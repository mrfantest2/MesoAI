<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_auth.php';
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function meso_v24_json(int $status,array $body):void{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
function meso_v24_lane(array $lanes,string $id):?array{
    foreach($lanes as $lane){
        if(is_array($lane)&&isset($lane['id'])&&hash_equals((string)$lane['id'],$id)){return $lane;}
    }
    return null;
}
function meso_v24_private_file(string $path,string $root):bool{
    $real=@realpath($path);$base=@realpath($root);
    if($real===false||$base===false||!is_file($real)){return false;}
    $realCmp=strtolower(str_replace('/','\\',$real));
    $baseCmp=rtrim(strtolower(str_replace('/','\\',$base)),'\\').'\\';
    return str_starts_with($realCmp,$baseCmp);
}
function meso_v24_ready(string $ready):bool{
    return is_dir($ready)||(@mkdir($ready,0700,true)&&is_dir($ready));
}
function meso_v24_cleanup(string $ready):void{
    $cutoff=time()-3600;
    foreach(glob($ready.'\\*')?:[] as $file){
        if(!is_file($file)){continue;}
        $mtime=@filemtime($file);
        if($mtime!==false&&$mtime<$cutoff){@unlink($file);}
    }
}
function meso_v24_publish_meta(string $path,array $meta):bool{
    $raw=json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    return $raw!==false&&@file_put_contents($path,$raw,LOCK_EX)!==false;
}

if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST'){
    header('Allow: POST');
    meso_v24_json(405,['ok'=>false,'error'=>'method_not_allowed']);
    exit;
}
meso_chat_require_json_auth();
$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if($length<=0||$length>4096){meso_v24_json(400,['ok'=>false,'error'=>'invalid_request_size']);exit;}
$body=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($body)){meso_v24_json(400,['ok'=>false,'error'=>'invalid_json']);exit;}
$action=strtolower(trim((string)($body['action']??'status')));
$root=meso_private_root().'\\voice-lab-v24';
$ready=$root.'\\ready';
$manifestPath=$root.'\\manifest.json';
$manifest=is_file($manifestPath)?json_decode((string)file_get_contents($manifestPath),true):null;
$lanes=is_array($manifest)&&isset($manifest['lanes'])&&is_array($manifest['lanes'])?$manifest['lanes']:[];

if($action==='status'){
    $laneIds=[];
    foreach($lanes as $lane){
        if(is_array($lane)&&isset($lane['id'])){$laneIds[]=(string)$lane['id'];}
    }
    meso_v24_json(200,['ok'=>true,'version'=>'meso-v2.4','batch_count'=>count($laneIds),'labels'=>['A','B','C','D','E'],'lanes'=>$laneIds]);
    exit;
}

$laneId=trim((string)($body['lane']??''));
$lane=meso_v24_lane($lanes,$laneId);
if($lane===null){meso_v24_json(400,['ok'=>false,'error'=>'invalid_lane']);exit;}

if($action==='vote'){
    $choice=strtoupper(trim((string)($body['choice']??'')));
    if($choice!=='REJECT'&&!in_array($choice,['A','B','C','D','E'],true)){meso_v24_json(400,['ok'=>false,'error'=>'invalid_vote']);exit;}
    if(!is_dir($root)&&!@mkdir($root,0700,true)&&!is_dir($root)){meso_v24_json(503,['ok'=>false,'error'=>'voice_lab_unavailable']);exit;}
    $row=json_encode(['at'=>time(),'lane'=>$laneId,'choice'=>$choice==='REJECT'?'reject':$choice],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
    if($row===false||@file_put_contents($root.'\\votes.jsonl',$row,FILE_APPEND|LOCK_EX)===false){meso_v24_json(503,['ok'=>false,'error'=>'vote_failed']);exit;}
    meso_v24_json(200,['ok'=>true,'vote'=>true,'lane'=>$laneId,'choice'=>$choice==='REJECT'?'reject':$choice]);
    exit;
}

if(!meso_v24_ready($ready)){meso_v24_json(503,['ok'=>false,'error'=>'voice_sweep_unavailable']);exit;}
meso_v24_cleanup($ready);

if($action==='anchor'){
    $anchor=(string)($lane['anchor_path']??'');
    if(!meso_v24_private_file($anchor,$root)){meso_v24_json(503,['ok'=>false,'error'=>'voice_sweep_unavailable']);exit;}
    try{$token=bin2hex(random_bytes(32));}catch(Throwable $e){meso_v24_json(503,['ok'=>false,'error'=>'voice_sweep_unavailable']);exit;}
    $published=$ready.'\\'.$token.'.wav';
    $metaPath=$ready.'\\'.$token.'.json';
    if(!@copy($anchor,$published)||!meso_v24_publish_meta($metaPath,['kind'=>'anchor','lane'=>$laneId,'created_at'=>time()])){
        @unlink($published);@unlink($metaPath);meso_v24_json(503,['ok'=>false,'error'=>'voice_sweep_unavailable']);exit;
    }
    meso_v24_json(200,['ok'=>true,'kind'=>'anchor','lane'=>$laneId,'audio_url'=>'/meso/api/voice-lab-v24-audio.php?id='.$token,'expires_in'=>3600]);
    exit;
}

if($action!=='synthesize'){meso_v24_json(400,['ok'=>false,'error'=>'invalid_action']);exit;}
$label=strtoupper(trim((string)($body['label']??'')));
if(!in_array($label,['A','B','C','D','E'],true)){meso_v24_json(400,['ok'=>false,'error'=>'invalid_label']);exit;}
$profiles=isset($lane['profiles'])&&is_array($lane['profiles'])?$lane['profiles']:[];
$refs=isset($profiles[$label])&&is_array($profiles[$label])?$profiles[$label]:[];
if(count($refs)<1||count($refs)>4){meso_v24_json(503,['ok'=>false,'error'=>'voice_sweep_unavailable']);exit;}
foreach($refs as $ref){if(!is_string($ref)||!meso_v24_private_file($ref,$root)){meso_v24_json(503,['ok'=>false,'error'=>'voice_sweep_unavailable']);exit;}}
$phrases=[
    'ar-casual'=>['language'=>'ar','text'=>'مرحبا، كيفك اليوم؟ شو أخبارك؟ خبرني شو صار معك.'],
    'ar-warm'=>['language'=>'ar','text'=>'والله اشتقتلك، احكيلي شوي عن يومك وكيف كانت أمورك.'],
    'en-casual'=>['language'=>'en','text'=>'Hey, how are you today? Tell me what happened and how your day went.']
];
$phrase=$phrases[$laneId]??null;
if(!is_array($phrase)||(string)($lane['language']??'')!==$phrase['language']){meso_v24_json(503,['ok'=>false,'error'=>'voice_sweep_unavailable']);exit;}
$python='C:\\ProgramData\\KhalilDigitalTwin\\meso\\xtts-venv\\Scripts\\python.exe';
$helper='C:\\ProgramData\\KhalilDigitalTwin\\meso\\chatterbox-v24\\meso_chatterbox_v24_client.py';
if(!is_file($python)||!is_file($helper)){meso_v24_json(503,['ok'=>false,'error'=>'voice_sweep_unavailable']);exit;}
try{$token=bin2hex(random_bytes(32));}catch(Throwable $e){meso_v24_json(503,['ok'=>false,'error'=>'voice_sweep_unavailable']);exit;}
$temp=$ready.'\\tmp-'.$token.'.wav';
$published=$ready.'\\'.$token.'.wav';
$metaPath=$ready.'\\'.$token.'.json';
$lock=@fopen($root.'\\chatterbox.lock','c+');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){
    if(is_resource($lock))fclose($lock);
    meso_v24_json(429,['ok'=>false,'error'=>'voice_sweep_busy']);exit;
}
try{
    @set_time_limit(305);
    $pipes=[];
    $proc=@proc_open([$python,$helper],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($proc))throw new RuntimeException('process_start_failed');
    $request=['text'=>$phrase['text'],'language'=>$phrase['language'],'reference_paths'=>array_values($refs),'output'=>$temp,'candidate_id'=>$label];
    fwrite($pipes[0],json_encode($request,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));fclose($pipes[0]);
    $stdout=(string)stream_get_contents($pipes[1]);fclose($pipes[1]);
    stream_get_contents($pipes[2]);fclose($pipes[2]);
    $exit=proc_close($proc);
    if($exit!==0||!is_file($temp))throw new RuntimeException('synthesis_failed');
    $result=json_decode(trim($stdout),true);
    if(!is_array($result)||($result['ok']??false)!==true||($result['engine']??'')!=='chatterbox'||($result['model']??'')!=='multilingual-v3'||($result['candidate_id']??'')!==$label)throw new RuntimeException('invalid_output');
    $referenceCount=(int)($result['references']??0);
    if($referenceCount<1||$referenceCount>4||$referenceCount!==count($refs))throw new RuntimeException('invalid_reference_count');
    $size=@filesize($temp);
    if($size===false||$size<1024||$size>16777216)throw new RuntimeException('invalid_wav_size');
    if(!@rename($temp,$published))throw new RuntimeException('publish_failed');
    if(!meso_v24_publish_meta($metaPath,['kind'=>'candidate','lane'=>$laneId,'label'=>$label,'references'=>$referenceCount,'created_at'=>time()]))throw new RuntimeException('metadata_failed');
    meso_v24_json(200,['ok'=>true,'kind'=>'candidate','lane'=>$laneId,'label'=>$label,'references'=>$referenceCount,'audio_url'=>'/meso/api/voice-lab-v24-audio.php?id='.$token,'expires_in'=>3600]);
}catch(Throwable $e){
    @unlink($temp);@unlink($published);@unlink($metaPath);
    meso_v24_json(503,['ok'=>false,'error'=>'voice_sweep_unavailable']);
}finally{
    flock($lock,LOCK_UN);fclose($lock);
}
