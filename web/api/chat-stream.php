<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_runtime.php';

function meso_chat_stream_preflight_fail(Throwable $e): never {
    $status=meso_chat_error_status($e);
    $code=$e->getMessage();
    if($status>=500&&!in_array($code,['rate_limited','provider_not_configured','invalid_local_provider','curl_unavailable','rate_limit_unavailable','memory_unavailable','provider_connection_failed','provider_error','empty_provider_response'],true)) $code='internal_error';
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode(['ok'=>false,'error'=>$code],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function meso_chat_sse_event(string $event,array $payload): void {
    echo 'event: '.$event."\n";
    echo 'data: '.json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n\n";
    if(function_exists('ob_flush')) @ob_flush();
    @flush();
}

function meso_chat_stream_append(string &$final,string $delta): bool {
    if($delta==='') return true;
    if(meso_memory_text_length($final)+meso_memory_text_length($delta)>meso_chat_stream_limit()) return false;
    $final.=$delta;
    meso_chat_sse_event('delta',['text'=>$delta]);
    return true;
}

if($_SERVER['REQUEST_METHOD']!=='POST'){
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
    exit;
}
meso_chat_require_json_state_auth();
try { meso_chat_rate_limit_or_throw(); } catch(Throwable $e) { meso_chat_stream_preflight_fail($e); }

$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if($length<=0||$length>65536) meso_chat_stream_preflight_fail(new InvalidArgumentException('invalid_request_size'));
$body=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($body)) meso_chat_stream_preflight_fail(new InvalidArgumentException('invalid_json'));

try {
    $prepared=meso_chat_prepare_request($body);
    $context=meso_chat_context_for((string)$prepared['conversation_id'],(string)$prepared['message']);
    $userMessage=meso_chat_persist_user_turn($prepared);
    $cfg=meso_chat_provider_config();
} catch(InvalidArgumentException $e) {
    meso_chat_stream_preflight_fail($e);
} catch(Throwable $e) {
    $code=$e->getMessage();
    if(!in_array($code,['provider_not_configured','invalid_local_provider','rate_limited'],true)) $e=new RuntimeException('memory_unavailable',0,$e);
    meso_chat_stream_preflight_fail($e);
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');
while(ob_get_level()>0) @ob_end_flush();

$provider=(string)$cfg['provider'];
$model=(string)$cfg['model'];
$resultBase=meso_chat_result_base($prepared,$context,$userMessage);
meso_chat_sse_event('meta',array_merge($resultBase,['provider'=>$provider,'model'=>$model]));

$final='';
$responseModel=$model;
$completed=false;
$aborted=false;
$providerError='';

if(!function_exists('curl_init')){
    meso_chat_sse_event('error',['error'=>'curl_unavailable']);
    exit;
}

if($provider==='ollama'){
    $lineBuffer='';
    $payload=[
        'model'=>$model,
        'messages'=>meso_chat_ollama_messages($prepared,$context),
        'stream'=>true,
        'keep_alive'=>-1,
        'options'=>['num_predict'=>900],
    ];
    $ch=curl_init((string)$cfg['base_url'].'/api/chat');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_CONNECTTIMEOUT=>15,
        CURLOPT_TIMEOUT=>300,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/x-ndjson'],
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        CURLOPT_WRITEFUNCTION=>function($curl,string $chunk) use (&$lineBuffer,&$final,&$responseModel,&$completed,&$aborted,&$providerError): int {
            if(connection_aborted()){$aborted=true;return 0;}
            $lineBuffer.=$chunk;
            while(($pos=strpos($lineBuffer,"\n"))!==false){
                $line=trim(substr($lineBuffer,0,$pos));
                $lineBuffer=substr($lineBuffer,$pos+1);
                if($line==='') continue;
                $row=json_decode($line,true);
                if(!is_array($row)) continue;
                if(isset($row['error'])){$providerError='provider_error';return 0;}
                $delta=(string)($row['message']['content']??'');
                if($delta!==''&&!meso_chat_stream_append($final,$delta)){$providerError='provider_output_too_large';return 0;}
                if(($row['done']??false)===true){$completed=true;$responseModel=(string)($row['model']??$responseModel);}
            }
            return strlen($chunk);
        },
    ]);
    $ok=curl_exec($ch);
    $curlError=curl_error($ch);
    $httpStatus=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if(!$aborted&&$providerError===''&&($ok===false||$curlError!==''||$httpStatus<200||$httpStatus>=300)) $providerError='provider_connection_failed';
} else {
    $frameBuffer='';
    $payload=[
        'model'=>$model,
        'store'=>false,
        'stream'=>true,
        'instructions'=>(string)$context['instructions'],
        'input'=>meso_chat_openai_transcript($prepared),
        'max_output_tokens'=>900,
    ];
    $ch=curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_CONNECTTIMEOUT=>15,
        CURLOPT_TIMEOUT=>180,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.trim((string)$cfg['api_key']),'Content-Type: application/json','Accept: text/event-stream'],
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        CURLOPT_WRITEFUNCTION=>function($curl,string $chunk) use (&$frameBuffer,&$final,&$responseModel,&$completed,&$aborted,&$providerError): int {
            if(connection_aborted()){$aborted=true;return 0;}
            $frameBuffer.=str_replace("\r\n","\n",$chunk);
            while(($pos=strpos($frameBuffer,"\n\n"))!==false){
                $frame=substr($frameBuffer,0,$pos);
                $frameBuffer=substr($frameBuffer,$pos+2);
                $eventName='';$data='';
                foreach(explode("\n",$frame) as $line){
                    if(str_starts_with($line,'event:')) $eventName=trim(substr($line,6));
                    elseif(str_starts_with($line,'data:')) $data.=trim(substr($line,5));
                }
                if($data===''||$data==='[DONE]') continue;
                $row=json_decode($data,true);
                if(!is_array($row)) continue;
                $type=(string)($row['type']??$eventName);
                if($type==='response.output_text.delta'){
                    $delta=(string)($row['delta']??'');
                    if($delta!==''&&!meso_chat_stream_append($final,$delta)){$providerError='provider_output_too_large';return 0;}
                }elseif($type==='response.completed'){
                    $completed=true;
                    $responseModel=(string)($row['response']['model']??$responseModel);
                }elseif($type==='error'||$type==='response.failed'){
                    $providerError='provider_error';return 0;
                }
            }
            return strlen($chunk);
        },
    ]);
    $ok=curl_exec($ch);
    $curlError=curl_error($ch);
    $httpStatus=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if(!$aborted&&$providerError===''&&($ok===false||$curlError!==''||$httpStatus<200||$httpStatus>=300)) $providerError='provider_connection_failed';
}

if($aborted||connection_aborted()) exit;
if($providerError!==''){
    meso_chat_sse_event('error',['error'=>$providerError]);
    exit;
}
$final=trim($final);
if(!$completed||$final===''){
    meso_chat_sse_event('error',['error'=>$final===''?'empty_provider_response':'provider_incomplete']);
    exit;
}

try {
    $assistantMessage=meso_chat_persist_assistant_turn((string)$prepared['conversation_id'],$final,$provider,$responseModel,$context);
} catch(Throwable $e) {
    meso_chat_sse_event('error',['error'=>'memory_unavailable']);
    exit;
}
meso_chat_sse_event('done',array_merge($resultBase,[
    'message_id'=>(string)$assistantMessage['id'],
    'provider'=>$provider,
    'model'=>$responseModel,
]));
