<?php
/**
 * SpoX+AI — Guest Chat Sync API
 * POST /api/sync_local.php
 *
 * Body: { "chats": [ { "uuid":"...", "title":"...", "messages":[...], "last_updated":... } ] }
 * Response: { "synced": N, "merged": [...] }
 *
 * Syncs LocalStorage chats to DB after user logs in.
 */

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('method_not_allowed', 'POST required', 405);
}

verify_csrf_token();
$user  = require_auth();
$body  = get_json_body();
$chats = $body['chats'] ?? [];

if (!is_array($chats)) {
    json_error('invalid_data', 'chats must be an array', 400);
}

$synced = 0;
$merged = [];

foreach ($chats as $chat) {
    if (empty($chat['uuid'])) continue;

    try {
        $uuid = sanitize_uuid($chat['uuid']);
    } catch (Throwable) {
        continue; // Skip invalid UUIDs
    }

    $title    = sanitize_string($chat['title'] ?? 'Untitled Chat', 255);
    $messages = $chat['messages'] ?? [];

    // Check if UUID already exists server-side
    $existing = DB::query('SELECT id, user_id FROM chats WHERE uuid = ?', [$uuid])->fetch();

    if ($existing) {
        if ($existing['user_id'] === $user['id']) {
            // Already owned by this user — skip
            continue;
        }
        // Conflict: UUID exists but owned by different user (shouldn't happen, but handle gracefully)
        $newUuid = generate_uuid();
        $merged[] = ['old_uuid' => $uuid, 'new_uuid' => $newUuid];
        $uuid = $newUuid;
    }

    // Create chat
    $chatId = DB::insert(
        'INSERT INTO chats (user_id, uuid, title, synced) VALUES (?, ?, ?, 1)',
        [$user['id'], $uuid, $title]
    );

    // Insert messages
    foreach (array_slice($messages, 0, 500) as $msg) {
        $sender  = ($msg['sender'] ?? '') === 'user' ? 'user' : 'assistant';
        $content = sanitize_string($msg['content'] ?? '', 4000);
        if (empty($content)) continue;

        DB::query(
            'INSERT IGNORE INTO messages (chat_id, uuid, sender, content) VALUES (?, ?, ?, ?)',
            [$chatId, generate_uuid(), $sender, $content]
        );
    }

    $synced++;
}

log_audit($user['id'], 'local_sync', ['synced' => $synced, 'merged_count' => count($merged)]);
json_response(['synced' => $synced, 'merged' => $merged]);
