<?php
/**
 * send.php — Send email via Resend API for the "To" project.
 *
 * POST /send.php  (application/json)
 * {
 *   name:        string,
 *   contact:     string,
 *   message:     string,
 *   activeMs:    number,
 *   words:       number,
 *   chars:       number,
 *   audioUrl:    string|null,
 *   audioName:   string|null,
 *   files:       [{ name, url, size, mime }],
 *   pin:         string   ← used server-side to decrypt the API key
 * }
 *
 * Returns:
 *   { ok: true, id: "..." }
 *   { error: "..." }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

define('CONFIG_FILE', __DIR__ . '/config/settings.enc.json');
define('CIPHER',      'AES-256-CBC');
define('SALT',        'to_paulfleury_salt_v1');
define('TO_EMAIL',    'hello@paulfleury.com');
define('FROM_EMAIL',  'to@up.paulfleury.com');
define('FROM_NAME',   'Message to Paul');

function jsonOut(array $data): void {
    echo json_encode($data);
    exit;
}

function deriveKey(string $pin): string {
    return hash_pbkdf2('sha256', $pin, SALT, 100000, 32, true);
}

function decryptData(string $encoded, string $pin): ?string {
    $raw = base64_decode($encoded);
    if (strlen($raw) < 16) return null;
    $key  = deriveKey($pin);
    $iv   = substr($raw, 0, 16);
    $enc  = substr($raw, 16);
    $dec  = openssl_decrypt($enc, CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    return $dec === false ? null : $dec;
}

function getApiKey(string $pin): ?string {
    if (!file_exists(CONFIG_FILE)) return null;
    $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
    if (!$cfg) return null;

    // Verify PIN
    $expected = hash_hmac('sha256', 'pin_verify_token', deriveKey($pin));
    if (!hash_equals($cfg['pinHmac'] ?? '', $expected)) return null;

    $plain = decryptData($cfg['enc'], $pin);
    if ($plain === null) return null;

    $data = json_decode($plain, true);
    return $data['apiKey'] ?? null;
}

function fmtTime(int $ms): string {
    $ms  = max(0, $ms);
    $m   = intdiv($ms, 60000);
    $s   = intdiv($ms % 60000, 1000);
    $x   = $ms % 1000;
    return sprintf('%02d:%02d.%03d', $m, $s, $x);
}

function fmtSize(int $b): string {
    if ($b < 1024)       return $b . ' B';
    if ($b < 1048576)    return round($b / 1024, 1) . ' KB';
    return round($b / 1048576, 1) . ' MB';
}

function buildHtml(array $d): string {
    $name    = htmlspecialchars($d['name']    ?? 'Anonymous');
    $contact = htmlspecialchars($d['contact'] ?? '');
    $message = nl2br(htmlspecialchars($d['message'] ?? ''));
    $time    = fmtTime((int)($d['activeMs'] ?? 0));
    $words   = (int)($d['words'] ?? 0);
    $chars   = (int)($d['chars'] ?? 0);
    $files   = $d['files']     ?? [];
    $audioUrl  = $d['audioUrl']  ?? null;
    $audioName = $d['audioName'] ?? 'voice_message.webm';

    $attachHtml = '';

    if ($audioUrl) {
        $attachHtml .= <<<HTML
        <tr>
          <td style="padding:16px 0 0;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f5f7;border-radius:8px;padding:14px 18px;">
              <tr>
                <td style="font-size:13px;color:#555;padding-bottom:8px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;">🎙️ Voice Message</td>
              </tr>
              <tr>
                <td>
                  <a href="{$audioUrl}" style="display:inline-block;background:#000;color:#fff;text-decoration:none;padding:8px 18px;border-radius:6px;font-size:13px;font-weight:500;">
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

    if (!empty($files)) {
        $rows = '';
        foreach ($files as $f) {
            $fn   = htmlspecialchars($f['name'] ?? 'file');
            $fu   = htmlspecialchars($f['url']  ?? '#');
            $fs   = fmtSize((int)($f['size'] ?? 0));
            $fm   = htmlspecialchars($f['mime'] ?? '');
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
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f5f7;border-radius:8px;padding:14px 18px;">
              <tr>
                <td style="font-size:13px;color:#555;padding-bottom:8px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;">📎 Attached Files</td>
              </tr>
              {$rows}
            </table>
          </td>
        </tr>
        HTML;
    }

    $contactRow = $contact
        ? "<tr><td style='font-size:13px;color:#888;padding-bottom:4px;'><strong style='color:#555;'>Reply to:</strong> {$contact}</td></tr>"
        : '';

    return <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head><meta charset="utf-8"><title>Message from {$name}</title></head>
    <body style="margin:0;padding:0;background:#f9f9f7;font-family:Georgia,'Times New Roman',serif;">
      <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f9f9f7;padding:40px 0;">
        <tr><td align="center">
          <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 20px rgba(0,0,0,.08);">

            <!-- Header -->
            <tr>
              <td style="background:#000;padding:28px 40px;">
                <p style="margin:0;font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.5);">Message to Paul Fleury</p>
                <h1 style="margin:8px 0 0;font-size:26px;font-weight:700;color:#fff;letter-spacing:-.02em;">From {$name}</h1>
              </td>
            </tr>

            <!-- Stats bar -->
            <tr>
              <td style="background:#f5f5f7;padding:14px 40px;">
                <table cellpadding="0" cellspacing="0" border="0">
                  <tr>
                    <td style="font-size:12px;color:#888;padding-right:20px;">⏱ <strong style="color:#444;">{$time}</strong> active</td>
                    <td style="font-size:12px;color:#888;padding-right:20px;">📝 <strong style="color:#444;">{$words}</strong> words</td>
                    <td style="font-size:12px;color:#888;">🔤 <strong style="color:#444;">{$chars}</strong> chars</td>
                  </tr>
                </table>
              </td>
            </tr>

            <!-- Body -->
            <tr>
              <td style="padding:32px 40px 24px;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0">

                  {$contactRow}

                  <!-- Divider -->
                  <tr><td style="height:1px;background:#eee;margin:16px 0;display:block;"></td></tr>

                  <!-- Message -->
                  <tr>
                    <td style="font-size:16px;line-height:1.8;color:#333;padding:20px 0;">{$message}</td>
                  </tr>

                  {$attachHtml}

                </table>
              </td>
            </tr>

            <!-- Footer -->
            <tr>
              <td style="padding:20px 40px 32px;border-top:1px solid #eee;">
                <p style="margin:0;font-size:11px;color:#bbb;line-height:1.6;">
                  Sent via <a href="https://to.paulfleury.com" style="color:#999;text-decoration:none;">to.paulfleury.com</a>
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

function buildText(array $d): string {
    $name    = $d['name']    ?? 'Anonymous';
    $contact = $d['contact'] ?? '';
    $message = $d['message'] ?? '';
    $time    = fmtTime((int)($d['activeMs'] ?? 0));
    $words   = (int)($d['words'] ?? 0);
    $chars   = (int)($d['chars'] ?? 0);
    $files   = $d['files']    ?? [];
    $audioUrl = $d['audioUrl'] ?? null;

    $text = "Message from {$name}\n";
    $text .= str_repeat('─', 40) . "\n\n";
    if ($contact) $text .= "Reply to: {$contact}\n\n";
    $text .= "Active time: {$time} | {$words} words | {$chars} chars\n\n";
    $text .= str_repeat('─', 40) . "\n\n";
    $text .= $message . "\n\n";

    if ($audioUrl) {
        $text .= str_repeat('─', 40) . "\n";
        $text .= "🎙️ Voice message: {$audioUrl}\n\n";
    }

    if (!empty($files)) {
        $text .= str_repeat('─', 40) . "\n";
        $text .= "📎 Attached files:\n";
        foreach ($files as $f) {
            $text .= '  • ' . ($f['name'] ?? 'file') . ' — ' . ($f['url'] ?? '') . "\n";
        }
    }

    return $text;
}

/* ── main ──────────────────────────────────────────────────────────────────── */

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$pin  = trim($body['pin'] ?? '');

// Load API key from encrypted config
$apiKey = getApiKey($pin);
if (!$apiKey) {
    // Try with default PIN if none provided (first run before settings saved)
    $apiKey = getApiKey('1234');
}
if (!$apiKey) {
    jsonOut(['error' => 'No Resend API key configured. Please open Settings.']);
}

$name    = trim($body['name']    ?? '') ?: 'Anonymous';
$contact = trim($body['contact'] ?? '');
$message = trim($body['message'] ?? '');

if (!$message && empty($body['audioUrl']) && empty($body['files'])) {
    jsonOut(['error' => 'Nothing to send.']);
}

$subject = 'Message from ' . $name . ' — to.paulfleury.com';

$htmlBody = buildHtml($body);
$textBody = buildText($body);

// Call Resend API
$payload = json_encode([
    'from'    => FROM_NAME . ' <' . FROM_EMAIL . '>',
    'to'      => [TO_EMAIL],
    'subject' => $subject,
    'html'    => $htmlBody,
    'text'    => $textBody,
    'reply_to'=> $contact ?: TO_EMAIL,
]);

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT        => 15,
]);

$resp   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($resp, true);

if ($status === 200 || $status === 201) {
    jsonOut(['ok' => true, 'id' => $result['id'] ?? null]);
} else {
    $msg = $result['message'] ?? $result['error'] ?? ('Resend error ' . $status);
    jsonOut(['error' => $msg]);
}
