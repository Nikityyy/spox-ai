<?php
/**
 * SpoX+AI — Files API
 * GET    /api/files.php?project_id=...  → list files for a project
 * DELETE /api/files.php?id=...          → delete a file
 *
 * Only for logged-in users.
 */

require_once __DIR__ . '/helpers.php';

$user   = require_auth();
$method = $_SERVER['REQUEST_METHOD'];

match ($method) {
    'GET'    => handle_list_files($user),
    'DELETE' => handle_delete_file($user),
    default  => json_error('method_not_allowed', 'Method not allowed', 405),
};

function handle_list_files(array $user): never {
    $projectUuid = isset($_GET['project_uuid']) ? sanitize_uuid($_GET['project_uuid']) : null;
    if (!$projectUuid) json_error('missing_uuid', 'Project UUID required', 400);

    // Verify ownership
    $project = DB::query('SELECT id FROM projects WHERE uuid = ? AND user_id = ? AND deleted_at IS NULL', [$projectUuid, $user['id']])->fetch();
    if (!$project) json_error('not_found', 'Project not found', 404);

    $files = DB::query(
        'SELECT id, filename, original_name, mime_type, size, created_at FROM files WHERE project_id = ? AND user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC',
        [$project['id'], $user['id']]
    )->fetchAll();

    json_response(['files' => $files]);
}

function handle_delete_file(array $user): never {
    verify_csrf_token();
    $fileId = isset($_GET['id']) ? (int)$_GET['id'] : null;
    if (!$fileId) json_error('missing_id', 'File ID required', 400);

    // Verify ownership and get storage path
    $file = DB::query('SELECT storage_path FROM files WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [$fileId, $user['id']])->fetch();
    if (!$file) json_error('not_found', 'File not found', 404);

    // Soft delete in DB
    DB::query('UPDATE files SET deleted_at = NOW() WHERE id = ?', [$fileId]);

    // Optional: actually delete the file from disk or keep it for audit? 
    // Usually, for GDPR, we should delete it.
    if (!empty($file['storage_path']) && file_exists($file['storage_path'])) {
        @unlink($file['storage_path']);
    }

    log_audit($user['id'], 'file_deleted', ['file_id' => $fileId]);
    json_response(['success' => true]);
}
