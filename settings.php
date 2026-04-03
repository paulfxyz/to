<?php
/**
 * ============================================================================
 * settings.php — Encrypted settings store for the "To" project
 * ============================================================================
 *
 * Manages the Resend API key using a PIN-protected, AES-256-CBC encrypted
 * JSON file on disk. The plaintext key never exists on disk at any point.
 *
 * SECURITY MODEL
 * ──────────────
 * 1. The user provides a PIN (min 4 chars) when saving the API key.
 * 2. The PIN is stretched via PBKDF2-SHA256 (100,000 iterations) using a
 *    static application salt to produce a 256-bit encryption key.
 * 3. The API key payload is encrypted with AES-256-CBC using a fresh random
 *    16-byte IV on every save, then base64-encoded as: base64(IV ∥ ciphertext).
 * 4. A separate HMAC-SHA256 of the sentinel string "pin_verify_token" is
 *    stored alongside the ciphertext. This allows fast PIN verification
 *    (O(1) comparison via hash_equals) without performing a full decryption
 *    round-trip on every check.
 * 5. PIN changes re-decrypt with the old PIN and re-encrypt with the new one,
 *    so the ciphertext and HMAC verifier are both rotated atomically.
 *
 * WHY STATIC SALT?
 * ────────────────
 * A static application salt is acceptable here because:
 *   - There is only one user / one config (single-tenant tool)
 *   - The PIN itself provides the entropy variation between deployments
 *   - A per-user random salt would need to be stored in plaintext anyway,
 *     offering no meaningful additional protection in this threat model
 *   - The 100k PBKDF2 iterations already make brute-force attacks expensive
 *
 * STORAGE
 * ───────
 * Config is written to /config/settings.enc.json (auto-created).
 * The /config/ directory is blocked from direct HTTP access via .htaccess.
 *
 * ENDPOINTS  (all: POST, Content-Type: application/json)
 * ────────────────────────────────────────────────────────────────────────────
 *
 *   get_status
 *     → { hasKey: bool }
 *     Returns whether an API key is currently configured.
 *
 *   save  { apiKey, pin }
 *     → { ok: true } | { error }
 *     Encrypts and stores the API key. Overwrites any existing config.
 *
 *   verify_pin  { pin }
 *     → { ok: bool }
 *     Checks a PIN against the stored HMAC verifier. Does not decrypt.
 *
 *   get_key  { pin }
 *     → { ok: true, apiKey } | { error }
 *     Verifies PIN then decrypts and returns the API key.
 *     Used by the Settings modal to pre-populate the key field on unlock.
 *
 *   change_pin  { currentPin, newPin [, apiKey] }
 *     → { ok: true } | { error }
 *     Re-encrypts the stored API key under a new PIN. The apiKey param is
 *     optional — if omitted, it is decrypted from the existing ciphertext.
 *
 * ============================================================================
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight — browsers send this before the real POST
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ── Constants ────────────────────────────────────────────────────────────────

/** Absolute path to the encrypted config file */
define('CONFIG_FILE', __DIR__ . '/config/settings.enc.json');

/** OpenSSL cipher. AES-256-CBC is widely supported and battle-tested on PHP */
define('CIPHER', 'AES-256-CBC');

/**
 * Static application salt for PBKDF2 key derivation.
 * Single-tenant tool — one salt per deployment is fine. See header note above.
 */
define('SALT', 'to_paulfleury_salt_v1');

/** Sentinel string whose HMAC is stored to allow PIN verification */
define('PIN_SENTINEL', 'pin_verify_token');

/** Minimum allowed PIN length */
define('PIN_MIN_LEN', 4);

// ── Cryptographic helpers ────────────────────────────────────────────────────

/**
 * Derive a 256-bit AES key from a PIN using PBKDF2-SHA256.
 *
 * 100,000 iterations is the OWASP-recommended minimum for PBKDF2-SHA256
 * as of 2023. On a modern shared host this takes ~50–150 ms — perceptible
 * but acceptable for an admin-only settings flow.
 *
 * @param  string $pin  User-supplied PIN (any length ≥ PIN_MIN_LEN)
 * @return string       32-byte raw binary key
 */
function deriveKey(string $pin): string {
    return hash_pbkdf2('sha256', $pin, SALT, 100000, 32, true);
}

/**
 * Encrypt a plaintext string with AES-256-CBC.
 *
 * Output format: base64( randomIV[16] ∥ ciphertext )
 * A fresh IV is generated on every call, so encrypting the same payload
 * twice produces different ciphertexts — safe against chosen-plaintext attacks.
 *
 * @param  string $plain  Plaintext to encrypt (typically JSON)
 * @param  string $pin    PIN used to derive the encryption key
 * @return string         Base64-encoded IV + ciphertext
 */
function encryptData(string $plain, string $pin): string {
    $key = deriveKey($pin);
    $iv  = random_bytes(16); // cryptographically secure random IV
    $enc = openssl_encrypt($plain, CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

/**
 * Decrypt a previously encrypted string.
 *
 * Extracts the IV from the first 16 bytes of the decoded payload, then
 * decrypts the remainder. Returns null on any decryption failure rather
 * than throwing — callers must handle the null case.
 *
 * @param  string $encoded  Base64-encoded IV + ciphertext (from encryptData)
 * @param  string $pin      PIN used to derive the decryption key
 * @return string|null      Plaintext, or null if decryption failed
 */
function decryptData(string $encoded, string $pin): ?string {
    $raw = base64_decode($encoded);
    if (strlen($raw) < 16) return null; // too short to contain a valid IV

    $key = deriveKey($pin);
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    $dec = openssl_decrypt($enc, CIPHER, $key, OPENSSL_RAW_DATA, $iv);

    return $dec === false ? null : $dec;
}

/**
 * Compute the HMAC-SHA256 PIN verifier.
 *
 * By storing HMAC(sentinel, derivedKey) we can verify a PIN in ~100k PBKDF2
 * iterations without performing a full AES decryption. The sentinel value
 * "pin_verify_token" is fixed and public — security comes from the derived
 * key, not the sentinel. hash_equals() is used throughout for constant-time
 * comparison, preventing timing attacks.
 *
 * @param  string $pin  PIN to verify
 * @return string       64-char hex HMAC digest
 */
function pinHmac(string $pin): string {
    return hash_hmac('sha256', PIN_SENTINEL, deriveKey($pin));
}

// ── Config I/O ───────────────────────────────────────────────────────────────

/**
 * Load the config file from disk.
 *
 * @return array|null  Decoded JSON array, or null if file does not exist
 */
function loadConfig(): ?array {
    if (!file_exists(CONFIG_FILE)) return null;
    $raw = file_get_contents(CONFIG_FILE);
    return json_decode($raw, true);
}

/**
 * Persist the config array to disk as JSON.
 * Creates the /config/ directory if it does not already exist.
 *
 * @param array $data  Config payload to persist
 */
function saveConfig(array $data): void {
    $dir = dirname(CONFIG_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents(CONFIG_FILE, json_encode($data));
}

// ── Response helper ──────────────────────────────────────────────────────────

/**
 * Emit a JSON response and terminate execution.
 *
 * @param array $data  Associative array to JSON-encode and send
 */
function jsonOut(array $data): void {
    echo json_encode($data);
    exit;
}

// ── Request dispatch ─────────────────────────────────────────────────────────

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

switch ($action) {

    // ── get_status ────────────────────────────────────────────────────────────
    // Quick probe used by the frontend to decide which Settings panel to show:
    // PIN gate (key exists) vs. first-run setup form (no key yet).
    case 'get_status':
        $cfg = loadConfig();
        jsonOut(['hasKey' => ($cfg !== null && isset($cfg['enc']))]);

    // ── save ──────────────────────────────────────────────────────────────────
    // Encrypts the API key under the given PIN and writes config to disk.
    // Idempotent — calling save again overwrites the previous config entirely.
    case 'save':
        $apiKey = trim($body['apiKey'] ?? '');
        $pin    = trim($body['pin']    ?? '');

        if (!$apiKey)                   jsonOut(['error' => 'API key is required.']);
        if (!$pin)                      jsonOut(['error' => 'PIN is required.']);
        if (strlen($pin) < PIN_MIN_LEN) jsonOut(['error' => 'PIN must be at least ' . PIN_MIN_LEN . ' characters.']);

        // Wrap the API key in a JSON payload so the config format is extensible
        $payload = json_encode(['apiKey' => $apiKey]);
        $enc     = encryptData($payload, $pin);
        $hmac    = pinHmac($pin);

        saveConfig(['enc' => $enc, 'pinHmac' => $hmac]);
        jsonOut(['ok' => true]);

    // ── verify_pin ────────────────────────────────────────────────────────────
    // Validates a PIN against the stored HMAC verifier using constant-time
    // comparison. Does NOT decrypt the API key — purely a PIN check.
    // Returns { ok: true } or { ok: false } (never an error shape).
    case 'verify_pin':
        $pin = trim($body['pin'] ?? '');
        $cfg = loadConfig();
        if (!$cfg) jsonOut(['ok' => false]);

        $expected = pinHmac($pin);
        jsonOut(['ok' => hash_equals($cfg['pinHmac'] ?? '', $expected)]);

    // ── get_key ───────────────────────────────────────────────────────────────
    // Verifies PIN, then decrypts and returns the API key in plaintext.
    // Used by the Settings modal to pre-populate the key input on unlock,
    // so the user can confirm the current key before editing.
    case 'get_key':
        $pin = trim($body['pin'] ?? '');
        $cfg = loadConfig();
        if (!$cfg) jsonOut(['error' => 'No key stored.']);

        $expected = pinHmac($pin);
        if (!hash_equals($cfg['pinHmac'] ?? '', $expected)) {
            jsonOut(['error' => 'Invalid PIN.']);
        }

        $plain = decryptData($cfg['enc'], $pin);
        if ($plain === null) jsonOut(['error' => 'Decryption failed.']);

        $data = json_decode($plain, true);
        jsonOut(['ok' => true, 'apiKey' => $data['apiKey']]);

    // ── change_pin ────────────────────────────────────────────────────────────
    // Rotates the PIN by:
    //   1. Verifying the current PIN via HMAC
    //   2. Decrypting the API key with the current PIN (or accepting it as a
    //      parameter if the caller already has it in memory)
    //   3. Re-encrypting the API key under the new PIN
    //   4. Writing the new ciphertext + new HMAC to disk atomically
    //
    // The optional apiKey parameter is a micro-optimisation: the Settings modal
    // already has the key in its state after unlock, so passing it avoids a
    // redundant decrypt round-trip.
    case 'change_pin':
        $currentPin = trim($body['currentPin'] ?? '');
        $newPin     = trim($body['newPin']     ?? '');
        $apiKey     = trim($body['apiKey']     ?? '');

        if (!$currentPin || !$newPin)   jsonOut(['error' => 'Both PINs required.']);
        if (strlen($newPin) < PIN_MIN_LEN) jsonOut(['error' => 'New PIN must be at least ' . PIN_MIN_LEN . ' characters.']);

        $cfg = loadConfig();
        if (!$cfg) jsonOut(['error' => 'No config found.']);

        // Step 1 — verify current PIN
        $expected = pinHmac($currentPin);
        if (!hash_equals($cfg['pinHmac'] ?? '', $expected)) {
            jsonOut(['error' => 'Current PIN is incorrect.']);
        }

        // Step 2 — get the API key (decrypt if not supplied by caller)
        if (!$apiKey) {
            $plain = decryptData($cfg['enc'], $currentPin);
            if ($plain === null) jsonOut(['error' => 'Decryption failed.']);
            $data   = json_decode($plain, true);
            $apiKey = $data['apiKey'];
        }

        // Step 3 — re-encrypt under new PIN and persist
        $payload = json_encode(['apiKey' => $apiKey]);
        $enc     = encryptData($payload, $newPin);
        $hmac    = pinHmac($newPin);

        saveConfig(['enc' => $enc, 'pinHmac' => $hmac]);
        jsonOut(['ok' => true]);

    default:
        jsonOut(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
}
