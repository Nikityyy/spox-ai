<?php
/**
 * SpoX+AI — GDPR Consent API
 * POST /api/consent.php
 * Body: { "functional": true }
 */

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('method_not_allowed', 'POST required', 405);
}

$body      = get_json_body();
$user      = get_session_user();
$userId    = $user['id'] ?? null;
$sessionId = session_id() ?: bin2hex(random_bytes(8));

$consentData = json_encode([
    'functional' => (bool)($body['functional'] ?? true),
    'ts'         => time(),
    'version'    => '1.0',
]);

DB::query(
    'INSERT INTO consent (user_id, session_hash, consent_data, ip_hash) VALUES (?, ?, ?, ?)',
    [
        $userId,
        hash('sha256', $sessionId),
        $consentData,
        mask_ip($_SERVER['REMOTE_ADDR'] ?? ''),
    ]
);

json_response(['success' => true]);
