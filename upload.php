<?php
/**
 * upload.php — Handle file & voice uploads for the "To" project.
 *
 * POST /upload.php  (multipart/form-data)
 *   file[]  — one or more files
 *   type    — "file" | "audio"
 *
 * Returns:
 *   { ok: true, files: [{ name, url, size, type }] }
 *   { error: "..." }
 *
 * Files are stored in /cache/ with a random prefix to avoid collisions.
 * Cache is auto-cleaned of files older than 30 days.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

define('CACHE_DIR',      __DIR__ . '/cache/');
define('MAX_FILE_SIZE',  52428800);  // 50 MB per file
define('MAX_TOTAL_SIZE', 104857600); // 100 MB total
define('CACHE_TTL',      30 * 86400); // 30 days

// Allowed MIME types
$ALLOWED_MIME = [
    // Audio
    'audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-wav',
    // Video
    'video/mp4', 'video/webm', 'video/ogg',
    // Images
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
    // Documents
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain', 'text/csv',
    // Archives
    'application/zip', 'application/x-zip-compressed',
    'application/x-tar', 'application/gzip',
];

function jsonOut(array $data): void {
    echo json_encode($data);
    exit;
}

function cleanCache(): void {
    $now = time();
    foreach (glob(CACHE_DIR . '*') as $f) {
        if (is_file($f) && ($now - filemtime($f)) > CACHE_TTL) {
            @unlink($f);
        }
    }
}

function getSiteUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path   = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $path   = rtrim($path, '/');
    return $scheme . '://' . $host . $path;
}

// Ensure cache dir exists
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
    // Write an index.html to prevent directory listing
    file_put_contents(CACHE_DIR . 'index.html', '');
}

// Periodic cleanup (5% chance per request)
if (rand(1, 20) === 1) cleanCache();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'POST required.']);
}

$uploaded = $_FILES['file'] ?? null;
if (!$uploaded) {
    jsonOut(['error' => 'No file received.']);
}

// Normalise $_FILES structure (handles both file[] array and single file)
$fileList = [];
if (is_array($uploaded['name'])) {
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
    $fileList[] = $uploaded;
}

$results   = [];
$totalSize = 0;
$siteUrl   = getSiteUrl();

foreach ($fileList as $f) {
    if ($f['error'] !== UPLOAD_ERR_OK) {
        jsonOut(['error' => 'Upload error code ' . $f['error']]);
    }

    if ($f['size'] > MAX_FILE_SIZE) {
        jsonOut(['error' => 'File "' . htmlspecialchars($f['name']) . '" exceeds 50 MB limit.']);
    }

    $totalSize += $f['size'];
    if ($totalSize > MAX_TOTAL_SIZE) {
        jsonOut(['error' => 'Total upload size exceeds 100 MB.']);
    }

    // Validate MIME (finfo first, then fallback to reported type)
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($f['tmp_name']);
    $mime     = $realMime ?: $f['type'];

    // For audio/webm blobs from browser recorder, MIME may be reported as application/octet-stream
    // Allow it if the original reported type was audio/
    if ($mime === 'application/octet-stream' && str_starts_with($f['type'], 'audio/')) {
        $mime = $f['type'];
    }

    if (!in_array($mime, $ALLOWED_MIME, true)) {
        jsonOut(['error' => 'File type "' . $mime . '" is not allowed.']);
    }

    // Build safe filename
    $ext      = pathinfo($f['name'], PATHINFO_EXTENSION);
    $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', basename($f['name']));
    $prefix   = bin2hex(random_bytes(8));
    $stored   = $prefix . '_' . $safeName;

    // For audio blobs that come without extension
    if (!$ext && str_starts_with($mime, 'audio/')) {
        $ext    = 'webm';
        $stored = $prefix . '_voice_message.webm';
    }

    $dest = CACHE_DIR . $stored;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        jsonOut(['error' => 'Failed to save file.']);
    }

    $results[] = [
        'name' => $f['name'] ?: 'voice_message.webm',
        'url'  => $siteUrl . '/cache/' . rawurlencode($stored),
        'size' => $f['size'],
        'mime' => $mime,
    ];
}

jsonOut(['ok' => true, 'files' => $results]);
