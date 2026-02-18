<?php
/**
 * SpoX+AI — File Upload API
 * POST /api/upload_file.php (multipart/form-data)
 * Fields: file (binary), project_id (optional), chat_id (optional)
 *
 * Only for logged-in users.
 * Max 20 MB. Allowed: PDF, images, text.
 * Extracts text from PDFs for RAG.
 */

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('method_not_allowed', 'POST required', 405);
}

verify_csrf_token();
$user = require_auth();

// Validate upload
if (empty($_FILES['file'])) {
    json_error('no_file', 'No file uploaded', 400);
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    json_error('upload_error', upload_error_message($file['error']), 400);
}

if ($file['size'] > UPLOAD_MAX_BYTES) {
    json_error('file_too_large', 'Datei zu groß. Maximum: 20 MB', 400);
}

// Detect real MIME type
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);

if (!in_array($mimeType, UPLOAD_ALLOWED_MIME, true)) {
    json_error('invalid_mime', 'Dateityp nicht erlaubt. Erlaubt: PDF, Bilder, Text', 400);
}

$projectUuid = !empty($_POST['project_uuid']) ? sanitize_uuid($_POST['project_uuid']) : null;
$chatId      = isset($_POST['chat_id'])      ? (int)$_POST['chat_id']        : null;

$projectId = null;
// Verify project ownership
if ($projectUuid) {
    $project = DB::query(
        'SELECT id FROM projects WHERE uuid = ? AND user_id = ? AND deleted_at IS NULL',
        [$projectUuid, $user['id']]
    )->fetch();
    if (!$project) json_error('not_found', 'Project not found or access denied', 403);
    $projectId = (int)$project['id'];
}

// Generate safe filename
$ext          = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$safeFilename = bin2hex(random_bytes(16)) . '.' . $ext;
$uploadDir    = rtrim(UPLOAD_DIR, '/') . '/';

// Create directory if needed
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        json_error('storage_error', 'Konnte Upload-Verzeichnis nicht erstellen', 500);
    }
}

$storagePath = $uploadDir . $safeFilename;

if (!move_uploaded_file($file['tmp_name'], $storagePath)) {
    json_error('storage_error', 'Datei konnte nicht gespeichert werden', 500);
}

// Extract text for RAG
$extractedText = null;
if ($mimeType === 'application/pdf') {
    $extractedText = extract_pdf_text($storagePath);
} elseif (str_starts_with($mimeType, 'text/')) {
    $extractedText = mb_substr(file_get_contents($storagePath), 0, 100000);
}

// Save to DB
$fileId = DB::insert(
    'INSERT INTO files (user_id, project_id, chat_id, filename, original_name, mime_type, size, storage_path, extracted_text)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
    [
        $user['id'],
        $projectId,
        $chatId,
        $safeFilename,
        sanitize_string($file['name']),
        $mimeType,
        $file['size'],
        $storagePath,
        $extractedText,
    ]
);

log_audit($user['id'], 'file_uploaded', [
    'file_id'    => $fileId,
    'project_id' => $projectId,
    'mime'       => $mimeType,
    'size'       => $file['size'],
]);

json_response([
    'file' => [
        'id'            => (int)$fileId,
        'filename'      => $safeFilename,
        'original_name' => sanitize_string($file['name']),
        'mime_type'     => $mimeType,
        'size'          => $file['size'],
        'has_text'      => $extractedText !== null,
    ],
], 201);

// ─── PDF Text Extraction (pure PHP, no dependencies) ─────────────────────────

function extract_pdf_text(string $path): ?string {
    $content = @file_get_contents($path);
    if ($content === false) return null;

    $text = '';

    // Extract text from PDF streams
    preg_match_all('/stream(.*?)endstream/s', $content, $streams);
    foreach ($streams[1] as $stream) {
        $stream = ltrim($stream, "\r\n");

        // Try zlib decompress
        $decompressed = @gzuncompress($stream);
        if ($decompressed === false) {
            $decompressed = @gzinflate($stream);
        }
        $data = $decompressed !== false ? $decompressed : $stream;

        // Extract readable text (BT...ET blocks)
        preg_match_all('/BT(.*?)ET/s', $data, $btBlocks);
        foreach ($btBlocks[1] as $block) {
            // Extract strings from Tj, TJ, '
            preg_match_all('/\(([^)]*)\)\s*(?:Tj|\'|")|(\[.*?\])\s*TJ/s', $block, $strings);
            foreach ($strings[1] as $s) {
                $text .= $s . ' ';
            }
        }
    }

    // Clean up
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);

    return mb_strlen($text) > 50 ? mb_substr($text, 0, 100000) : null;
}

function upload_error_message(int $code): string {
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Datei zu groß',
        UPLOAD_ERR_PARTIAL   => 'Upload unvollständig',
        UPLOAD_ERR_NO_FILE   => 'Keine Datei ausgewählt',
        UPLOAD_ERR_NO_TMP_DIR => 'Temporäres Verzeichnis fehlt',
        UPLOAD_ERR_CANT_WRITE => 'Schreibfehler',
        default              => 'Upload-Fehler',
    };
}
