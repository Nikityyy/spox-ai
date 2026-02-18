<?php
/**
 * SpoX+AI — Projects API
 * GET  /api/projects.php        → list user's projects
 * POST /api/projects.php        → create project
 * DELETE /api/projects.php?id=N → delete project
 */

require_once __DIR__ . '/helpers.php';

$user   = require_auth();
$method = $_SERVER['REQUEST_METHOD'];

match ($method) {
    'GET'    => handle_list($user),
    'POST'   => handle_create($user),
    'DELETE' => handle_delete($user),
    default  => json_error('method_not_allowed', 'Method not allowed', 405),
};

function handle_list(array $user): never {
    $projects = DB::query(
        'SELECT p.uuid, p.name, p.created_at,
                COUNT(DISTINCT c.id) AS chat_count,
                COUNT(DISTINCT f.id) AS file_count
         FROM projects p
         LEFT JOIN chats c ON c.project_id = p.id AND c.deleted_at IS NULL
         LEFT JOIN files  f ON f.project_id = p.id
         WHERE p.user_id = ? AND p.deleted_at IS NULL
         GROUP BY p.id
         ORDER BY p.created_at DESC',
        [$user['id']]
    )->fetchAll();

    json_response(['projects' => $projects]);
}

function handle_create(array $user): never {
    verify_csrf_token();
    $body = get_json_body();
    $name = sanitize_string($body['name'] ?? '', 100);

    if (empty($name)) {
        json_error('missing_name', 'Project name is required', 400);
    }

    $uuid = generate_uuid();
    $id = DB::insert(
        'INSERT INTO projects (user_id, uuid, name) VALUES (?, ?, ?)',
        [$user['id'], $uuid, $name]
    );

    log_audit($user['id'], 'project_created', ['project_id' => $id]);
    json_response(['project' => ['uuid' => $uuid, 'name' => $name]], 201);
}

function handle_delete(array $user): never {
    verify_csrf_token();
    $uuid = sanitize_uuid($_GET['uuid'] ?? '');
    if (!$uuid) json_error('missing_uuid', 'Project UUID required', 400);

    // Verify ownership
    $project = DB::query(
        'SELECT id FROM projects WHERE uuid = ? AND user_id = ? AND deleted_at IS NULL',
        [$uuid, $user['id']]
    )->fetch();

    if (!$project) json_error('not_found', 'Project not found', 404);

    DB::query('UPDATE projects SET deleted_at = NOW() WHERE id = ?', [$project['id']]);
    log_audit($user['id'], 'project_deleted', ['project_id' => $project['id']]);
    json_response(['success' => true]);
}
