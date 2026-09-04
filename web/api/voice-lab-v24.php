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
$manifestPath=$root.'\\manifest.json';
$manifest=is_file($manifestPath)?json_decode((string)file_get_contents($manifestPath),true):null;
$lanes=is_array($manifest)&&isset($manifest['lanes'])&&is_array($manifest['lanes'])?$manifest['lanes']:[];
if($action==='status'){
    $laneIds=[];
    foreach($lanes as $lane){
        if(is_array($lane)&&isset($lane['id'])){$laneIds[]=(string)$lane['id'];}
    }
    meso_v24_json(200,['ok'=>true,'version'=>'meso-v2.4','batch_count'=>count($lanes),'labels'=>['A','B','C','D','E'],'lanes'=>$laneIds]);
    exit;
}
if($action==='vote'){
    $lane=trim((string)($body['lane']??''));
    $choice=strtoupper(trim((string)($body['choice']??'')));
    if($lane===''||($choice!=='REJECT'&&!in_array($choice,['A','B','C','D','E'],true))){meso_v24_json(400,['ok'=>false,'error'=>'invalid_vote']);exit;}
    if(!is_dir($root)&&!@mkdir($root,0700,true)&&!is_dir($root)){meso_v24_json(503,['ok'=>false,'error'=>'voice_lab_unavailable']);exit;}
    $row=json_encode(['at'=>time(),'lane'=>$lane,'choice'=>strtolower($choice)==='reject'?'reject':$choice],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
    if($row===false||@file_put_contents($root.'\\votes.jsonl',$row,FILE_APPEND|LOCK_EX)===false){meso_v24_json(503,['ok'=>false,'error'=>'vote_failed']);exit;}
    meso_v24_json(200,['ok'=>true,'vote'=>true,'lane'=>$lane,'choice'=>strtolower($choice)==='reject'?'reject':$choice]);
    exit;
}
if($action==='anchor'||$action==='synthesize'){
    meso_v24_json(503,['ok'=>false,'error'=>'voice_sweep_unavailable']);
    exit;
}
meso_v24_json(400,['ok'=>false,'error'=>'invalid_action']);
