<?php
declare(strict_types=1);

require dirname(__DIR__) . '/web/includes/chat_auth.php';
require dirname(__DIR__) . '/web/includes/memory.php';

function expect_true(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function rrmdir(string $path): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $name;
        is_dir($child) ? rrmdir($child) : @unlink($child);
    }
    @rmdir($path);
}

expect_true(function_exists('meso_chat_state_request_allowed'), 'State-request guard helper is missing');
$stateServer = [
    'CONTENT_TYPE'=>'application/json; charset=utf-8',
    'HTTP_SEC_FETCH_SITE'=>'same-origin',
    'HTTP_HOST'=>'fantest.win',
    'HTTPS'=>'on',
    'HTTP_ORIGIN'=>'https://fantest.win',
];
expect_true(meso_chat_state_request_allowed($stateServer), 'Valid same-origin JSON state request was rejected');
expect_true(!meso_chat_state_request_allowed($stateServer + ['HTTP_SEC_FETCH_SITE'=>'cross-site']), 'Cross-site state request was accepted');
expect_true(!meso_chat_state_request_allowed(array_merge($stateServer, ['CONTENT_TYPE'=>'text/plain'])), 'Non-JSON state request was accepted');
expect_true(!meso_chat_state_request_allowed(array_merge($stateServer, ['HTTP_ORIGIN'=>'https://evil.example'])), 'Foreign Origin state request was accepted');

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'meso-memory-v1-' . bin2hex(random_bytes(8));
$memoryRoot = $base . DIRECTORY_SEPARATOR . 'memory-v1';
putenv('MESO_MEMORY_ROOT=' . $memoryRoot);
@mkdir($memoryRoot, 0700, true);

try {
    $db = meso_memory_db();
    expect_true($db instanceof PDO, 'Memory DB did not open');
    expect_true(meso_memory_schema_version($db) === 1, 'Memory schema version must be 1');
    expect_true(str_ends_with(meso_memory_db_path(), 'meso-memory.sqlite'), 'Unexpected DB filename');

    $conversation = meso_memory_create_conversation('First private chat');
    expect_true(preg_match('/^[a-f0-9]{64}$/', $conversation['id']) === 1, 'Conversation ID is not opaque');

    $user = meso_memory_add_message($conversation['id'], 'user', 'Please remember that my test drink is cardamom coffee.');
    $assistant = meso_memory_add_message($conversation['id'], 'assistant', 'I will keep that as conversation memory.', [
        'provider'=>'ollama',
        'model'=>'test',
        'persona_version'=>'meso-v2',
        'persona_grounding'=>'evidence-retrieval',
        'persona_evidence_count'=>2,
    ]);
    expect_true(preg_match('/^[a-f0-9]{64}$/', $user['id']) === 1, 'User message ID invalid');
    expect_true(preg_match('/^[a-f0-9]{64}$/', $assistant['id']) === 1, 'Assistant message ID invalid');

    $explicit = meso_memory_extract_explicit_remember('Please remember that my test drink is cardamom coffee.');
    expect_true($explicit === 'my test drink is cardamom coffee.', 'Explicit remember extraction failed');
    $verified = meso_memory_create_item($conversation['id'], $user['id'], 'fact', $explicit, 'verified', 'user-explicit-chat');
    expect_true($verified['status'] === 'verified', 'Explicit memory was not verified');

    $candidate = meso_memory_create_item($conversation['id'], $user['id'], 'preference', 'candidate-only-token', 'candidate', 'user-derived');
    $before = meso_memory_context($conversation['id'], 'candidate-only-token', 6);
    expect_true($before['items_used'] === 0, 'Candidate memory leaked into recall');
    meso_memory_set_item_status($candidate['id'], 'verified');
    $after = meso_memory_context($conversation['id'], 'candidate-only-token', 6);
    expect_true($after['items_used'] === 1, 'Verified memory was not recalled');

    $blocked = false;
    try {
        meso_memory_create_item($conversation['id'], $assistant['id'], 'fact', 'assistant invented fact', 'verified', 'user-derived');
    } catch (InvalidArgumentException $e) {
        $blocked = $e->getMessage() === 'assistant_memory_not_verifiable';
    }
    expect_true($blocked, 'Assistant output became verified memory');

    $second = meso_memory_create_conversation('Second private chat');
    $secondUser = meso_memory_add_message($second['id'], 'user', 'Please remember that cross-chat-token means cedar.');
    meso_memory_create_item($second['id'], $secondUser['id'], 'fact', 'cross-chat-token means cedar.', 'verified', 'user-explicit-chat');
    $cross = meso_memory_context($conversation['id'], 'cross-chat-token', 6);
    expect_true($cross['items_used'] >= 1, 'Verified memory from another active conversation was not recalled');

    $messages = meso_memory_list_messages($conversation['id'], 100, null);
    expect_true(count($messages['items']) === 2, 'Transcript persistence failed');
    expect_true($messages['items'][0]['role'] === 'user' && $messages['items'][1]['role'] === 'assistant', 'Transcript ordering failed');

    $sentinelDir = $base . DIRECTORY_SEPARATOR . 'persona-v2';
    @mkdir($sentinelDir, 0700, true);
    $sentinel = $sentinelDir . DIRECTORY_SEPARATOR . 'sentinel.txt';
    file_put_contents($sentinel, 'persona-immutable');
    $sentinelHash = hash_file('sha256', $sentinel);
    meso_memory_clear_conversation_memory($conversation['id']);
    expect_true(hash_equals((string)$sentinelHash, (string)hash_file('sha256', $sentinel)), 'Memory operation touched Persona storage');

    $archive = meso_memory_update_conversation($conversation['id'], null, true);
    expect_true($archive['archived'] === true, 'Archive failed');
    $unarchive = meso_memory_update_conversation($conversation['id'], 'Renamed chat', false);
    expect_true($unarchive['archived'] === false && $unarchive['title'] === 'Renamed chat', 'Rename/unarchive failed');

    meso_memory_delete_transcript($conversation['id']);
    expect_true(count(meso_memory_list_messages($conversation['id'], 100, null)['items']) === 0, 'Transcript delete failed');

    $future = $base . DIRECTORY_SEPARATOR . 'future.sqlite';
    $futureDb = new PDO('sqlite:' . $future);
    $futureDb->exec('PRAGMA user_version=2');
    $futureDb = null;
    $failed = false;
    try {
        meso_memory_connect($future);
    } catch (RuntimeException $e) {
        $failed = $e->getMessage() === 'memory_schema_newer_than_app';
    }
    expect_true($failed, 'Newer schema was not rejected');

    echo "MESO_MEMORY_V1_CONTRACT=PASS\n";
} finally {
    putenv('MESO_MEMORY_ROOT');
    rrmdir($base);
}
