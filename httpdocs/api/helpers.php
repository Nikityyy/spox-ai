<?php
/**
 * SpoX+ AI — Shared Helpers
 * Security utilities, response helpers, logging
 */

// Debug: Enable error reporting (Temporary)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Dynamic config path resolution (Local vs Production Shared Hosting)
// On prod, httpdocs/api/ needs httpdocs/config/ because of open_basedir
$configPaths = [
    __DIR__ . '/../config/.env.php',
];

$configLoaded = false;
foreach ($configPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        require_once dirname($path) . '/db.php';
        $configLoaded = true;
        break;
    }
}

if (!$configLoaded) {
    die('Critical Error: Configuration file not found.');
}

// ─── One-time Migration Hook: Database Schema ────────────────────────────────
try {
    $db = DB::get();
    // 1. Projects: Ensure uuid column exists and is populated
    $checkProjUuid = $db->query("SHOW COLUMNS FROM projects LIKE 'uuid'")->fetch();
    if (!$checkProjUuid) {
        $db->query("ALTER TABLE projects ADD COLUMN uuid VARCHAR(36) NULL AFTER user_id");
    }
    
    // Populate missing UUIDs
    $toMigrate = $db->query("SELECT id FROM projects WHERE uuid IS NULL OR uuid = ''")->fetchAll();
    if (count($toMigrate) > 0) {
        foreach ($toMigrate as $p) {
            $db->query("UPDATE projects SET uuid = ? WHERE id = ?", [generate_uuid(), $p['id']]);
        }
    }

    if (!$checkProjUuid) {
        $db->query("ALTER TABLE projects MODIFY uuid VARCHAR(36) NOT NULL UNIQUE");
    }

    // 2. Files: Add deleted_at column
    $checkFilesDel = $db->query("SHOW COLUMNS FROM files LIKE 'deleted_at'")->fetch();
    if (!$checkFilesDel) {
        $db->query("ALTER TABLE files ADD COLUMN deleted_at TIMESTAMP NULL AFTER created_at");
    }

    // 3. Files: Add message_uuid column to link files to specific messages
    $checkFilesMsg = $db->query("SHOW COLUMNS FROM files LIKE 'message_uuid'")->fetch();
    if (!$checkFilesMsg) {
        $db->query("ALTER TABLE files ADD COLUMN message_uuid VARCHAR(36) NULL AFTER chat_id");
        $db->query("CREATE INDEX idx_files_message_uuid ON files (message_uuid)");
    }

    // 4. Messages: Add deleted_at column (for consistency)
    $checkMsgDel = $db->query("SHOW COLUMNS FROM messages LIKE 'deleted_at'")->fetch();
    if (!$checkMsgDel) {
        $db->query("ALTER TABLE messages ADD COLUMN deleted_at TIMESTAMP NULL AFTER created_at");
    }

    // 5. Gemini Caches: Table for context caching
    $checkCacheTable = $db->query("SHOW TABLES LIKE 'gemini_caches'")->fetch();
    if (!$checkCacheTable) {
        $db->query("CREATE TABLE gemini_caches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cache_name VARCHAR(255) NOT NULL,
            model VARCHAR(128) NOT NULL,
            display_name VARCHAR(128) NOT NULL,
            prompt_hash VARCHAR(32) NULL,
            expire_time TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_prompt_hash (prompt_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        // Migration for existing table
        $checkCacheHash = $db->query("SHOW COLUMNS FROM gemini_caches LIKE 'prompt_hash'")->fetch();
        if (!$checkCacheHash) {
            $db->query("ALTER TABLE gemini_caches ADD COLUMN prompt_hash VARCHAR(32) NULL AFTER display_name");
            $db->query("CREATE INDEX idx_gemini_caches_prompt_hash ON gemini_caches (prompt_hash)");
        }
    }
} catch (Throwable $e) { 
    // Log to error_logs for debugging
    log_error('migration_hook_failed', ['error' => $e->getMessage()]);
}

// ─── Response Helpers ────────────────────────────────────────────────────────

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function json_error(string $code, string $message, int $status = 400): never {
    json_response(['error' => $code, 'message' => $message], $status);
}

// ─── Session ─────────────────────────────────────────────────────────────────

function session_start_secure(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function get_session_user(): ?array {
    session_start_secure();
    return $_SESSION['user'] ?? null;
}

function require_auth(): array {
    $user = get_session_user();
    if (!$user) {
        json_error('unauthorized', 'Authentication required', 401);
    }
    return $user;
}

// ─── CSRF ────────────────────────────────────────────────────────────────────

function generate_csrf_token(): string {
    session_start_secure();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(): void {
    session_start_secure();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        json_error('csrf_invalid', 'Invalid CSRF token', 403);
    }
}

// ─── Input Sanitization ──────────────────────────────────────────────────────

function sanitize_string(string $input, int $maxLen = 255): string {
    return mb_substr(trim($input), 0, $maxLen);
}

function sanitize_uuid(string $input): string {
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $input)) {
        json_error('invalid_uuid', 'Invalid UUID format', 400);
    }
    return strtolower($input);
}

function get_json_body(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];
    try {
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? [];
    } catch (JsonException) {
        json_error('invalid_json', 'Invalid JSON body', 400);
    }
}

// ─── Rate Limiting ───────────────────────────────────────────────────────────

function check_rate_limit(string $identifier): void {
    $keyHash     = hash('sha256', $identifier);
    $windowStart = date('Y-m-d H:i:00'); // current minute
    $limit       = RATE_LIMIT_PER_USER_PER_MINUTE;

    try {
        $db = DB::get();
        // Upsert counter
        $stmt = $db->prepare(
            'INSERT INTO rate_limits (key_hash, window_start, count)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE count = count + 1'
        );
        $stmt->execute([$keyHash, $windowStart]);

        $row = DB::query(
            'SELECT count FROM rate_limits WHERE key_hash = ? AND window_start = ?',
            [$keyHash, $windowStart]
        )->fetch();

        if ($row && $row['count'] > $limit) {
            header('Retry-After: 60');
            json_error('rate_limited', 'Zu viele Anfragen. Bitte warte eine Minute.', 429);
        }

        // Cleanup old windows (older than 2 minutes)
        DB::query(
            'DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 2 MINUTE)'
        );
    } catch (PDOException $e) {
        // If rate limit table fails, log but don't block user
        log_error('rate_limit_error', ['message' => $e->getMessage()]);
    }
}

// ─── Logging ─────────────────────────────────────────────────────────────────

function mask_email(string $email): string {
    [$local, $domain] = explode('@', $email, 2);
    return substr($local, 0, 1) . '***@' . $domain;
}

function mask_ip(string $ip): string {
    return hash('sha256', $ip . 'spox-salt-2024');
}

function log_audit(int $userId = null, string $action = '', array $details = []): void {
    try {
        $ipHash = mask_ip($_SERVER['REMOTE_ADDR'] ?? '');
        DB::query(
            'INSERT INTO audit_logs (user_id, action, ip_hash, details) VALUES (?, ?, ?, ?)',
            [$userId, $action, $ipHash, json_encode($details)]
        );
    } catch (Throwable) { /* silent */ }
}

function log_error(string $message, array $context = [], string $level = 'error'): void {
    try {
        DB::query(
            'INSERT INTO error_logs (level, message, context) VALUES (?, ?, ?)',
            [$level, $message, json_encode($context)]
        );
    } catch (Throwable) { /* silent */ }
}

// ─── UUID Generation ─────────────────────────────────────────────────────────

function generate_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ─── RAG Context ─────────────────────────────────────────────────────────────

function build_rag_context(int $projectId, string $message): string {
    try {
        // Fetch files from the project that have extracted text
        // For a simple initial implementation, we take all extracted text until a limit.
        // In a more advanced version, we would use vector search here.
        $files = DB::query(
            "SELECT filename, extracted_text FROM files 
             WHERE project_id = ? AND deleted_at IS NULL AND extracted_text IS NOT NULL 
             LIMIT 20",
            [$projectId]
        )->fetchAll();

        if (empty($files)) return "";

        $context = "Hier sind relevante Informationen aus dem Projekt-Kontext:\n\n";
        $totalLength = 0;
        $maxLength = 10000; // Limit to ~10k characters for context to avoid huge payloads

        foreach ($files as $file) {
            $snippet = trim($file['extracted_text']);
            if (empty($snippet)) continue;

            $header = "--- Datei: {$file['filename']} ---\n";
            if (($totalLength + strlen($header) + strlen($snippet)) > $maxLength) {
                $snippet = substr($snippet, 0, $maxLength - $totalLength - strlen($header));
                if (strlen($snippet) < 50) break; // Don't add tiny fragments
            }

            $context .= $header . $snippet . "\n\n";
            $totalLength += strlen($header) + strlen($snippet) + 2;

            if ($totalLength >= $maxLength) break;
        }

        return $context;
    } catch (Throwable $e) {
        log_error('build_rag_context_failed', ['error' => $e->getMessage()]);
        return "";
    }
}
