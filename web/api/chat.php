<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_runtime.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function meso_chat_json_fail(Throwable $e): never {
    $status=meso_chat_error_status($e);
    $code=$e->getMessage();
    $safe=[
        'invalid_conversation_id','conversation_not_found','conversation_archived','invalid_message',
        'invalid_regenerate_message_id','regenerate_message_not_found','regenerate_message_not_user',
        'rate_limited','provider_not_configured','invalid_local_provider','curl_unavailable',
        'rate_limit_unavailable','memory_unavailable','provider_connection_failed','provider_error','empty_provider_response'
    ];
    if(!in_array($code,$safe,true)) $code=$status>=500?'internal_error':'invalid_request';
    http_response_code($status);
    $body=['ok'=>false,'error'=>$code];
    $message=meso_chat_error_public_message($e);
    if($message!=='') $body['message']=$message;
    echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

if($_SERVER['REQUEST_METHOD']!=='POST'){
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
    exit;
}
meso_chat_require_json_state_auth();
try { meso_chat_rate_limit_or_throw(); } catch(Throwable $e) { meso_chat_json_fail($e); }

$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if($length<=0||$length>65536) meso_chat_json_fail(new InvalidArgumentException('invalid_request_size'));
$body=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($body)) meso_chat_json_fail(new InvalidArgumentException('invalid_json'));

try {
    $prepared=meso_chat_prepare_request($body);
    $context=meso_chat_context_for((string)$prepared['conversation_id'],(string)$prepared['message']);
    $userMessage=meso_chat_persist_user_turn($prepared);
} catch(InvalidArgumentException $e) {
    meso_chat_json_fail($e);
} catch(Throwable $e) {
    meso_chat_json_fail(new RuntimeException('memory_unavailable',0,$e));
}

try {
    $cfg=meso_chat_provider_config();
    $provider=(string)$cfg['provider'];
    $model=(string)$cfg['model'];
    $reply='';
    $responseModel=$model;

    if($provider==='ollama') {
        $response=meso_chat_curl_json(
            (string)$cfg['base_url'].'/api/chat',
            [
                'model'=>$model,
                'messages'=>meso_chat_ollama_messages($prepared,$context),
                'stream'=>false,
                'keep_alive'=>-1,
                'options'=>['num_predict'=>900],
            ],
            ['Content-Type: application/json','Accept: application/json'],
            300
        );
        $reply=trim((string)($response['message']['content']??''));
        $responseModel=(string)($response['model']??$model);
    } else {
        $response=meso_chat_curl_json(
            'https://api.openai.com/v1/responses',
            [
                'model'=>$model,
                'store'=>false,
                'instructions'=>(string)$context['instructions'],
                'input'=>meso_chat_openai_transcript($prepared),
                'max_output_tokens'=>900,
            ],
            ['Authorization: Bearer '.trim((string)$cfg['api_key']),'Content-Type: application/json','Accept: application/json'],
            90
        );
        $reply=meso_chat_openai_extract_text($response);
        $responseModel=(string)($response['model']??$model);
    }
    if($reply==='') throw new RuntimeException('empty_provider_response');
    $assistantMessage=meso_chat_persist_assistant_turn((string)$prepared['conversation_id'],$reply,$provider,$responseModel,$context);
    $resultBase=meso_chat_result_base($prepared,$context,$userMessage);
    echo json_encode(array_merge([
        'ok'=>true,
        'reply'=>$reply,
        'provider'=>$provider,
        'model'=>$responseModel,
        'message_id'=>$assistantMessage['id'],
    ],$resultBase),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
} catch(InvalidArgumentException $e) {
    meso_chat_json_fail($e);
} catch(Throwable $e) {
    meso_chat_json_fail($e);
}
