<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'memory.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function meso_messages_json(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    meso_messages_json(405, ['ok'=>false,'error'=>'method_not_allowed']);
}
meso_chat_require_json_auth();

$conversationId = strtolower(trim((string)($_GET['conversation_id'] ?? '')));
if (!meso_memory_valid_id($conversationId)) meso_messages_json(400, ['ok'=>false,'error'=>'invalid_conversation_id']);
$beforeMessageId = trim((string)($_GET['before_message_id'] ?? ''));
if ($beforeMessageId !== '') {
    $beforeMessageId = strtolower($beforeMessageId);
    if (!meso_memory_valid_id($beforeMessageId)) meso_messages_json(400, ['ok'=>false,'error'=>'invalid_before_message_id']);
} else {
    $beforeMessageId = null;
}
$limit = filter_var($_GET['limit'] ?? 100, FILTER_VALIDATE_INT);
if ($limit === false) meso_messages_json(400, ['ok'=>false,'error'=>'invalid_limit']);

try {
    $page = meso_memory_list_messages($conversationId, (int)$limit, $beforeMessageId);
    meso_messages_json(200, [
        'ok'=>true,
        'conversation_id'=>$conversationId,
        'items'=>$page['items'],
        'next_before_message_id'=>$page['next_before_message_id'],
        'memory'=>'meso-memory-v1',
    ]);
} catch (InvalidArgumentException $e) {
    $status = $e->getMessage() === 'conversation_not_found' ? 404 : 400;
    meso_messages_json($status, ['ok'=>false,'error'=>$e->getMessage()]);
} catch (Throwable $e) {
    meso_messages_json(503, ['ok'=>false,'error'=>'memory_unavailable']);
}
