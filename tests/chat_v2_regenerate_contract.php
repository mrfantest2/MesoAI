<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'meso-chat-v2-' . bin2hex(random_bytes(6));
putenv('MESO_MEMORY_ROOT=' . $root);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'memory.php';

function meso_chat_v2_assert(bool $condition, string $message): void {
    if ($condition) return;
    fwrite(STDERR, "CHAT_V2_REGENERATE_FAIL: {$message}\n");
    exit(1);
}

function meso_chat_v2_remove_tree(string $path): void {
    if (!is_dir($path)) return;
    $items = scandir($path);
    if (!is_array($items)) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child)) meso_chat_v2_remove_tree($child); else @unlink($child);
    }
    @rmdir($path);
}

try {
    meso_chat_v2_assert(function_exists('meso_memory_get_message'), 'meso_memory_get_message is missing');

    $conversation = meso_memory_create_conversation('Regeneration contract');
    $user = meso_memory_add_message($conversation['id'], 'user', 'Original persisted user turn');
    meso_memory_add_message($conversation['id'], 'assistant', 'First assistant reply');

    $found = meso_memory_get_message($conversation['id'], $user['id']);
    meso_chat_v2_assert(is_array($found), 'original user message was not found');
    meso_chat_v2_assert(($found['id'] ?? '') === $user['id'], 'message identity changed');
    meso_chat_v2_assert(($found['role'] ?? '') === 'user', 'message role changed');
    meso_chat_v2_assert(($found['content'] ?? '') === 'Original persisted user turn', 'message content changed');

    $before = meso_memory_list_messages($conversation['id'], 12, $user['id']);
    meso_chat_v2_assert(($before['items'] ?? null) === [], 'regeneration history must be strictly before the original user turn');

    $missing = meso_memory_get_message($conversation['id'], str_repeat('a', 64));
    meso_chat_v2_assert($missing === null, 'unknown message id must return null');

    echo "MESO_CHAT_V2_REGENERATE_CONTRACT=PASS\n";
} finally {
    meso_chat_v2_remove_tree($root);
}
