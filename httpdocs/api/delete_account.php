<?php
/**
 * SpoX+AI — Delete Account API (GDPR Art. 17)
 * DELETE /api/delete_account.php
 * Soft-deletes user and all their data.
 */

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('method_not_allowed', 'DELETE required', 405);
}

verify_csrf_token();
$user = require_auth();

// Soft-delete all user data
DB::get()->beginTransaction();
try {
    // Delete messages (via cascade from chats)
    // Soft-delete chats
    DB::query('UPDATE chats SET deleted_at = NOW() WHERE user_id = ?', [$user['id']]);
    // Soft-delete projects
    DB::query('UPDATE projects SET deleted_at = NOW() WHERE user_id = ?', [$user['id']]);
    // Soft-delete user
    DB::query('UPDATE users SET deleted_at = NOW(), email = ?, display_name = ? WHERE id = ?', [
        'deleted-' . $user['id'] . '@deleted.invalid',
        '[Gelöscht]',
        $user['id'],
    ]);

    DB::get()->commit();
} catch (Throwable $e) {
    DB::get()->rollBack();
    log_error('delete_account_failed', ['user_id' => $user['id'], 'error' => $e->getMessage()]);
    json_error('delete_failed', 'Konto konnte nicht gelöscht werden', 500);
}

log_audit($user['id'], 'account_deleted');

// Destroy session
session_destroy();
setcookie(session_name(), '', time() - 3600, '/', '', true, true);

json_response(['success' => true, 'message' => 'Ihr Konto wurde gelöscht.']);
