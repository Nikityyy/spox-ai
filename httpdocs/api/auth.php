<?php
// DEBUG: Diagnostics
if (isset($_GET['test'])) {
    header('Content-Type: text/plain');
    echo "=== SpoX+ AI Diagnostics ===\n";
    echo "PHP Version: " . phpversion() . "\n";
    echo "Modules: " . implode(', ', get_loaded_extensions()) . "\n\n";

    if ($_GET['test'] === 'info') {
        phpinfo(); exit;
    }

    require_once __DIR__ . '/helpers.php';
    echo "Config loaded: " . (defined('DB_NAME') ? "YES" : "NO") . "\n";
    echo "Database: " . (defined('DB_NAME') ? DB_NAME : 'N/A') . "\n";
    
    try {
        $pdo = DB::get();
        echo "Database Connection: SUCCESS\n";
        $res = $pdo->query("SELECT 1")->fetch();
        echo "Query Test: SUCCESS\n";
    } catch (Throwable $e) {
        echo "Database Connection: FAILED\n";
        echo "Error: " . $e->getMessage() . "\n";
    }
}

/**
 * Endpoints:
 *   GET  /api/auth.php?action=login    → redirect to MS login
 *   GET  /api/auth.php?action=callback → handle OAuth2 callback
 *   POST /api/auth.php?action=logout   → destroy session
 *   GET  /api/auth.php?action=me       → return current user JSON
 *   GET  /api/auth.php?action=csrf     → return CSRF token
 */

require_once __DIR__ . '/helpers.php';

$action = $_GET['action'] ?? 'me';

match ($action) {
    'login'    => handle_login(),
    'callback' => handle_callback(),
    'logout'   => handle_logout(),
    'me'       => handle_me(),
    'csrf'     => handle_csrf(),
    default    => json_error('not_found', 'Unknown action', 404),
};

// ─── Login: redirect to Microsoft ────────────────────────────────────────────
function handle_login(): never {
    session_start_secure();
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    $params = http_build_query([
        'client_id'     => MS_CLIENT_ID,
        'response_type' => 'code',
        'redirect_uri'  => MS_REDIRECT_URI,
        'response_mode' => 'query',
        'scope'         => 'openid profile email User.Read',
        'state'         => $state,
        'prompt'        => 'select_account',
    ]);

    $authUrl = 'https://login.microsoftonline.com/' . MS_TENANT_ID . '/oauth2/v2.0/authorize?' . $params;
    header('Location: ' . $authUrl);
    exit;
}

// ─── Callback: exchange code for token ───────────────────────────────────────
function handle_callback(): never {
    session_start_secure();

    // Validate state
    $state = $_GET['state'] ?? '';
    if (!hash_equals($_SESSION['oauth_state'] ?? '', $state)) {
        json_error('invalid_state', 'OAuth state mismatch', 400);
    }
    unset($_SESSION['oauth_state']);

    // Check for error from MS
    if (isset($_GET['error'])) {
        $err = htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
        header('Location: ' . APP_URL . '/?auth_error=' . urlencode($err));
        exit;
    }

    $code = $_GET['code'] ?? '';
    if (empty($code)) {
        json_error('missing_code', 'Authorization code missing', 400);
    }

    // Exchange code for tokens
    $tokenData = exchange_code_for_token($code);
    if (!$tokenData || empty($tokenData['access_token'])) {
        log_error('oauth_token_exchange_failed', ['code' => substr($code, 0, 8)]);
        header('Location: ' . APP_URL . '/?auth_error=token_exchange_failed');
        exit;
    }

    // Get user profile from MS Graph
    $profile = get_ms_graph_profile($tokenData['access_token']);
    if (!$profile) {
        header('Location: ' . APP_URL . '/?auth_error=profile_fetch_failed');
        exit;
    }

    // Validate domain
    $email = strtolower($profile['mail'] ?? $profile['userPrincipalName'] ?? '');
    if (!str_ends_with($email, '@' . MS_ALLOWED_DOMAIN)) {
        log_audit(null, 'login_rejected_domain', ['email_masked' => mask_email($email)]);
        header('Location: ' . APP_URL . '/?auth_error=domain_not_allowed');
        exit;
    }

    // Upsert user in DB
    $user = upsert_user($profile, $email);

    // Store in session
    $_SESSION['user'] = $user;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    log_audit($user['id'], 'login_success');

    // Redirect to app
    header('Location: ' . APP_URL . '/');
    exit;
}

// ─── Logout ──────────────────────────────────────────────────────────────────
function handle_logout(): never {
    session_start_secure();
    $userId = $_SESSION['user']['id'] ?? null;
    log_audit($userId, 'logout');
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/', '', true, true);
    json_response(['success' => true]);
}

// ─── Me: return current user ─────────────────────────────────────────────────
function handle_me(): never {
    $user = get_session_user();
    if (!$user) {
        json_response(['authenticated' => false]);
    }
    json_response([
        'authenticated' => true,
        'user' => [
            'id'           => $user['id'],
            'display_name' => $user['display_name'],
            'username'     => $user['username'],
            'email'        => $user['email'],
            'role'         => $user['role'],
        ],
        'csrf_token' => generate_csrf_token(),
    ]);
}

// ─── CSRF token ──────────────────────────────────────────────────────────────
function handle_csrf(): never {
    json_response(['csrf_token' => generate_csrf_token()]);
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function exchange_code_for_token(string $code): ?array {
    $url  = 'https://login.microsoftonline.com/' . MS_TENANT_ID . '/oauth2/v2.0/token';
    $body = http_build_query([
        'client_id'     => MS_CLIENT_ID,
        'client_secret' => MS_CLIENT_SECRET,
        'code'          => $code,
        'redirect_uri'  => MS_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]);

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body),
        'content' => $body,
        'timeout' => 10,
    ]]);

    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) return null;

    try {
        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
}

function get_ms_graph_profile(string $accessToken): ?array {
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "Authorization: Bearer $accessToken\r\nAccept: application/json",
        'timeout' => 10,
    ]]);

    $response = @file_get_contents('https://graph.microsoft.com/v1.0/me', false, $ctx);
    if ($response === false) return null;

    try {
        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
}

function upsert_user(array $profile, string $email): array {
    $msOid       = $profile['id'] ?? '';
    $displayName = sanitize_string($profile['displayName'] ?? 'Unbekannt');
    $username    = sanitize_string(strtolower($profile['userPrincipalName'] ?? explode('@', $email)[0]));

    // Try to find existing user (including soft-deleted ones)
    $existing = DB::query(
        'SELECT * FROM users WHERE ms_oid = ?',
        [$msOid]
    )->fetch();
    
    if ($existing) {
        // Update profile and restore if soft-deleted
        DB::query(
            'UPDATE users SET deleted_at = NULL, last_login_at = NOW(), display_name = ?, email = ? WHERE id = ?',
            [$displayName, $email, $existing['id']]
        );
        $existing['display_name'] = $displayName;
        $existing['email']        = $email;
        $existing['deleted_at']   = null;
        return $existing;
    }

    // Create new user
    $id = DB::insert(
        'INSERT INTO users (ms_oid, email, display_name, username, last_login_at) VALUES (?, ?, ?, ?, NOW())',
        [$msOid, $email, $displayName, $username]
    );

    return [
        'id'           => (int)$id,
        'ms_oid'       => $msOid,
        'email'        => $email,
        'display_name' => $displayName,
        'username'     => $username,
        'role'         => 'student',
    ];
}
