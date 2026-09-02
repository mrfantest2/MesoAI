<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'memory.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function meso_conversations_json(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function meso_conversations_body(): array {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length <= 0 || $length > 16384) meso_conversations_json(400, ['ok'=>false,'error'=>'invalid_request_size']);
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) meso_conversations_json(400, ['ok'=>false,'error'=>'invalid_json']);
    return $body;
}

function meso_conversations_error(Throwable $e): never {
    $code = $e->getMessage();
    if ($e instanceof InvalidArgumentException) {
        $status = $code === 'conversation_not_found' ? 404 : ($code === 'conversation_archived' ? 409 : 400);
        meso_conversations_json($status, ['ok'=>false,'error'=>$code]);
    }
    meso_conversations_json(503, ['ok'=>false,'error'=>'memory_unavailable']);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    meso_chat_require_json_auth();
    $archivedRaw = trim((string)($_GET['archived'] ?? '0'));
    if (!in_array($archivedRaw, ['0','1'], true)) meso_conversations_json(400, ['ok'=>false,'error'=>'invalid_archived']);
    $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT);
    if ($limit === false) meso_conversations_json(400, ['ok'=>false,'error'=>'invalid_limit']);
    try {
        meso_conversations_json(200, [
            'ok'=>true,
            'archived'=>$archivedRaw === '1',
            'items'=>meso_memory_list_conversations($archivedRaw === '1', (int)$limit),
            'memory'=>'meso-memory-v1',
        ]);
    } catch (Throwable $e) { meso_conversations_error($e); }
}

if (!in_array($method, ['POST','PATCH','DELETE'], true)) {
    header('Allow: GET, POST, PATCH, DELETE');
    meso_conversations_json(405, ['ok'=>false,'error'=>'method_not_allowed']);
}
meso_chat_require_json_state_auth();
$body = meso_conversations_body();

try {
    if ($method === 'POST') {
        $title = is_string($body['title'] ?? null) ? (string)$body['title'] : 'New conversation';
        meso_conversations_json(201, ['ok'=>true,'conversation'=>meso_memory_create_conversation($title),'memory'=>'meso-memory-v1']);
    }

    $conversationId = strtolower(trim((string)($body['conversation_id'] ?? '')));
    if (!meso_memory_valid_id($conversationId)) meso_conversations_json(400, ['ok'=>false,'error'=>'invalid_conversation_id']);

    if ($method === 'PATCH') {
        $title = array_key_exists('title', $body) && is_string($body['title']) ? (string)$body['title'] : null;
        $archived = array_key_exists('archived', $body) && is_bool($body['archived']) ? (bool)$body['archived'] : null;
        if ($title === null && $archived === null) meso_conversations_json(400, ['ok'=>false,'error'=>'no_changes']);
        meso_conversations_json(200, ['ok'=>true,'conversation'=>meso_memory_update_conversation($conversationId, $title, $archived)]);
    }

    $mode = strtolower(trim((string)($body['mode'] ?? 'conversation')));
    if ($mode === 'transcript') {
        meso_memory_delete_transcript($conversationId);
        meso_conversations_json(200, ['ok'=>true,'deleted'=>'transcript','conversation_id'=>$conversationId]);
    }
    if ($mode !== 'conversation') meso_conversations_json(400, ['ok'=>false,'error'=>'invalid_delete_mode']);
    meso_memory_delete_conversation($conversationId);
    meso_conversations_json(200, ['ok'=>true,'deleted'=>'conversation','conversation_id'=>$conversationId]);
} catch (Throwable $e) {
    meso_conversations_error($e);
}
