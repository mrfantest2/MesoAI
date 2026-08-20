<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_auth.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'persona.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
    exit;
}
meso_chat_require_json_auth();
$status = meso_persona_status();
echo json_encode([
    'ok'=>true,
    'enabled'=>(bool)($status['enabled']??false),
    'version'=>(string)($status['version']??'off'),
    'grounding'=>(string)($status['grounding']??'off'),
    'source_count'=>(int)($status['source_count']??0),
    'record_count'=>(int)($status['record_count']??0),
    'memory'=>'off',
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
