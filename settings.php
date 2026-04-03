<?php
/**
 * settings.php — Read / write encrypted settings for the "To" project.
 *
 * Endpoints:
 *   POST /settings.php  { action: "get_status" }
 *     → { hasKey: bool }
 *
 *   POST /settings.php  { action: "save", apiKey: "re_...", pin: "1234" }
 *     → { ok: true } | { error: "..." }
 *
 *   POST /settings.php  { action: "verify_pin", pin: "1234" }
 *     → { ok: true } | { ok: false }
 *
 *   POST /settings.php  { action: "change_pin", currentPin: "1234", newPin: "5678", apiKey: "re_..." }
 *     → { ok: true } | { error: "..." }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

define('CONFIG_FILE', __DIR__ . '/config/settings.enc.json');
define('CIPHER',      'AES-256-CBC');
define('SALT',        'to_paulfleury_salt_v1'); // static salt baked in

/* ── helpers ─────────────────────────────────────────────────────────────── */

function deriveKey(string $pin): string {
    return hash_pbkdf2('sha256', $pin, SALT, 100000, 32, true);
}

function encryptData(string $plain, string $pin): string {
    $key = deriveKey($pin);
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
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

function loadConfig(): ?array {
    if (!file_exists(CONFIG_FILE)) return null;
    $raw = file_get_contents(CONFIG_FILE);
    return json_decode($raw, true);
}

function saveConfig(array $data): void {
    $dir = dirname(CONFIG_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents(CONFIG_FILE, json_encode($data));
}

function jsonOut(array $data): void {
    echo json_encode($data);
    exit;
}

/* ── request handling ────────────────────────────────────────────────────── */

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

switch ($action) {

    case 'get_status':
        $cfg = loadConfig();
        jsonOut(['hasKey' => ($cfg !== null && isset($cfg['enc']))]);

    case 'save':
        $apiKey = trim($body['apiKey'] ?? '');
        $pin    = trim($body['pin']    ?? '');
        if (!$apiKey) jsonOut(['error' => 'API key is required.']);
        if (!$pin)    jsonOut(['error' => 'PIN is required.']);
        if (strlen($pin) < 4) jsonOut(['error' => 'PIN must be at least 4 characters.']);

        $payload = json_encode(['apiKey' => $apiKey]);
        $enc     = encryptData($payload, $pin);

        // Store a PIN verifier (hmac of known string) so we can verify without decrypting
        $pinHmac = hash_hmac('sha256', 'pin_verify_token', deriveKey($pin));

        saveConfig(['enc' => $enc, 'pinHmac' => $pinHmac]);
        jsonOut(['ok' => true]);

    case 'verify_pin':
        $pin = trim($body['pin'] ?? '');
        $cfg = loadConfig();
        if (!$cfg) jsonOut(['ok' => false]);

        $expected = hash_hmac('sha256', 'pin_verify_token', deriveKey($pin));
        jsonOut(['ok' => hash_equals($cfg['pinHmac'] ?? '', $expected)]);

    case 'get_key':
        // Returns decrypted API key if PIN is correct
        $pin = trim($body['pin'] ?? '');
        $cfg = loadConfig();
        if (!$cfg) jsonOut(['error' => 'No key stored.']);

        $expected = hash_hmac('sha256', 'pin_verify_token', deriveKey($pin));
        if (!hash_equals($cfg['pinHmac'] ?? '', $expected)) {
            jsonOut(['error' => 'Invalid PIN.']);
        }

        $plain = decryptData($cfg['enc'], $pin);
        if ($plain === null) jsonOut(['error' => 'Decryption failed.']);

        $data = json_decode($plain, true);
        jsonOut(['ok' => true, 'apiKey' => $data['apiKey']]);

    case 'change_pin':
        $currentPin = trim($body['currentPin'] ?? '');
        $newPin     = trim($body['newPin']     ?? '');
        $apiKey     = trim($body['apiKey']     ?? '');
        if (!$currentPin || !$newPin) jsonOut(['error' => 'Both PINs required.']);
        if (strlen($newPin) < 4) jsonOut(['error' => 'New PIN must be at least 4 characters.']);

        $cfg = loadConfig();
        if (!$cfg) jsonOut(['error' => 'No config found.']);

        // Verify current PIN
        $expected = hash_hmac('sha256', 'pin_verify_token', deriveKey($currentPin));
        if (!hash_equals($cfg['pinHmac'] ?? '', $expected)) {
            jsonOut(['error' => 'Current PIN is incorrect.']);
        }

        // If no apiKey passed, decrypt it from existing config
        if (!$apiKey) {
            $plain = decryptData($cfg['enc'], $currentPin);
            if ($plain === null) jsonOut(['error' => 'Decryption failed.']);
            $data   = json_decode($plain, true);
            $apiKey = $data['apiKey'];
        }

        // Re-encrypt with new PIN
        $payload = json_encode(['apiKey' => $apiKey]);
        $enc     = encryptData($payload, $newPin);
        $pinHmac = hash_hmac('sha256', 'pin_verify_token', deriveKey($newPin));

        saveConfig(['enc' => $enc, 'pinHmac' => $pinHmac]);
        jsonOut(['ok' => true]);

    default:
        jsonOut(['error' => 'Unknown action.']);
}
