<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_auth.php';
meso_chat_require_auth();
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$id=strtolower(trim((string)($_GET['id']??'')));
if(!preg_match('/^[a-f0-9]{64}$/',$id)){http_response_code(404);exit;}
$root=meso_private_root().'\\voice-lab-v24\\ready';
$metaPath=$root.'\\'.$id.'.json';
if(!is_file($metaPath)){http_response_code(404);exit;}
$meta=json_decode((string)file_get_contents($metaPath),true);
if(!is_array($meta)){http_response_code(404);exit;}
$created=(int)($meta['created_at']??0);
$kind=(string)($meta['kind']??'');
if($created<1||time()-$created>3600||!in_array($kind,['anchor','candidate'],true)){http_response_code(404);exit;}
$file=null;
foreach(['mp3'=>'audio/mpeg','wav'=>'audio/wav'] as $ext=>$mime){
    $candidate=$root.'\\'.$id.'.'.$ext;
    if(is_file($candidate)){$file=[$candidate,$mime];break;}
}
if($file===null){http_response_code(404);exit;}
$size=filesize($file[0]);
if($size===false||$size<1){http_response_code(404);exit;}
header('Content-Type: '.$file[1]);
header('Content-Length: '.$size);
header('Content-Disposition: inline; filename="meso-v24-'.$kind.'.'.pathinfo($file[0],PATHINFO_EXTENSION).'"');
readfile($file[0]);
