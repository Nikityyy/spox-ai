<?php
require_once __DIR__ . '/httpdocs/api/helpers.php';

try {
    $db = DB::get();
    echo "--- Files Table Schema ---\n";
    $cols = $db->query("SHOW COLUMNS FROM files")->fetchAll();
    foreach ($cols as $col) {
        echo "{$col['Field']} - {$col['Type']}\n";
    }

    echo "\n--- Latest 10 Error Logs ---\n";
    $logs = $db->query("SELECT * FROM error_logs ORDER BY created_at DESC LIMIT 10")->fetchAll();
    foreach ($logs as $log) {
        echo "[{$log['created_at']}] {$log['level']}: {$log['message']} - {$log['context']}\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
