<?php
/**
 * ============================================================================
 * send.php — Email delivery via Resend API for the "To" project
 * ============================================================================
 *
 * Accepts a JSON POST from the frontend, decrypts the stored Resend API key,
 * and dispatches a richly formatted email to hello@paulfleury.com.
 *
 * FLOW
 * ────
 * 1. Frontend uploads audio/files to upload.php → gets back public URLs
 * 2. Frontend POSTs to this file with the message body + those URLs + PIN
 * 3. This file verifies the PIN, decrypts the API key, builds HTML + text
 *    email templates, and calls the Resend REST API via cURL
 * 4. Returns { ok: true, id: "<resend-message-id>" } or { error: "..." }
 *
 * WHY RESEND (AND NOT SMTP / PHPMAILER)?
 * ──────────────────────────────────────
 * Most shared hosts block outbound SMTP port 25 or throttle it heavily.
 * Resend uses a simple REST API over HTTPS (port 443 — never blocked),
 * handles SPF/DKIM signing automatically on your verified domain, and
 * requires zero PHP dependencies. One cURL call, done.
 *
 * THE VERIFIED DOMAIN CONSTRAINT
 * ────────────────────────────────
 * Resend requires the FROM address to be on a domain you've verified via
 * DNS (DKIM + SPF records). In this deployment, up.paulfleury.com is the
 * verified domain, so FROM_EMAIL uses to@up.paulfleury.com rather than
 * to@paulfleury.com. This is a DNS management detail, not a code change —
 * simply update FROM_EMAIL if the verified domain changes.
 *
 * THE reply_to TRAP
 * ─────────────────
 * Resend's API rejects a null reply_to value with a validation error —
 * it must be either a valid email string or omitted from the payload
 * entirely. Since we build the payload as a PHP array, omitting a key is
 * the clean solution: we only include reply_to when the sender provided
 * a contact address, and fall back to TO_EMAIL otherwise.
 *
 * PIN HANDLING
 * ────────────
 * The frontend sends the PIN that was used to unlock the Settings modal.
 * This file passes it to getApiKey() which runs the same PBKDF2+HMAC
 * verification as settings.php before decrypting. The PIN never persists
 * in any server-side session — it lives only in the JS memory of the
 * current browser tab.
 *
 * If no valid PIN is provided, we try the default "1234". This handles the
 * first-run case where a user sets up the API key using the default PIN and
 * then sends a message without explicitly unlocking Settings in that session.
 *
 * EMAIL TEMPLATE DESIGN
 * ──────────────────────
 * HTML email is notoriously tricky — many clients (Outlook, Apple Mail, Gmail)
 * strip external CSS and ignore modern layout techniques. The template uses:
 *   - Table-based layout (100% inbox compatible)
 *   - Inline styles only
 *   - Web-safe font stack: Georgia, 'Times New Roman', serif
 *   - No external images or fonts (would be blocked by most clients)
 *   - A full plain-text fallback for clients that don't render HTML
 *
 * REQUEST FORMAT  (application/json)
 * ────────────────────────────────────
 * {
 *   name:       string          — sender's name (defaults to "Anonymous")
 *   contact:    string          — reply-to address/handle (optional)
 *   message:    string          — message body
 *   activeMs:   number          — writing session duration in milliseconds
 *   words:      number          — word count
 *   chars:      number          — character count
 *   audioUrl:   string|null     — public URL of voice message in /cache/
 *   audioName:  string|null     — filename of voice message
 *   files:      array           — [{ name, url, size, mime }] from upload.php
 *   pin:        string          — PIN to decrypt the API key
 * }
 *
 * RESPONSE FORMAT
 * ───────────────
 *   Success: { ok: true, id: "<resend-message-id>" }
 *   Failure: { error: "human-readable description" }
 *
 * ============================================================================
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ── Constants ────────────────────────────────────────────────────────────────

/** Absolute path to the encrypted config file (shared with settings.php) */
define('CONFIG_FILE', __DIR__ . '/config/settings.enc.json');

/** OpenSSL cipher — must match the value used in settings.php */
define('CIPHER', 'AES-256-CBC');

/** PBKDF2 salt — must match the value used in settings.php */
define('SALT', 'to_paulfleury_salt_v1');

/** Destination inbox — where all messages are delivered */
define('TO_EMAIL', 'hello@paulfleury.com');

/**
 * Sender address — MUST be on a Resend-verified domain.
 *
 * up.paulfleury.com is the verified domain in this deployment.
 * to@paulfleury.com would be cleaner, but the DNS records for
 * paulfleury.com itself aren't set up with Resend at this time.
 * Update this constant if/when paulfleury.com is verified.
 */
define('FROM_EMAIL', 'to@up.paulfleury.com');

/** Display name shown in the inbox "From" field */
define('FROM_NAME', 'Message to Paul');

/** Resend API endpoint */
define('RESEND_API_URL', 'https://api.resend.com/emails');

/** cURL timeout in seconds for the Resend API call */
define('CURL_TIMEOUT', 15);

// ── Crypto helpers (mirrors settings.php) ────────────────────────────────────

/**
 * Derive a 256-bit AES key from a PIN using PBKDF2-SHA256.
 * Must match the implementation in settings.php exactly.
 *
 * @param  string $pin  User PIN
 * @return string       32-byte raw binary key
 */
function deriveKey(string $pin): string {
    return hash_pbkdf2('sha256', $pin, SALT, 100000, 32, true);
}

/**
 * Decrypt AES-256-CBC ciphertext encoded as base64(IV ∥ ciphertext).
 *
 * @param  string $encoded  Output of encryptData() from settings.php
 * @param  string $pin      PIN used to derive the decryption key
 * @return string|null      Plaintext JSON, or null on failure
 */
function decryptData(string $encoded, string $pin): ?string {
    $raw = base64_decode($encoded);
    if (strlen($raw) < 16) return null;

    $key = deriveKey($pin);
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    $dec = openssl_decrypt($enc, CIPHER, $key, OPENSSL_RAW_DATA, $iv);

    return $dec === false ? null : $dec;
}

/**
 * Load, verify, and decrypt the Resend API key from the config file.
 *
 * Returns null rather than throwing if anything goes wrong — the caller
 * handles the null case with a user-friendly error message.
 *
 * @param  string $pin  PIN to verify and use for decryption
 * @return string|null  Plaintext API key, or null on any failure
 */
function getApiKey(string $pin): ?string {
    if (!file_exists(CONFIG_FILE)) return null;

    $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
    if (!$cfg) return null;

    // Verify PIN via constant-time HMAC comparison (prevents timing attacks)
    $expected = hash_hmac('sha256', 'pin_verify_token', deriveKey($pin));
    if (!hash_equals($cfg['pinHmac'] ?? '', $expected)) return null;

    $plain = decryptData($cfg['enc'], $pin);
    if ($plain === null) return null;

    $data = json_decode($plain, true);
    return $data['apiKey'] ?? null;
}

// ── Formatting helpers ────────────────────────────────────────────────────────

/**
 * Format a millisecond duration as MM:SS.mmm.
 *
 * @param  int $ms  Duration in milliseconds
 * @return string   e.g. "02:34.567"
 */
function fmtTime(int $ms): string {
    $ms = max(0, $ms);
    $m  = intdiv($ms, 60000);
    $s  = intdiv($ms % 60000, 1000);
    $x  = $ms % 1000;
    return sprintf('%02d:%02d.%03d', $m, $s, $x);
}

/**
 * Format a byte count as a human-readable size string.
 *
 * @param  int $b  Size in bytes
 * @return string  e.g. "1.4 MB"
 */
function fmtSize(int $b): string {
    if ($b < 1024)    return $b . ' B';
    if ($b < 1048576) return round($b / 1024, 1) . ' KB';
    return round($b / 1048576, 1) . ' MB';
}

// ── Email template builders ───────────────────────────────────────────────────

/**
 * Build the HTML email body.
 *
 * Uses a table-based layout for maximum email client compatibility.
 * No external stylesheets, no web fonts, no JavaScript — everything
 * is inline CSS on standard HTML elements.
 *
 * Structure:
 *   ┌─────────────────────────────────┐
 *   │ Black header — name + eyebrow   │
 *   ├─────────────────────────────────┤
 *   │ Grey stats bar — time/words/chars│
 *   ├─────────────────────────────────┤
 *   │ Reply-to row (if provided)       │
 *   │ Message body                     │
 *   │ Voice message card (if any)      │
 *   │ Attached files table (if any)    │
 *   ├─────────────────────────────────┤
 *   │ Footer — sent via to.paulfleury  │
 *   └─────────────────────────────────┘
 *
 * @param  array $d  Decoded request payload
 * @return string    Full HTML document string
 */
function buildHtml(array $d): string {
    // Sanitise all user-supplied strings before interpolating into HTML
    $name      = htmlspecialchars($d['name']    ?? 'Anonymous');
    $contact   = htmlspecialchars($d['contact'] ?? '');
    $message   = nl2br(htmlspecialchars($d['message'] ?? '')); // preserve line breaks
    $time      = fmtTime((int)($d['activeMs'] ?? 0));
    $words     = (int)($d['words'] ?? 0);
    $chars     = (int)($d['chars'] ?? 0);
    $files     = $d['files']    ?? [];
    $audioUrl  = $d['audioUrl'] ?? null;
    $audioName = htmlspecialchars($d['audioName'] ?? 'voice_message.webm');

    // ── Build attachment section ─────────────────────────────────────────────
    $attachHtml = '';

    // Voice message card — shows only when a recording was attached
    if ($audioUrl) {
        $safeUrl    = htmlspecialchars($audioUrl);
        $attachHtml .= <<<HTML
        <tr>
          <td style="padding:16px 0 0;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="background:#f5f5f7;border-radius:8px;padding:14px 18px;">
              <tr>
                <td style="font-size:13px;color:#555;padding-bottom:8px;font-weight:600;
                           letter-spacing:.05em;text-transform:uppercase;">
                  🎙️ Voice Message
                </td>
              </tr>
              <tr>
                <td>
                  <a href="{$safeUrl}"
                     style="display:inline-block;background:#000;color:#fff;text-decoration:none;
                            padding:8px 18px;border-radius:6px;font-size:13px;font-weight:500;">
                    ▶ Listen / Download
                  </a>
                  <span style="font-size:12px;color:#999;margin-left:10px;">{$audioName}</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        HTML;
    }

    // Attached files table — shows only when files were uploaded
    if (!empty($files)) {
        $rows = '';
        foreach ($files as $f) {
            $fn    = htmlspecialchars($f['name'] ?? 'file');
            $fu    = htmlspecialchars($f['url']  ?? '#');
            $fs    = fmtSize((int)($f['size'] ?? 0));
            $fm    = htmlspecialchars($f['mime'] ?? '');
            $rows .= <<<HTML
            <tr>
              <td style="padding:6px 0;border-bottom:1px solid #eee;">
                <a href="{$fu}" style="color:#000;text-decoration:none;font-size:13px;font-weight:500;">{$fn}</a>
                <span style="color:#999;font-size:12px;margin-left:8px;">{$fs}</span>
                <span style="color:#bbb;font-size:11px;margin-left:6px;">{$fm}</span>
              </td>
            </tr>
            HTML;
        }

        $attachHtml .= <<<HTML
        <tr>
          <td style="padding:16px 0 0;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="background:#f5f5f7;border-radius:8px;padding:14px 18px;">
              <tr>
                <td style="font-size:13px;color:#555;padding-bottom:8px;font-weight:600;
                           letter-spacing:.05em;text-transform:uppercase;">
                  📎 Attached Files
                </td>
              </tr>
              {$rows}
            </table>
          </td>
        </tr>
        HTML;
    }

    // Optional reply-to row — only rendered when the sender left a contact
    $contactRow = $contact
        ? "<tr><td style='font-size:13px;color:#888;padding-bottom:4px;'>
             <strong style='color:#555;'>Reply to:</strong> {$contact}
           </td></tr>"
        : '';

    return <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <title>Message from {$name}</title>
    </head>
    <body style="margin:0;padding:0;background:#f9f9f7;font-family:Georgia,'Times New Roman',serif;">
      <table width="100%" cellpadding="0" cellspacing="0" border="0"
             style="background:#f9f9f7;padding:40px 0;">
        <tr><td align="center">
          <table width="600" cellpadding="0" cellspacing="0" border="0"
                 style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden;
                        box-shadow:0 2px 20px rgba(0,0,0,.08);">

            <!-- ── Header ─────────────────────────────────────────── -->
            <tr>
              <td style="background:#000;padding:28px 40px;">
                <p style="margin:0;font-size:11px;letter-spacing:.12em;text-transform:uppercase;
                          color:rgba(255,255,255,.5);">
                  Message to Paul Fleury
                </p>
                <h1 style="margin:8px 0 0;font-size:26px;font-weight:700;color:#fff;
                           letter-spacing:-.02em;">
                  From {$name}
                </h1>
              </td>
            </tr>

            <!-- ── Writing stats bar ──────────────────────────────── -->
            <tr>
              <td style="background:#f5f5f7;padding:14px 40px;">
                <table cellpadding="0" cellspacing="0" border="0">
                  <tr>
                    <td style="font-size:12px;color:#888;padding-right:20px;">
                      ⏱ <strong style="color:#444;">{$time}</strong> active
                    </td>
                    <td style="font-size:12px;color:#888;padding-right:20px;">
                      📝 <strong style="color:#444;">{$words}</strong> words
                    </td>
                    <td style="font-size:12px;color:#888;">
                      🔤 <strong style="color:#444;">{$chars}</strong> chars
                    </td>
                  </tr>
                </table>
              </td>
            </tr>

            <!-- ── Message body ────────────────────────────────────── -->
            <tr>
              <td style="padding:32px 40px 24px;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">

                  {$contactRow}

                  <tr>
                    <td style="height:1px;background:#eee;display:block;"></td>
                  </tr>

                  <tr>
                    <td style="font-size:16px;line-height:1.8;color:#333;padding:20px 0;">
                      {$message}
                    </td>
                  </tr>

                  {$attachHtml}

                </table>
              </td>
            </tr>

            <!-- ── Footer ─────────────────────────────────────────── -->
            <tr>
              <td style="padding:20px 40px 32px;border-top:1px solid #eee;">
                <p style="margin:0;font-size:11px;color:#bbb;line-height:1.6;">
                  Sent via
                  <a href="https://to.paulfleury.com" style="color:#999;text-decoration:none;">
                    to.paulfleury.com
                  </a>
                </p>
              </td>
            </tr>

          </table>
        </td></tr>
      </table>
    </body>
    </html>
    HTML;
}

/**
 * Build the plain-text email fallback.
 *
 * Used by email clients that don't render HTML and by spam filters that
 * check for a matching plain-text part. Mirrors the structure of buildHtml()
 * but uses Unicode box-drawing characters for visual separation.
 *
 * @param  array $d  Decoded request payload
 * @return string    Plain-text email body
 */
function buildText(array $d): string {
    $name     = $d['name']    ?? 'Anonymous';
    $contact  = $d['contact'] ?? '';
    $message  = $d['message'] ?? '';
    $time     = fmtTime((int)($d['activeMs'] ?? 0));
    $words    = (int)($d['words'] ?? 0);
    $chars    = (int)($d['chars'] ?? 0);
    $files    = $d['files']   ?? [];
    $audioUrl = $d['audioUrl'] ?? null;

    $divider = str_repeat('─', 40);

    $text  = "Message from {$name}\n{$divider}\n\n";
    if ($contact) $text .= "Reply to: {$contact}\n\n";
    $text .= "Active time: {$time} | {$words} words | {$chars} chars\n\n";
    $text .= "{$divider}\n\n";
    $text .= $message . "\n\n";

    if ($audioUrl) {
        $text .= "{$divider}\n";
        $text .= "🎙️ Voice message: {$audioUrl}\n\n";
    }

    if (!empty($files)) {
        $text .= "{$divider}\n";
        $text .= "📎 Attached files:\n";
        foreach ($files as $f) {
            $text .= '  • ' . ($f['name'] ?? 'file') . ' — ' . ($f['url'] ?? '') . "\n";
        }
    }

    return $text;
}

// ── Main ──────────────────────────────────────────────────────────────────────

/** @var array $body  Decoded JSON request payload */
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$pin  = trim($body['pin'] ?? '');

// ── Decrypt API key ───────────────────────────────────────────────────────────
// Try the supplied PIN first; fall back to the default "1234" to support
// the case where a user configured the key with the default PIN and hasn't
// changed it, and is now sending without explicitly unlocking Settings.
$apiKey = getApiKey($pin) ?? getApiKey('1234');
if (!$apiKey) {
    jsonOut(['error' => 'No Resend API key configured. Click the ⚙ icon in the top bar to add one.']);
}

// ── Validate payload ──────────────────────────────────────────────────────────
$name    = trim($body['name']    ?? '') ?: 'Anonymous';
$contact = trim($body['contact'] ?? '');
$message = trim($body['message'] ?? '');

if (!$message && empty($body['audioUrl']) && empty($body['files'])) {
    jsonOut(['error' => 'Nothing to send — message, voice note, and files are all empty.']);
}

// ── Build email ───────────────────────────────────────────────────────────────
$subject  = 'Message from ' . $name . ' — to.paulfleury.com';
$htmlBody = buildHtml($body);
$textBody = buildText($body);

// Build Resend payload — omit reply_to entirely when no contact provided,
// because Resend rejects null as an invalid email address (learned the hard way).
$resendPayload = [
    'from'    => FROM_NAME . ' <' . FROM_EMAIL . '>',
    'to'      => [TO_EMAIL],
    'subject' => $subject,
    'html'    => $htmlBody,
    'text'    => $textBody,
];

// Only add reply_to when the sender left a contact address
if ($contact) {
    $resendPayload['reply_to'] = $contact;
}

// ── Call Resend API ───────────────────────────────────────────────────────────
$ch = curl_init(RESEND_API_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($resendPayload),
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT        => CURL_TIMEOUT,
]);

$resp   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($resp, true);

// Resend returns 200 or 201 on success
if ($status === 200 || $status === 201) {
    jsonOut(['ok' => true, 'id' => $result['id'] ?? null]);
} else {
    // Surface Resend's own error message to make debugging easier
    $msg = $result['message'] ?? $result['error'] ?? ('Resend API returned HTTP ' . $status);
    jsonOut(['error' => $msg]);
}
