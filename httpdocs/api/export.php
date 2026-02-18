<?php
/**
 * SpoX+AI — Data Export API (GDPR Art. 20)
 * GET /api/export.php
 * Returns all user data as JSON download.
 */

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('method_not_allowed', 'GET required', 405);
}

$user = require_auth();

// Gather all user data
$userData = [
    'export_date' => date('c'),
    'user' => [
        'id'           => $user['id'],
        'display_name' => $user['display_name'],
        'username'     => $user['username'],
        'email'        => $user['email'],
    ],
    'projects' => [],
    'chats'    => [],
    'files'    => [],
];

// Projects
$projects = DB::query(
    'SELECT id, name, created_at FROM projects WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at',
    [$user['id']]
)->fetchAll();

foreach ($projects as $project) {
    $userData['projects'][] = $project;
}

// Chats with messages
$chats = DB::query(
    'SELECT id, uuid, title, project_id, created_at FROM chats WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at',
    [$user['id']]
)->fetchAll();

foreach ($chats as $chat) {
    $messages = DB::query(
        'SELECT uuid, sender, content, created_at FROM messages WHERE chat_id = ? ORDER BY created_at',
        [$chat['id']]
    )->fetchAll();

    $chatExport = $chat;
    unset($chatExport['id']); // Don't expose internal IDs
    $chatExport['messages'] = $messages;
    $userData['chats'][] = $chatExport;
}

// Files (metadata only, not content)
$files = DB::query(
    'SELECT original_name, mime_type, size, created_at FROM files WHERE user_id = ? ORDER BY created_at',
    [$user['id']]
)->fetchAll();
$userData['files'] = $files;

log_audit($user['id'], 'data_export');

// Send as JSON download
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="spoxai-export-' . date('Y-m-d') . '.json"');
header('X-Content-Type-Options: nosniff');
echo json_encode($userData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
