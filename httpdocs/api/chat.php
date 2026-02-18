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
    $ragContext = build_rag_context($projectId, $message);
}

// Build Gemini request
$geminiMessages = build_gemini_messages($userId, $chatUuid, $history, $message, $ragContext, $fileNames);

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

// Call Gemini with streaming
$startTime    = microtime(true);
$fullResponse = '';
$error        = null;
$actualModel  = GEMINI_MODEL;

// Save user message to DB immediately
if ($userId) {
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

    // Auto-generate title from first message
    $title = generate_chat_title($message);
    DB::query('UPDATE chats SET title = ? WHERE uuid = ? AND title IS NULL', [$title, $chatUuid]);
}

try {
    $fullResponse = stream_gemini($geminiMessages, function(string $token) {
        send_sse(['type' => 'token', 'text' => $token]);
    }, $actualModel);
} catch (Throwable $e) {
    $error = $e->getMessage();
    log_error('gemini_error', ['message' => $e->getMessage()]);
    send_sse(['type' => 'error', 'message' => 'SpoX+ AI ist momentan überlastet oder nicht erreichbar. (Details: ' . $e->getMessage() . ')']);
    send_sse(['type' => 'done', 'chat_uuid' => $chatUuid]);
    exit;
}

$durationMs = (int)((microtime(true) - $startTime) * 1000);

// Save bot response to DB
if ($userId && !empty($fullResponse)) {
    save_message($chatUuid, 'assistant', $fullResponse);
}

// Log Gemini call
log_gemini_call($userId, $chatUuid, $durationMs, $error, $actualModel);

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

function ensure_chat_exists(string $uuid, int $userId, ?int $projectId): void {
    $existing = DB::query('SELECT id FROM chats WHERE uuid = ?', [$uuid])->fetch();
    if (!$existing) {
        DB::query(
            'INSERT INTO chats (user_id, project_id, uuid) VALUES (?, ?, ?)',
            [$userId, $projectId, $uuid]
        );
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

function build_rag_context(int $projectId, string $query): string {
    $files = DB::query(
        'SELECT extracted_text, filename FROM files WHERE project_id = ? AND extracted_text IS NOT NULL',
        [$projectId]
    )->fetchAll();

    if (empty($files)) return '';

    $context = "=== Projektdokumente (für Kontext) ===\n";
    foreach ($files as $file) {
        // Simple relevance: include first 2000 chars of each file
        $text = mb_substr($file['extracted_text'], 0, 2000);
        $context .= "\n--- {$file['filename']} ---\n$text\n";
    }
    $context .= "\n=== Ende der Dokumente ===\n";
    return $context;
}

function build_gemini_messages(int|null $userId, string $chatUuid, array $history, string $newMessage, string $ragContext, array $fileNames = []): array {
    $systemPrompt = "Du bist SpoX+ AI, der KI-Assistent des HAK Sport+ Programms an der BHAK & BHAS Steyr. "
        . "Das HAK Sport+ Programm richtet sich an bewegungsorientierte Schülerinnen und Schüler, die sich für Sport, Fitness, Teamarbeit und einen aktiven Lebensstil begeistern. "
        . "Sport+ ist auch ein eigenständiges Maturafach (Reife- und Diplomprüfung), daher hilfst du auch gezielt bei der Matura-Vorbereitung in Sport+. "
        . "Deine Kernthemen sind: Training und Trainingsplanung, Sporternährung, Sportveranstaltungen und Events, Teamarbeit und Leadership, Motivation und Mentale Stärke, kaufmännische Ausbildung mit Sportbezug, Matura-Vorbereitung Sport+. "
        . "Du antwortest auf Deutsch oder in der Sprache des Nutzers. "
        . "Du bist motivierend, präzise, sportlich und jugendgerecht. "
        . "Du gibst keine schädlichen, illegalen oder unangemessenen Inhalte aus. "
        . "Wenn du dir bei etwas nicht sicher bist, sagst du das ehrlich. "
        . "Dein Motto: 'Wer sich bewegt, kann auch etwas bewegen!'.";

    if ($ragContext) {
        $systemPrompt .= "\n\n" . $ragContext;
    }

    $contents = [];

    // Add history (last 20 messages to stay within context)
    $recentHistory = array_slice($history, -20);
    foreach ($recentHistory as $msg) {
        $role = ($msg['sender'] ?? '') === 'user' ? 'user' : 'model';
        $contents[] = [
            'role'  => $role,
            'parts' => [['text' => sanitize_string($msg['content'] ?? '', 4000)]],
        ];
    }

    // Prepare parts for the new user message
    $userParts = [['text' => $newMessage]];

    // Add multimodal files if any
    if (!empty($fileNames) && $userId) {
        foreach ($fileNames as $fn) {
            $file = DB::query(
                'SELECT storage_path, mime_type FROM files WHERE filename = ? AND user_id = ? AND deleted_at IS NULL',
                [$fn, $userId]
            )->fetch();

            if ($file && file_exists($file['storage_path'])) {
                // Gemini supports images and PDFs directly
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

    // Add new user message
    $contents[] = [
        'role'  => 'user',
        'parts' => $userParts,
    ];

    return [
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents'           => $contents,
        'generationConfig'   => [
            'temperature'     => 0.7,
            'maxOutputTokens' => 2048,
            'topP'            => 0.95,
        ],
        'safetySettings' => [
            ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
        ],
    ];
}

function stream_gemini(array $payload, callable $onToken, string &$actualModel): string {
    $model   = trim($actualModel);
    $apiKey  = trim(GEMINI_API_KEY);
    $url     = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:streamGenerateContent?alt=sse&key={$apiKey}";

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
        CURLOPT_BUFFERSIZE     => 1024, // Small buffer to process tokens immediately
    ]);

    $buffer      = '';
    $fullText    = '';
    $httpStatus  = 200;

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$buffer, &$fullText, $onToken) {
        $buffer .= $data;
        // Process SSE lines
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
                        $fullText .= $token;
                        $onToken($token);
                    }
                } catch (JsonException) { /* skip malformed */ }
            }
        }
        return strlen($data);
    });

    $result = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError  = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new RuntimeException('cURL error: ' . $curlError);
    }

    if ($httpStatus === 429 || $httpStatus >= 500) {
        // Try fallback model
        $actualModel = GEMINI_MODEL_FALLBACK;
        return stream_gemini_fallback($payload, $onToken);
    }

    if ($httpStatus >= 400) {
        throw new RuntimeException('Gemini API error: HTTP ' . $httpStatus);
    }

    return $fullText;
}

function stream_gemini_fallback(array $payload, callable $onToken): string {
    // Exponential backoff with fallback model
    $model  = trim(GEMINI_MODEL_FALLBACK);
    $apiKey = trim(GEMINI_API_KEY);
    $url    = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:streamGenerateContent?alt=sse&key={$apiKey}";

    // We removed the sleep(2) to improve first-token speed on fallback.

    $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_BUFFERSIZE     => 1024,
    ]);

    $buffer   = '';
    $fullText = '';

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$buffer, &$fullText, $onToken) {
        $buffer .= $data;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line   = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            if (str_starts_with($line, 'data: ')) {
                $json = substr($line, 6);
                try {
                    $parsed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                    $token  = $parsed['candidates'][0]['content']['parts'][0]['text'] ?? '';
                    if ($token !== '') { $fullText .= $token; $onToken($token); }
                } catch (JsonException) {}
            }
        }
        return strlen($data);
    });

    curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpStatus >= 400) {
        throw new RuntimeException('Gemini fallback model also failed: HTTP ' . $httpStatus);
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
