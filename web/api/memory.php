<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'memory.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function meso_memory_api_json(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function meso_memory_api_body(): array {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length <= 0 || $length > 16384) meso_memory_api_json(400, ['ok'=>false,'error'=>'invalid_request_size']);
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) meso_memory_api_json(400, ['ok'=>false,'error'=>'invalid_json']);
    return $body;
}

function meso_memory_api_error(Throwable $e): never {
    if ($e instanceof InvalidArgumentException) {
        $code = $e->getMessage();
        $status = in_array($code, ['conversation_not_found','memory_item_not_found','memory_message_not_found'], true) ? 404 : 400;
        meso_memory_api_json($status, ['ok'=>false,'error'=>$code]);
    }
    meso_memory_api_json(503, ['ok'=>false,'error'=>'memory_unavailable']);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    meso_chat_require_json_auth();
    $conversationId = trim((string)($_GET['conversation_id'] ?? ''));
    if ($conversationId !== '') {
        $conversationId = strtolower($conversationId);
        if (!meso_memory_valid_id($conversationId)) meso_memory_api_json(400, ['ok'=>false,'error'=>'invalid_conversation_id']);
    } else {
        $conversationId = null;
    }
    $status = trim((string)($_GET['status'] ?? ''));
    if ($status === '') $status = null;
    elseif (!in_array($status, ['candidate','verified','rejected'], true)) meso_memory_api_json(400, ['ok'=>false,'error'=>'invalid_memory_status']);
    $limit = filter_var($_GET['limit'] ?? 100, FILTER_VALIDATE_INT);
    if ($limit === false) meso_memory_api_json(400, ['ok'=>false,'error'=>'invalid_limit']);
    try {
        meso_memory_api_json(200, ['ok'=>true,'memory'=>'meso-memory-v1','items'=>meso_memory_list_items($conversationId, $status, (int)$limit)]);
    } catch (Throwable $e) { meso_memory_api_error($e); }
}

if (!in_array($method, ['POST','DELETE'], true)) {
    header('Allow: GET, POST, DELETE');
    meso_memory_api_json(405, ['ok'=>false,'error'=>'method_not_allowed']);
}
meso_chat_require_json_state_auth();
$body = meso_memory_api_body();
$action = strtolower(trim((string)($body['action'] ?? '')));

try {
    if ($method === 'POST') {
        if ($action === 'create') {
            $conversationId = strtolower(trim((string)($body['conversation_id'] ?? '')));
            if (!meso_memory_valid_id($conversationId)) meso_memory_api_json(400, ['ok'=>false,'error'=>'invalid_conversation_id']);
            $messageId = trim((string)($body['message_id'] ?? ''));
            $messageId = $messageId === '' ? null : strtolower($messageId);
            if ($messageId !== null && !meso_memory_valid_id($messageId)) meso_memory_api_json(400, ['ok'=>false,'error'=>'invalid_message_id']);
            $kind = strtolower(trim((string)($body['kind'] ?? 'fact')));
            $text = trim((string)($body['text'] ?? ''));
            $item = meso_memory_create_item($conversationId, $messageId, $kind, $text, 'verified', 'user-explicit-api');
            meso_memory_api_json(201, ['ok'=>true,'memory'=>'meso-memory-v1','item'=>$item]);
        }
        if (in_array($action, ['verify','reject'], true)) {
            $memoryId = strtolower(trim((string)($body['memory_id'] ?? '')));
            if (!meso_memory_valid_id($memoryId)) meso_memory_api_json(400, ['ok'=>false,'error'=>'invalid_memory_id']);
            $item = meso_memory_set_item_status($memoryId, $action === 'verify' ? 'verified' : 'rejected');
            meso_memory_api_json(200, ['ok'=>true,'memory'=>'meso-memory-v1','item'=>$item]);
        }
        meso_memory_api_json(400, ['ok'=>false,'error'=>'invalid_action']);
    }

    if ($action === 'item') {
        $memoryId = strtolower(trim((string)($body['memory_id'] ?? '')));
        if (!meso_memory_valid_id($memoryId)) meso_memory_api_json(400, ['ok'=>false,'error'=>'invalid_memory_id']);
        meso_memory_delete_item($memoryId);
        meso_memory_api_json(200, ['ok'=>true,'deleted'=>'item','memory_id'=>$memoryId]);
    }
    if ($action === 'conversation_memory') {
        $conversationId = strtolower(trim((string)($body['conversation_id'] ?? '')));
        if (!meso_memory_valid_id($conversationId)) meso_memory_api_json(400, ['ok'=>false,'error'=>'invalid_conversation_id']);
        meso_memory_clear_conversation_memory($conversationId);
        meso_memory_api_json(200, ['ok'=>true,'deleted'=>'conversation_memory','conversation_id'=>$conversationId]);
    }
    meso_memory_api_json(400, ['ok'=>false,'error'=>'invalid_action']);
} catch (Throwable $e) {
    meso_memory_api_error($e);
}
