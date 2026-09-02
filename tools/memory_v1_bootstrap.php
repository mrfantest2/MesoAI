<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli_only\n");
    exit(2);
}

require dirname(__DIR__) . '/web/includes/chat_auth.php';
require dirname(__DIR__) . '/web/includes/memory.php';

try {
    $db = meso_memory_db();
    $schema = meso_memory_schema_version($db);
    if ($schema !== MESO_MEMORY_SCHEMA_VERSION) {
        throw new RuntimeException('memory_schema_mismatch');
    }

    $payload = [
        'ok' => true,
        'memory' => 'meso-memory-v1',
        'schema' => $schema,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('memory_status_encode_failed');
    fwrite(STDOUT, $json . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    $payload = [
        'ok' => false,
        'memory' => 'meso-memory-v1',
        'error' => $e->getMessage(),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    fwrite(STDERR, ($json === false ? '{"ok":false,"memory":"meso-memory-v1","error":"bootstrap_failed"}' : $json) . PHP_EOL);
    exit(1);
}
