<?php
/**
 * SpoX+AI — Chats API
 * GET  /api/chats.php              → list user's chats (optionally filtered by project)
 * GET  /api/chats.php?uuid=...     → get single chat with messages
 * POST /api/chats.php              → update chat title
 * DELETE /api/chats.php?uuid=...   → delete chat
 */

require_once __DIR__ . '/helpers.php';

$user   = require_auth();
$method = $_SERVER['REQUEST_METHOD'];

match ($method) {
    'GET'    => isset($_GET['uuid']) ? handle_get_chat($user) : handle_list_chats($user),
    'POST'   => handle_update_chat($user),
    'DELETE' => handle_delete_chat($user),
    default  => json_error('method_not_allowed', 'Method not allowed', 405),
};

function handle_list_chats(array $user): never {
    $projectUuid = isset($_GET['project_uuid']) ? sanitize_uuid($_GET['project_uuid']) : null;

    if ($projectUuid) {
        // Verify project ownership
        $project = DB::query(
            'SELECT id, name FROM projects WHERE uuid = ? AND user_id = ? AND deleted_at IS NULL',
            [$projectUuid, $user['id']]
        )->fetch();
        if (!$project) json_error('not_found', 'Project not found', 404);

        $chats = DB::query(
            'SELECT uuid, title, created_at, updated_at,
                    (SELECT uuid FROM projects WHERE id = chats.project_id) AS project_uuid,
                    (SELECT content FROM messages WHERE chat_id = chats.id ORDER BY created_at LIMIT 1) AS first_message
             FROM chats
             WHERE user_id = ? AND project_id = ? AND deleted_at IS NULL
             ORDER BY updated_at DESC',
            [$user['id'], $project['id']]
        )->fetchAll();

        json_response(['project' => ['uuid' => $projectUuid, 'name' => $project['name']], 'chats' => $chats]);
    }

    // List all chats for the user (including those in projects) for the sidebar to group them
    $chats = DB::query(
        'SELECT uuid, title, created_at, updated_at,
                (SELECT uuid FROM projects WHERE id = chats.project_id) AS project_uuid,
                (SELECT content FROM messages WHERE chat_id = chats.id ORDER BY created_at LIMIT 1) AS first_message
         FROM chats
         WHERE user_id = ? AND deleted_at IS NULL
         ORDER BY updated_at DESC
         LIMIT 200',
        [$user['id']]
    )->fetchAll();

    json_response(['chats' => $chats]);
}

function handle_get_chat(array $user): never {
    $uuid = sanitize_uuid($_GET['uuid']);

    $chat = DB::query(
        'SELECT id, uuid, title, project_id, created_at,
                (SELECT uuid FROM projects WHERE id = chats.project_id) AS project_uuid,
                (SELECT name FROM projects WHERE id = chats.project_id) AS project_name
         FROM chats
         WHERE uuid = ? AND user_id = ? AND deleted_at IS NULL',
        [$uuid, $user['id']]
    )->fetch();

    if (!$chat) json_error('not_found', 'Chat not found', 404);

    $messages = DB::query(
        'SELECT uuid, sender, content, created_at FROM messages WHERE chat_id = ? AND deleted_at IS NULL ORDER BY created_at',
        [$chat['id']]
    )->fetchAll();

    foreach ($messages as &$msg) {
        $msg['files'] = DB::query(
            'SELECT filename, original_name, mime_type, size FROM files WHERE message_uuid = ? AND deleted_at IS NULL',
            [$msg['uuid']]
        )->fetchAll();
    }

    $projectUuid = $chat['project_uuid'];
    unset($chat['id']);
    unset($chat['project_id']);
    $chat['messages'] = $messages;
    $chat['project_uuid'] = $projectUuid;
    json_response(['chat' => $chat]);
}

function handle_update_chat(array $user): never {
    verify_csrf_token();
    $body  = get_json_body();
    $uuid  = sanitize_uuid($body['uuid'] ?? '');
    $title = sanitize_string($body['title'] ?? '', 255);

    if (empty($title)) json_error('missing_title', 'Title required', 400);

    $result = DB::query(
        'UPDATE chats SET title = ? WHERE uuid = ? AND user_id = ? AND deleted_at IS NULL',
        [$title, $uuid, $user['id']]
    );

    json_response(['success' => true]);
}

function handle_delete_chat(array $user): never {
    verify_csrf_token();
    $uuid = sanitize_uuid($_GET['uuid'] ?? '');

    DB::query(
        'UPDATE chats SET deleted_at = NOW() WHERE uuid = ? AND user_id = ?',
        [$uuid, $user['id']]
    );

    log_audit($user['id'], 'chat_deleted', ['uuid' => $uuid]);
    json_response(['success' => true]);
}
