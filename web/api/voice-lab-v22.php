<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_auth.php';
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
function meso_v22_json(int $status,array $body):void{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST'){header('Allow: POST');meso_v22_json(405,['ok'=>false,'error'=>'method_not_allowed']);exit;}
meso_chat_require_json_auth();
$length=(int)($_SERVER['CONTENT_LENGTH']??0);if($length<=0||$length>4096){meso_v22_json(400,['ok'=>false,'error'=>'invalid_request_size']);exit;}
$body=json_decode((string)file_get_contents('php://input'),true);if(!is_array($body)){meso_v22_json(400,['ok'=>false,'error'=>'invalid_json']);exit;}
$action=strtolower(trim((string)($body['action']??'synthesize')));
$root=meso_private_root().'\\voice-lab-v22';$mapPath=$root.'\\'.'sweep'.'.json';$ready=$root.'\\ready';
$map=is_file($mapPath)?json_decode((string)file_get_contents($mapPath),true):null;$batches=is_array($map)&&isset($map['batches'])&&is_array($map['batches'])?$map['batches']:null;
if(!is_array($batches)||count($batches)<1){meso_v22_json(503,['ok'=>false,'error'=>'voice_sweep_unavailable']);exit;}
if($action==='status'){meso_v22_json(200,['ok'=>true,'version'=>'meso-v2.2','batch_count'=>count($batches),'labels'=>['A','B','C','D','E']]);exit;}
$batch=filter_var($body['batch']??null,FILTER_VALIDATE_INT);if($batch===false||$batch<0||$batch>=count($batches)){meso_v22_json(400,['ok'=>false,'error'=>'invalid_batch']);exit;}
if($action==='vote'){
    $choice=strtoupper(trim((string)($body['choice']??'')));if($choice!=='REJECT'&&!in_array($choice,['A','B','C','D','E'],true)){meso_v22_json(400,['ok'=>false,'error'=>'invalid_choice']);exit;}
    if(!is_dir($root)&&!@mkdir($root,0700,true)&&!is_dir($root)){meso_v22_json(503,['ok'=>false,'error'=>'voice_sweep_unavailable']);exit;}
    $row=json_encode(['at'=>time(),'batch'=>$batch,'choice'=>strtolower($choice)==='reject'?'reject':$choice],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
    if($row===false||@file_put_contents($root.'\\votes.jsonl',$row,FILE_APPEND|LOCK_EX)===false){meso_v22_json(503,['ok'=>false,'error'=>'vote_failed']);exit;}
    meso_v22_json(200,['ok'=>true,'vote'=>true,'batch'=>$batch,'choice'=>strtolower($choice)==='reject'?'reject':$choice]);exit;
}
if($action!=='synthesize'){meso_v22_json(400,['ok'=>false,'error'=>'invalid_action']);exit;}
$label=strtoupper(trim((string)($body['label']??'')));$phraseId=trim((string)($body['phrase_id']??'ar-casual'));if(!in_array($label,['A','B','C','D','E'],true)){meso_v22_json(400,['ok'=>false,'error'=>'invalid_label']);exit;}
$phrases=[
'ar-casual'=>['language'=>'ar','text'=>'مرحبا، كيفك اليوم؟ شو أخبارك؟ خبرني شو صار معك.'],
'ar-warm'=>['language'=>'ar','text'=>'والله اشتقتلك، احكيلي شوي عن يومك وكيف كانت أمورك.'],
'en-casual'=>['language'=>'en','text'=>'Hey, how are you today? Tell me what happened and how your day went.']];
$phrase=$phrases[$phraseId]??null;if(!is_array($phrase)){meso_v22_json(400,['ok'=>false,'error'=>'invalid_phrase']);exit;}
$python='C:\\ProgramData\\KhalilDigitalTwin\\meso\\xtts-venv\\Scripts\\python.exe';$helper='C:\\ProgramData\\KhalilDigitalTwin\\meso\\xtts-bridge\\meso_xtts_sweep_client.py';
if(!is_file($python)||!is_file($helper)){meso_v22_json(503,['ok'=>false,'error'=>'voice_sweep_unavailable']);exit;}if(!is_dir($ready)&&!@mkdir($ready,0700,true)&&!is_dir($ready)){meso_v22_json(503,['ok'=>false,'error'=>'voice_sweep_unavailable']);exit;}
$cutoff=time()-3600;foreach(glob($ready.'\\*.mp3')?:[] as $file){$mtime=@filemtime($file);if($mtime!==false&&$mtime<$cutoff){@unlink($file);@unlink(substr($file,0,-4).'.json');}}
$token=bin2hex(random_bytes(32));$temp=$ready.'\\tmp-'.$token.'.mp3';$published=$ready.'\\'.$token.'.mp3';$metaPath=$ready.'\\'.$token.'.json';$lock=@fopen($root.'\\sweep.lock','c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))fclose($lock);meso_v22_json(429,['ok'=>false,'error'=>'voice_sweep_busy']);exit;}
try{@set_time_limit(305);$pipes=[];$proc=@proc_open([$python,$helper],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($proc))throw new RuntimeException('process_start_failed');fwrite($pipes[0],json_encode(['text'=>$phrase['text'],'language'=>$phrase['language'],'label'=>$label,'batch'=>$batch,'output'=>$temp],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));fclose($pipes[0]);$stdout=(string)stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($proc);if($exit!==0||!is_file($temp))throw new RuntimeException('synthesis_failed');$meta=json_decode(trim($stdout),true);if(!is_array($meta)||($meta['ok']??false)!==true||($meta['engine']??'')!=='xtts-v2'||($meta['sweep']??'')!=='meso-v2.2'||($meta['label']??'')!==$label||(int)($meta['batch']??-1)!==$batch||(int)($meta['references']??0)!==1)throw new RuntimeException('invalid_output');$size=filesize($temp);if($size===false||$size<1024||$size>8388608)throw new RuntimeException('invalid_mp3_size');if(!@rename($temp,$published))throw new RuntimeException('publish_failed');$mediaMeta=json_encode(['label'=>$label,'batch'=>$batch,'phrase_id'=>$phraseId,'created_at'=>time()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($mediaMeta===false||@file_put_contents($metaPath,$mediaMeta,LOCK_EX)===false)throw new RuntimeException('metadata_failed');meso_v22_json(200,['ok'=>true,'label'=>$label,'batch'=>$batch,'phrase_id'=>$phraseId,'audio_url'=>'/meso/api/voice-lab-v22-audio.php?id='.$token,'expires_in'=>3600]);}
catch(Throwable $e){@unlink($temp);@unlink($published);@unlink($metaPath);meso_v22_json(503,['ok'=>false,'error'=>'voice_sweep_unavailable']);}
finally{flock($lock,LOCK_UN);fclose($lock);}
