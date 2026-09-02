<?php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'meso-memory-' . bin2hex(random_bytes(8)) . '.sqlite';
putenv('MESO_MEMORY_DB=' . $tmp);
require dirname(__DIR__) . '/web/includes/memory.php';

function ok(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}

try {
    $db = meso_memory_open();
    ok($db instanceof PDO, 'PDO open failed');

    $conv = meso_memory_create_conversation('First chat');
    ok(meso_memory_valid_id((string)($conv['id'] ?? '')), 'conversation id must be opaque hex');

    $user = meso_memory_add_message($conv['id'], 'user', 'Remember that I prefer concise answers.');
    $assistant = meso_memory_add_message($conv['id'], 'assistant', 'Understood.');
    ok(meso_memory_valid_id((string)($user['id'] ?? '')) && meso_memory_valid_id((string)($assistant['id'] ?? '')), 'message ids invalid');

    $candidate = meso_memory_add_item($conv['id'], $user['id'], 'preference', 'User prefers concise answers.', 'candidate');
    ok(count(meso_memory_retrieve_verified('concise answers')) === 0, 'candidate must not be recalled');

    $verified = meso_memory_set_item_status($candidate['id'], 'verified');
    ok(($verified['status'] ?? '') === 'verified', 'verification failed');
    ok(count(meso_memory_retrieve_verified('concise answers')) === 1, 'verified memory not recalled');

    $messages = meso_memory_get_messages($conv['id'], 10);
    ok(count($messages) === 2, 'message count wrong');
    ok(($messages[0]['role'] ?? '') === 'user' && ($messages[1]['role'] ?? '') === 'assistant', 'message order wrong');

    $cleared = meso_memory_clear_items($conv['id']);
    ok($cleared === 1, 'clear count wrong');
    ok(count(meso_memory_get_messages($conv['id'], 10)) === 2, 'clear memory must preserve transcript');

    ok(meso_memory_delete_conversation($conv['id']) === true, 'conversation delete failed');
    ok(count(meso_memory_get_messages($conv['id'], 10)) === 0, 'deleted transcript remained readable');

    echo "MESO_MEMORY_V1_REPOSITORY=PASS\n";
} finally {
    @unlink($tmp);
    @unlink($tmp . '-wal');
    @unlink($tmp . '-shm');
}
