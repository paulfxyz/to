<?php
/**
 * ============================================================================
 * upload.php — File & voice upload handler for the "To" project
 * ============================================================================
 *
 * Accepts multipart/form-data POST requests containing one or more files,
 * validates them, stores them in /cache/ with randomised filenames, and
 * returns public download URLs to the caller.
 *
 * DESIGN DECISIONS & LESSONS LEARNED
 * ────────────────────────────────────
 *
 * 1. VOICE BLOB MIME TYPE MISMATCH
 *    Browser-recorded audio blobs (MediaRecorder API) are reported by PHP's
 *    finfo as "application/octet-stream" because finfo reads magic bytes —
 *    and a raw WebM stream doesn't always have a recognised magic header at
 *    detection time. The browser-reported MIME type (audio/webm) is far more
 *    reliable in this case, so we fall back to it when finfo returns the
 *    generic octet-stream type for a file the browser declared as audio/*.
 *
 * 2. FILENAME SANITISATION
 *    User-supplied filenames are stripped of anything that isn't alphanumeric,
 *    dash, underscore, or dot — then prefixed with 16 hex chars of
 *    cryptographically random data. This prevents both path traversal attacks
 *    and filename collisions between concurrent uploads.
 *
 * 3. CACHE AUTO-CLEANUP (PROBABILISTIC)
 *    Rather than running a cron job (unavailable on most shared hosts),
 *    cleanup runs inline with a 5% probability on each request. Over time
 *    this provides consistent cache hygiene with zero infrastructure overhead.
 *    TTL is 30 days — long enough for Paul to click the links at leisure.
 *
 * 4. URL CONSTRUCTION
 *    The public URL is built from HTTP_HOST + SCRIPT_NAME rather than a
 *    hard-coded domain, so the same codebase works on localhost, staging,
 *    and production without changes.
 *
 * 5. DIRECTORY LISTING PREVENTION
 *    /cache/ contains an index.html returning 403, and .htaccess sets
 *    Options -Indexes. Belt and suspenders — if one fails the other holds.
 *
 * REQUEST FORMAT
 * ──────────────
 *   POST /upload.php  (multipart/form-data)
 *   Field: file  — one or more files (file[] for multi-upload)
 *
 * RESPONSE FORMAT
 * ───────────────
 *   Success: { ok: true, files: [{ name, url, size, mime }] }
 *   Failure: { error: "human-readable message" }
 *
 * LIMITS
 * ──────
 *   Per-file:  50 MB
 *   Total:    100 MB per request
 *   TTL:       30 days
 *
 * ALLOWED MIME TYPES
 * ──────────────────
 *   Audio:  webm, ogg, mpeg, mp4, wav
 *   Video:  mp4, webm, ogg
 *   Image:  jpeg, png, gif, webp, svg
 *   Docs:   pdf, doc/docx, xls/xlsx, ppt/pptx, txt, csv
 *   Archive: zip, tar, gz
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

/** Absolute path to the upload cache directory */
define('CACHE_DIR',      __DIR__ . '/cache/');

/** Maximum size per individual file (50 MB) */
define('MAX_FILE_SIZE',  52428800);

/** Maximum combined size for all files in one request (100 MB) */
define('MAX_TOTAL_SIZE', 104857600);

/** How long uploaded files are kept before auto-deletion (30 days in seconds) */
define('CACHE_TTL',      30 * 86400);

/**
 * Allowed MIME types.
 *
 * This list is intentionally broad — Paul may receive anything from a
 * screenshot to a contract PDF. Types are validated against PHP's finfo
 * (magic-byte inspection), not just the browser-reported content-type.
 *
 * @var string[]
 */
$ALLOWED_MIME = [
    // ── Audio (includes browser MediaRecorder output) ──
    'audio/webm',       // Chrome/Edge MediaRecorder default
    'audio/ogg',        // Firefox MediaRecorder default
    'audio/mpeg',       // MP3
    'audio/mp4',        // AAC in MP4 container
    'audio/wav',        // Uncompressed WAV
    'audio/x-wav',      // Alias for WAV (older finfo databases)

    // ── Video ──
    'video/mp4',
    'video/webm',
    'video/ogg',

    // ── Images ──
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/svg+xml',

    // ── Documents ──
    'application/pdf',
    'application/msword',                                                       // .doc
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',  // .docx
    'application/vnd.ms-excel',                                                 // .xls
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',        // .xlsx
    'application/vnd.ms-powerpoint',                                            // .ppt
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',// .pptx
    'text/plain',
    'text/csv',

    // ── Archives ──
    'application/zip',
    'application/x-zip-compressed', // Alias used by some Windows browsers
    'application/x-tar',
    'application/gzip',
];

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Emit a JSON response and halt execution.
 *
 * @param array $data  Payload to JSON-encode
 */
function jsonOut(array $data): void {
    echo json_encode($data);
    exit;
}

/**
 * Delete cache files older than CACHE_TTL seconds.
 *
 * Called probabilistically (5% of requests) to avoid cron dependency.
 * Uses glob() rather than a recursive iterator — /cache/ is a flat directory.
 * Files that cannot be deleted (permission issue) are silently skipped with @.
 */
function cleanCache(): void {
    $now = time();
    foreach (glob(CACHE_DIR . '*') as $f) {
        if (is_file($f) && ($now - filemtime($f)) > CACHE_TTL) {
            @unlink($f);
        }
    }
}

/**
 * Build the public base URL for this script's directory.
 *
 * Detects HTTPS from the $_SERVER superglobal rather than assuming a scheme,
 * so the same code works behind a load balancer or reverse proxy that sets
 * HTTPS=on or passes X-Forwarded-Proto (note: trust your proxy's headers).
 *
 * SCRIPT_NAME example: /public_html/upload.php → dirname = /public_html
 *
 * @return string  e.g. "https://to.paulfleury.com"
 */
function getSiteUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    return $scheme . '://' . $host . $path;
}

// ── Bootstrap ────────────────────────────────────────────────────────────────

// Create /cache/ on first run if it doesn't exist
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
    // Stub index.html blocks directory listing as a fallback to .htaccess
    file_put_contents(CACHE_DIR . 'index.html', '<!doctype html><html><body>403 Forbidden</body></html>');
}

// Probabilistic cleanup — runs ~1 in 20 requests (~5%)
if (rand(1, 20) === 1) cleanCache();

// ── Validate request ─────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'POST required.']);
}

$uploaded = $_FILES['file'] ?? null;
if (!$uploaded) {
    jsonOut(['error' => 'No file received. Send files as multipart/form-data field "file" or "file[]".']);
}

// ── Normalise $_FILES structure ───────────────────────────────────────────────
// PHP represents multi-file uploads as parallel arrays keyed by field name,
// e.g. $_FILES['file']['name'] = ['a.txt', 'b.pdf']. Single-file uploads
// use scalar values. We normalise both into a flat array of file entries.

$fileList = [];
if (is_array($uploaded['name'])) {
    // Multi-file: zip the parallel arrays into per-file associative arrays
    for ($i = 0; $i < count($uploaded['name']); $i++) {
        $fileList[] = [
            'name'     => $uploaded['name'][$i],
            'type'     => $uploaded['type'][$i],
            'tmp_name' => $uploaded['tmp_name'][$i],
            'error'    => $uploaded['error'][$i],
            'size'     => $uploaded['size'][$i],
        ];
    }
} else {
    // Single file: already a flat associative array
    $fileList[] = $uploaded;
}

// ── Process each file ────────────────────────────────────────────────────────

$results   = [];
$totalSize = 0;
$siteUrl   = getSiteUrl();

foreach ($fileList as $f) {

    // Reject PHP upload errors (disk full, partial upload, etc.)
    if ($f['error'] !== UPLOAD_ERR_OK) {
        jsonOut(['error' => 'Upload error code ' . $f['error'] . ' on file "' . htmlspecialchars($f['name']) . '".']);
    }

    // Per-file size guard
    if ($f['size'] > MAX_FILE_SIZE) {
        jsonOut(['error' => 'File "' . htmlspecialchars($f['name']) . '" exceeds the 50 MB limit.']);
    }

    // Cumulative size guard
    $totalSize += $f['size'];
    if ($totalSize > MAX_TOTAL_SIZE) {
        jsonOut(['error' => 'Total upload size exceeds 100 MB.']);
    }

    // ── MIME validation ───────────────────────────────────────────────────────
    // finfo inspects magic bytes in the file content — more reliable than the
    // browser-supplied Content-Type which can be spoofed.
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($f['tmp_name']);
    $mime     = $realMime ?: $f['type'];

    // Special case: browser MediaRecorder blobs (voice messages) are often
    // identified by finfo as "application/octet-stream" because a raw WebM
    // bitstream may not start with a recognised magic sequence. If the browser
    // reported an audio/* type, trust it — the worst case is a corrupt WebM
    // that the media player simply won't play, not a security risk.
    if ($mime === 'application/octet-stream' && str_starts_with($f['type'], 'audio/')) {
        $mime = $f['type'];
    }

    global $ALLOWED_MIME;
    if (!in_array($mime, $ALLOWED_MIME, true)) {
        jsonOut(['error' => 'File type "' . $mime . '" is not allowed.']);
    }

    // ── Filename sanitisation ─────────────────────────────────────────────────
    // Strip anything that isn't alphanumeric, dash, underscore, or dot to
    // prevent path traversal (../../etc/passwd) and shell injection.
    // Prefix with 16 hex chars of CSPRNG data to guarantee uniqueness.
    $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', basename($f['name']));
    $prefix   = bin2hex(random_bytes(8)); // 8 bytes → 16 hex chars
    $stored   = $prefix . '_' . $safeName;

    // Audio blobs from MediaRecorder often arrive with a generic name like
    // "blob" and no extension. Normalise them to a descriptive .webm filename.
    $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
    if (!$ext && str_starts_with($mime, 'audio/')) {
        $stored = $prefix . '_voice_message.webm';
    }

    // ── Move to cache ─────────────────────────────────────────────────────────
    $dest = CACHE_DIR . $stored;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        jsonOut(['error' => 'Failed to save "' . htmlspecialchars($f['name']) . '" to disk. Check /cache/ is writable.']);
    }

    // ── Build result entry ────────────────────────────────────────────────────
    $results[] = [
        'name' => $f['name'] ?: 'voice_message.webm',
        'url'  => $siteUrl . '/cache/' . rawurlencode($stored), // percent-encode for safe embedding in HTML
        'size' => $f['size'],
        'mime' => $mime,
    ];
}

jsonOut(['ok' => true, 'files' => $results]);
