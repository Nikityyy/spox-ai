<?php
/**
 * SpoX+AI — Chat API with Google Gemini Streaming SSE
 * POST /api/chat.php
 *
 * Body: {
 *   "chat_uuid": "uuid-v4",
 *   "message": "user message",
 *   "project_id": null | int,
 *   "history": [ {"sender":"user","content":"..."}, ... ]
 * }
 *
 * Response: text/event-stream (SSE)
 *   data: {"type":"token","text":"..."}
 *   data: {"type":"done","chat_uuid":"...","title":"..."}
 *   data: {"type":"error","message":"..."}
 */

// Disable all output buffering for true SSE streaming
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', 'off');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { ob_end_clean(); }

require_once __DIR__ . '/helpers.php';

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('method_not_allowed', 'POST required', 405);
}

// CSRF check
verify_csrf_token();

// Get user (optional — guests can chat too)
$user = get_session_user();
$userId = $user['id'] ?? null;

// Parse body
$body      = get_json_body();
$message   = sanitize_string($body['message'] ?? '', 4000);
$chatUuid   = !empty($body['chat_uuid']) ? sanitize_uuid($body['chat_uuid']) : generate_uuid();
$projectUuid = !empty($body['project_uuid']) ? sanitize_uuid($body['project_uuid']) : null;
$history     = $body['history'] ?? [];
$fileNames   = $body['files'] ?? []; // Array of filenames to attach
$maturaMode  = !empty($body['matura_mode']) && $body['matura_mode'] === true;

$projectId = null;
if ($projectUuid && $userId) {
    $project = DB::query('SELECT id FROM projects WHERE uuid = ? AND user_id = ? AND deleted_at IS NULL', [$projectUuid, $userId])->fetch();
    if ($project) $projectId = $project['id'];
}
// Verify project ownership
if ($projectId) {
    // Already verified above, but let's be safe
    if (!$projectId) json_error('forbidden', 'Project not found or access denied', 403);
}

if (empty($message)) {
    json_error('empty_message', 'Message cannot be empty', 400);
}

// Rate limiting
$rateLimitKey = $userId ? 'user:' . $userId : 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
check_rate_limit($rateLimitKey);

// Ensure chat exists in DB (for logged-in users)
if ($userId) {
    ensure_chat_exists($chatUuid, $userId, $projectId);
}

// Build RAG context if project
$ragContext = '';
if ($projectId && $userId) {
    try {
        $ragContext = build_rag_context($projectId, $message);
    } catch (Throwable $e) {
        log_error('rag_failed', ['message' => $e->getMessage()]);
    }
}

// CRITICAL-PERFORMANCE: Save user message to DB immediately
// This ensures that even if Gemini fails or the user refreshes, the prompt is recorded.
if ($userId) {
    try {
        $msgUuid = save_message($chatUuid, 'user', $message);
        
        // Link files to this specific message
        if ($msgUuid && !empty($fileNames)) {
            $placeholders = implode(',', array_fill(0, count($fileNames), '?'));
            $params = array_merge([$msgUuid, $userId], $fileNames);
            DB::query(
                "UPDATE files SET message_uuid = ? WHERE user_id = ? AND filename IN ($placeholders) AND message_uuid IS NULL",
                $params
            );
        }

        // Auto-generate title from first message immediately
        $title = generate_chat_title($message);
        DB::query('UPDATE chats SET title = ? WHERE uuid = ? AND title IS NULL', [$title, $chatUuid]);
    } catch (Throwable $e) {
        log_error('save_user_message_failed', ['error' => $e->getMessage()]);
    }
}

// CRITICAL: Release session lock before streaming.
// PHP holds the session file lock for the entire request duration.
// Without this, SSE tokens cannot flush to the browser until the script ends.
session_write_close();

// Disable Apache compression if possible
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

// Start SSE headers
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');
header('Content-Encoding: identity');
header('X-Content-Type-Options: nosniff');
header('Connection: keep-alive');

// Kill all PHP buffers again just before starting
while (ob_get_level() > 0) { ob_end_clean(); }

// Browsers/Proxies often buffer the first ~1KB.
// We use a comment to "open the pipe".
echo ': ' . str_repeat(' ', 1024) . "\n\n";
@ob_flush();
flush();

// Call Gemini with fallback rotation
$startTime    = microtime(true);
$fullResponse = '';
$error        = null;

$apiKeys = GEMINI_API_KEYS;
$models  = GEMINI_MODELS;

// Pick a starting key index randomly to distribute load
$startingKeyIndex = mt_rand(0, count($apiKeys) - 1);
$success = false;
$actualModel = $models[0];

for ($k = 0; $k < count($apiKeys); $k++) {
    $keyIndex = ($startingKeyIndex + $k) % count($apiKeys);
    $apiKey = $apiKeys[$keyIndex];

    for ($m = 0; $m < count($models); $m++) {
        $actualModel = $models[$m];
        
        try {
            $cachedContentName = null;
            $geminiMessages = build_gemini_messages($userId, $chatUuid, $history, $message, $ragContext, $fileNames, $cachedContentName, $projectId, $maturaMode);

            $fullResponse = stream_gemini($geminiMessages, function(string $token) {
                send_sse(['type' => 'token', 'text' => $token]);
            }, $actualModel, $apiKey, $cachedContentName);

            $success = true;
            break 2; // Exit both loops on success
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $isRateLimit = (str_contains($error, '429') || str_contains($error, 'quota') || str_contains($error, 'exhausted'));
            
            log_error('gemini_retry', [
                'model' => $actualModel,
                'key_index' => $keyIndex,
                'error' => $error,
                'is_rate_limit' => $isRateLimit
            ]);

            if ($isRateLimit) {
                continue; // Try next model/key
            } else {
                continue; // Still rotate just in case
            }
        }
    }
}

if (!$success) {
    log_error('gemini_all_fallbacks_failed', ['error' => $error]);
    send_sse(['type' => 'error', 'message' => 'SpoX+ AI ist momentan überlastet. Bitte versuche es in wenigen Minuten erneut.']);
    send_sse(['type' => 'done', 'chat_uuid' => $chatUuid]);
    exit;
}

$durationMs = (int)((microtime(true) - $startTime) * 1000);

// Save bot response to DB
if ($userId && !empty($fullResponse)) {
    save_message($chatUuid, 'assistant', $fullResponse);
}

// Log Gemini call
log_gemini_call($userId, $chatUuid, $durationMs, null, $actualModel);

// Send done event
$title = generate_chat_title($message);
send_sse(['type' => 'done', 'chat_uuid' => $chatUuid, 'title' => $title]);
exit;

// ─── Helpers ─────────────────────────────────────────────────────────────────

function send_sse(array $data): void {
    // Send data as JSON
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    
    // Explicitly flush to avoid buffering
    @ob_flush();
    flush();
}

function ensure_chat_exists(string $uuid, int $userId, ?string $projectUuid): void {
    $projectId = null;
    if ($projectUuid) {
        $p = DB::query('SELECT id FROM projects WHERE uuid = ? AND user_id = ? AND deleted_at IS NULL', [$projectUuid, $userId])->fetch();
        if ($p) $projectId = $p['id'];
    }

    $existing = DB::query('SELECT id, project_id FROM chats WHERE uuid = ?', [$uuid])->fetch();
    if (!$existing) {
        DB::query(
            'INSERT INTO chats (user_id, project_id, uuid) VALUES (?, ?, ?)',
            [$userId, $projectId, $uuid]
        );
    } else if ($projectId && $existing['project_id'] !== $projectId) {
        DB::query('UPDATE chats SET project_id = ? WHERE id = ?', [$projectId, $existing['id']]);
    }
}

function save_message(string $chatUuid, string $sender, string $content): ?string {
    $chat = DB::query('SELECT id FROM chats WHERE uuid = ?', [$chatUuid])->fetch();
    if (!$chat) return null;
    $msgUuid = generate_uuid();
    DB::query(
        'INSERT INTO messages (chat_id, uuid, sender, content) VALUES (?, ?, ?, ?)',
        [$chat['id'], $msgUuid, $sender, $content]
    );
    return $msgUuid;
}

// ─── Gemini Cache Management ──────────────────────────────────────────────────

// ─── Gemini Cache Management ──────────────────────────────────────────────────

function get_or_create_gemini_cache(string $model, string $prompt, string $cacheKey = 'system_prompt'): ?string {
    $apiKeys = GEMINI_API_KEYS;
    $apiKey  = $apiKeys[0]; // Baseline key for cache management
    
    // Hash the prompt to identify if it changed
    $promptHash = md5($prompt);

    try {
        // Check for active cache in DB with same hash
        // Use a 10-minute buffer before expiration
        $cache = DB::query(
            "SELECT cache_name FROM gemini_caches 
             WHERE model = ? AND prompt_hash = ? AND expire_time > DATE_ADD(NOW(), INTERVAL 10 MINUTE) 
             ORDER BY created_at DESC LIMIT 1",
            [$model, $promptHash]
        )->fetch();

        if ($cache) return $cache['cache_name'];

        // Create new cache via API
        $url = "https://generativelanguage.googleapis.com/v1beta/cachedContents?key={$apiKey}";
        $payload = [
            'model' => "models/{$model}",
            'systemInstruction' => [
                'parts' => [['text' => $prompt]]
            ],
            'ttl' => '3600s' // 1 hour
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpStatus >= 200 && $httpStatus < 300) {
            $data = json_decode($response, true);
            $cacheName = $data['name'] ?? null;
            $expireTime = $data['expireTime'] ?? null;

            if ($cacheName && $expireTime) {
                // Convert ISO8601 to MySQL format
                $dt = new DateTime($expireTime);
                $mysqlTime = $dt->format('Y-m-d H:i:s');

                DB::query(
                    "INSERT INTO gemini_caches (cache_name, model, display_name, expire_time, prompt_hash) VALUES (?, ?, ?, ?, ?)",
                    [$cacheName, $model, "SpoX+ $cacheKey", $mysqlTime, $promptHash]
                );
                return $cacheName;
            }
        } else {
            log_error('cache_creation_failed', ['status' => $httpStatus, 'response' => $response]);
        }
    } catch (Throwable $e) {
        log_error('cache_helper_error', ['message' => $e->getMessage()]);
    }

    return null;
}

function build_gemini_messages(int|null $userId, string $chatUuid, array $history, string $newMessage, string $ragContext, array $fileNames = [], ?string &$cachedContentName = null, ?int $projectId = null, bool $maturaMode = false): array {
    $model = GEMINI_MODELS[0]; 
    
    // 1. Load Base Prompt (Identity, Tone, Rules)
    $basePath = __DIR__ . '/../data/system_prompt.txt';
    $systemPrompt = file_exists($basePath) ? file_get_contents($basePath) : "Du bist SpoX+ AI.";
    
    // 2. If Matura Mode, append Matura Context from file
    if ($maturaMode) {
        $maturaPath = __DIR__ . '/../data/matura_context.txt';
        if (file_exists($maturaPath)) {
            $systemPrompt .= "\n\n" . file_get_contents($maturaPath);
        }
        
        // Use context caching only for the heavy Matura prompt
        $cachedContentName = get_or_create_gemini_cache($model, $systemPrompt, 'matura_prompt');
    } else {
        $cachedContentName = null;
    }

    if ($ragContext) {
        $systemPrompt .= "\n\n" . $ragContext;
    }

    $contents = [];
    $recentHistory = array_slice($history, -20);
    foreach ($recentHistory as $msg) {
        $role = ($msg['sender'] ?? '') === 'user' ? 'user' : 'model';
        $contents[] = [
            'role'  => $role,
            'parts' => [['text' => sanitize_string($msg['content'] ?? '', 4000)]],
        ];
    }

    $messageText = $newMessage;
    if ($cachedContentName && $ragContext) {
        $messageText = $ragContext . "\n\n" . $newMessage;
    }
    $userParts = [['text' => $messageText]];

    if (!empty($fileNames) && $userId) {
        foreach ($fileNames as $fn) {
            $file = DB::query(
                'SELECT storage_path, mime_type FROM files WHERE filename = ? AND user_id = ? AND deleted_at IS NULL',
                [$fn, $userId]
            )->fetch();

            if ($file && file_exists($file['storage_path'])) {
                $supportedMimes = ['image/png', 'image/jpeg', 'image/webp', 'image/heic', 'image/heif', 'application/pdf'];
                if (in_array($file['mime_type'], $supportedMimes)) {
                    $userParts[] = [
                        'inline_data' => [
                            'mime_type' => $file['mime_type'],
                            'data'      => base64_encode(file_get_contents($file['storage_path']))
                        ]
                    ];
                }
            }
        }
    }

    $contents[] = [
        'role'  => 'user',
        'parts' => $userParts,
    ];

    $config = [
        'contents'           => $contents,
        'generationConfig'   => [
            'temperature'     => 0.7,
            'maxOutputTokens' => 2048,
            'topP'            => 0.95,
            'thinkingConfig' => ['thinkingBudget' => 128],
        ],
        'safetySettings' => [
            ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
        ],
    ];

    if (!$cachedContentName) {
        $config['system_instruction'] = ['parts' => [['text' => $systemPrompt]]];
    }

    return $config;
}

function stream_gemini(array $payload, callable $onToken, string $model, string $apiKey, ?string $cachedContentName = null): string {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:streamGenerateContent?alt=sse&key={$apiKey}";

    if ($cachedContentName) {
        $payload['cachedContent'] = $cachedContentName;
    }

    $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_BUFFERSIZE     => 1024,
    ]);

    $buffer      = '';
    $fullText    = '';
    $tokenBuffer = '';
    $lastFlush   = microtime(true);
    $isError     = false;
    $errorMessage = "";

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$buffer, &$fullText, &$tokenBuffer, &$lastFlush, &$isError, &$errorMessage, $onToken) {
        $buffer .= $data;

        // Peak into first chunks for error JSON
        if (!$isError && str_contains($buffer, '"error"')) {
             $parsed = json_decode($buffer, true);
             if (isset($parsed['error'])) {
                 $isError = true;
                 $errorMessage = ($parsed['error']['message'] ?? 'API Error') . " (" . ($parsed['error']['code'] ?? 'no code') . ")";
                 return 0; // Abort
             }
        }

        while (($pos = strpos($buffer, "\n")) !== false) {
            $line   = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);

            if (str_starts_with($line, 'data: ')) {
                $json = substr($line, 6);
                if ($json === '[DONE]') continue;
                try {
                    $parsed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                    $token  = $parsed['candidates'][0]['content']['parts'][0]['text'] ?? '';
                    if ($token !== '') {
                        $fullText    .= $token;
                        $tokenBuffer .= $token;
                        $now = microtime(true);
                        if (($now - $lastFlush) > 0.05 || strlen($tokenBuffer) > 128 || str_contains($token, "\n") || str_contains($token, '$')) {
                            $onToken($tokenBuffer);
                            $tokenBuffer = '';
                            $lastFlush   = $now;
                        }
                    }
                } catch (JsonException) {}
            }
        }
        return strlen($data);
    });

    curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($tokenBuffer !== '') {
        $onToken($tokenBuffer);
    }

    if ($isError || $httpStatus >= 400) {
        throw new Exception($errorMessage ?: "HTTP Error $httpStatus");
    }

    return $fullText;
}

function generate_chat_title(string $message): string {
    $title = mb_substr($message, 0, 60);
    if (mb_strlen($message) > 60) $title .= '…';
    return $title;
}

function log_gemini_call(int|null $userId, string $chatUuid, int $durationMs, ?string $error, string $model): void {
    try {
        $chat   = DB::query('SELECT id FROM chats WHERE uuid = ?', [$chatUuid])->fetch();
        $chatId = $chat['id'] ?? null;
        $status = $error ? 'error' : 'success';
        DB::query(
            'INSERT INTO gemini_calls (user_id, chat_id, model, duration_ms, status) VALUES (?, ?, ?, ?, ?)',
            [$userId, $chatId, $model, $durationMs, $status]
        );
    } catch (Throwable) {}
}
