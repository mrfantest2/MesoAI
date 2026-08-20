<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_auth.php';

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function meso_voice_lab_json(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Allow: POST');
    meso_voice_lab_json(405, ['ok'=>false,'error'=>'method_not_allowed']);
    exit;
}
meso_chat_require_json_auth();

$length=(int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if($length<=0 || $length>4096){meso_voice_lab_json(400,['ok'=>false,'error'=>'invalid_request_size']);exit;}
$body=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($body)){meso_voice_lab_json(400,['ok'=>false,'error'=>'invalid_json']);exit;}
$label=strtoupper(trim((string)($body['label'] ?? '')));
$phraseId=trim((string)($body['phrase_id'] ?? ''));
if(!in_array($label,['A','B','C','D'],true)){meso_voice_lab_json(400,['ok'=>false,'error'=>'invalid_label']);exit;}

$phrases=[
    'ar-casual'=>['language'=>'ar','text'=>'مرحبا، كيفك اليوم؟ شو أخبارك؟ خبرني شو صار معك.'],
    'ar-warm'=>['language'=>'ar','text'=>'والله اشتقتلك، احكيلي شوي عن يومك وكيف كانت أمورك.'],
    'en-casual'=>['language'=>'en','text'=>'Hey, how are you today? Tell me what happened and how your day went.'],
    'mixed'=>['language'=>'ar','text'=>'شو الأخبار؟ I hope your day was good. احكيلي شو صار معك.'],
];
$phrase=$phrases[$phraseId] ?? null;
if(!is_array($phrase)){meso_voice_lab_json(400,['ok'=>false,'error'=>'invalid_phrase']);exit;}

$root=meso_private_root().'\\voice-lab';
$ready=$root.'\\ready';
$python='C:\\ProgramData\\KhalilDigitalTwin\\meso\\xtts-venv\\Scripts\\python.exe';
$helper='C:\\ProgramData\\KhalilDigitalTwin\\meso\\xtts-bridge\\meso_xtts_lab_client.py';
if(!is_file($python)||!is_file($helper)){meso_voice_lab_json(503,['ok'=>false,'error'=>'voice_lab_unavailable']);exit;}
if(!is_dir($ready) && !@mkdir($ready,0700,true) && !is_dir($ready)){meso_voice_lab_json(503,['ok'=>false,'error'=>'voice_lab_unavailable']);exit;}

$cutoff=time()-3600;
foreach(glob($ready.'\\*.mp3')?:[] as $file){$mtime=@filemtime($file);if($mtime!==false&&$mtime<$cutoff){@unlink($file);@unlink(substr($file,0,-4).'.json');}}

$token=bin2hex(random_bytes(32));
$temp=$ready.'\\tmp-'.$token.'.mp3';
$published=$ready.'\\'.$token.'.mp3';
$metaPath=$ready.'\\'.$token.'.json';
$lock=@fopen($root.'\\lab.lock','c+');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))fclose($lock);meso_voice_lab_json(429,['ok'=>false,'error'=>'voice_lab_busy']);exit;}
try{
    @set_time_limit(305);
    $pipes=[];
    $proc=@proc_open([$python,$helper],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($proc))throw new RuntimeException('process_start_failed');
    fwrite($pipes[0],json_encode(['text'=>$phrase['text'],'language'=>$phrase['language'],'label'=>$label,'output'=>$temp],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));fclose($pipes[0]);
    $stdout=(string)stream_get_contents($pipes[1]);fclose($pipes[1]);
    $stderr=(string)stream_get_contents($pipes[2]);fclose($pipes[2]);
    $exit=proc_close($proc);
    if($exit!==0 || !is_file($temp))throw new RuntimeException('synthesis_failed');
    $meta=json_decode(trim($stdout),true);
    if(!is_array($meta)||($meta['ok']??false)!==true||($meta['engine']??'')!=='xtts-v2'||($meta['lab']??'')!==$label||($meta['format']??'')!=='mp3')throw new RuntimeException('invalid_lab_output');
    $size=filesize($temp);if($size===false||$size<1024||$size>8388608)throw new RuntimeException('invalid_mp3_size');
    if(!@rename($temp,$published))throw new RuntimeException('publish_failed');
    $mediaMeta=json_encode(['label'=>$label,'phrase_id'=>$phraseId,'created_at'=>time()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($mediaMeta===false||@file_put_contents($metaPath,$mediaMeta,LOCK_EX)===false)throw new RuntimeException('metadata_failed');
    meso_voice_lab_json(200,['ok'=>true,'label'=>$label,'phrase_id'=>$phraseId,'audio_url'=>'/meso/api/voice-lab-audio.php?id='.$token,'expires_in'=>3600]);
}catch(Throwable $e){
    @unlink($temp);@unlink($published);@unlink($metaPath);
    meso_voice_lab_json(503,['ok'=>false,'error'=>'voice_lab_unavailable']);
}finally{
    flock($lock,LOCK_UN);fclose($lock);
}
