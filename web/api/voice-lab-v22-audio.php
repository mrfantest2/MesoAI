<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_auth.php';
header('Cache-Control: private, no-store, max-age=0');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));if($method!=='GET'&&$method!=='HEAD'){http_response_code(405);header('Allow: GET, HEAD');exit;}meso_chat_require_json_auth();
$id=strtolower(trim((string)($_GET['id']??'')));if(preg_match('/\A[a-f0-9]{64}\z/D',$id)!==1){http_response_code(404);exit;}
$ready=meso_private_root().'\\voice-lab-v22\\ready';$path=$ready.'\\'.$id.'.mp3';$metaPath=$ready.'\\'.$id.'.json';if(!is_file($path)||!is_readable($path)||!is_file($metaPath)||!is_readable($metaPath)){http_response_code(404);exit;}
$mtime=filemtime($path);if($mtime===false||$mtime<time()-3600){@unlink($path);@unlink($metaPath);http_response_code(410);exit;}
$meta=json_decode((string)file_get_contents($metaPath),true);$label=is_array($meta)?strtoupper((string)($meta['label']??'')):'';$batch=is_array($meta)?(int)($meta['batch']??-1):-1;if(!in_array($label,['A','B','C','D','E'],true)||$batch<0){http_response_code(404);exit;}
$size=filesize($path);if($size===false||$size<1024||$size>8388608){http_response_code(404);exit;}$start=0;$end=$size-1;$range=trim((string)($_SERVER['HTTP_RANGE']??''));
if($range!==''){if(preg_match('/\Abytes=(\d+)-(\d*)\z/D',$range,$m)!==1){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}$start=(int)$m[1];if($m[2]!=='')$end=(int)$m[2];if($start<0||$start>=$size||$end<$start){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}if($end>=$size)$end=$size-1;http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);}
$length=$end-$start+1;header('Content-Type: audio/mpeg');header('Accept-Ranges: bytes');header('Content-Length: '.$length);header('Content-Disposition: inline; filename="meso-voice-v22-'.$label.'.mp3"');header('X-Meso-Voice: xtts-v2');header('X-Meso-Voice-Sweep: meso-v2.2');header('X-Meso-Voice-Lab: '.$label);header('X-Meso-Voice-Batch: '.$batch);if($method==='HEAD')exit;
$fh=fopen($path,'rb');if(!$fh){http_response_code(404);exit;}try{if($start>0&&fseek($fh,$start)!==0)throw new RuntimeException('seek_failed');$remaining=$length;while($remaining>0&&!feof($fh)){$chunk=fread($fh,min(65536,$remaining));if($chunk===false)throw new RuntimeException('read_failed');if($chunk==='')break;echo $chunk;$remaining-=strlen($chunk);}}finally{fclose($fh);}
