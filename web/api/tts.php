<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_auth.php';

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function meso_tts_json(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
function meso_tts_error(int $status, string $error, string $message = ''): void {
    $body = ['ok' => false, 'error' => $error];
    if ($message !== '') $body['message'] = $message;
    meso_tts_json($status, $body);
}
function meso_tts_rate_limit(): bool {
    $root = meso_private_root() . '\\xtts-live\\rate';
    if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) return false;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $path = $root . '\\' . hash('sha256', $ip) . '.json';
    $fh = @fopen($path, 'c+');
    if (!$fh) return false;
    try {
        if (!flock($fh, LOCK_EX)) return false;
        $items = json_decode((string)stream_get_contents($fh), true);
        if (!is_array($items)) $items = [];
        $now = time();
        $items = array_values(array_filter($items, static fn($t) => is_int($t) && $t > $now - 60));
        if (count($items) >= 8) return false;
        $items[] = $now;
        ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($items)); fflush($fh); flock($fh, LOCK_UN);
        return true;
    } finally { fclose($fh); }
}
function meso_tts_cleanup_ready(string $readyRoot): void {
    $cutoff = time() - 1800;
    foreach (glob($readyRoot . '\\*.mp3') ?: [] as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime < $cutoff) {
            @unlink($file);
            @unlink(substr($file, 0, -4) . '.json');
        }
    }
    foreach (glob($readyRoot . '\\*.json') ?: [] as $meta) {
        $mp3 = substr($meta, 0, -5) . '.mp3';
        if (!is_file($mp3)) @unlink($meta);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); header('Allow: POST'); meso_tts_error(405, 'method_not_allowed'); exit;
}
meso_chat_require_json_auth();
$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length <= 0 || $length > 8192) { meso_tts_error(400, 'invalid_request_size'); exit; }
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) { meso_tts_error(400, 'invalid_json'); exit; }
$text = trim((string)($body['text'] ?? ''));
if ($text === '' || mb_strlen($text) > 1200 || str_contains($text, "\0")) { meso_tts_error(400, 'invalid_text'); exit; }
if (!meso_tts_rate_limit()) { meso_tts_error(429, 'tts_rate_limited', 'Meso voice is busy or rate limited.'); exit; }

$language = preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', $text) === 1 ? 'ar' : 'en';
$privateRoot = meso_private_root() . '\\xtts-live';
$python = 'C:\\ProgramData\\KhalilDigitalTwin\\meso\\xtts-venv\\Scripts\\python.exe';
$helper = 'C:\\ProgramData\\KhalilDigitalTwin\\meso\\xtts-bridge\\meso_xtts_client.py';
if (!is_file($python) || !is_file($helper)) { meso_tts_error(503, 'xtts_unavailable', 'Meso voice is offline.'); exit; }
if (!is_dir($privateRoot) && !@mkdir($privateRoot, 0700, true) && !is_dir($privateRoot)) { meso_tts_error(503, 'xtts_unavailable'); exit; }
$lock = @fopen($privateRoot . '\\tts.lock', 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (is_resource($lock)) fclose($lock);
    meso_tts_error(429, 'tts_busy', 'Meso voice is generating another reply.'); exit;
}
$requestRoot = $privateRoot . '\\requests';
$readyRoot = $requestRoot . '\\ready';
if ((!is_dir($requestRoot) && !@mkdir($requestRoot, 0700, true) && !is_dir($requestRoot)) || (!is_dir($readyRoot) && !@mkdir($readyRoot, 0700, true) && !is_dir($readyRoot))) {
    flock($lock, LOCK_UN); fclose($lock); meso_tts_error(503, 'xtts_unavailable'); exit;
}
meso_tts_cleanup_ready($readyRoot);
$tempPath = $requestRoot . '\\tmp-' . bin2hex(random_bytes(16)) . '.mp3';
$publishedPath = null;
$publishedMetaPath = null;
try {
    @set_time_limit(305);
    $pipes = [];
    $process = @proc_open([$python, $helper, '--output', $tempPath], [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('process_start_failed');
    fwrite($pipes[0], json_encode(['text'=>$text,'language'=>$language], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); fclose($pipes[0]);
    $stdout=(string)stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr=(string)stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exitCode=proc_close($process);
    if ($exitCode !== 0 || !is_file($tempPath)) throw new RuntimeException('synthesis_failed');
    $meta=json_decode(trim($stdout), true);
    $voiceProfile=is_array($meta) ? (string)($meta['profile'] ?? '') : '';
    if (!is_array($meta) || ($meta['ok'] ?? false)!==true || ($meta['engine'] ?? '')!=='xtts-v2' || !in_array($voiceProfile,['meso-a','meso-v2'],true) || ($meta['format'] ?? '')!=='mp3') throw new RuntimeException('unexpected_voice_profile');
    $size=filesize($tempPath);
    if ($size===false || $size<1024 || $size>8388608) throw new RuntimeException('invalid_mp3_size');
    $fh=fopen($tempPath,'rb'); if(!$fh) throw new RuntimeException('mp3_open_failed'); $head=fread($fh,3); fclose($fh);
    $isId3=strlen($head)>=3 && $head==='ID3';
    $isFrame=strlen($head)>=2 && ord($head[0])===0xFF && (ord($head[1]) & 0xE0)===0xE0;
    if(!$isId3 && !$isFrame) throw new RuntimeException('invalid_mp3_header');

    $token=bin2hex(random_bytes(32));
    $publishedPath=$readyRoot.'\\'.$token.'.mp3';
    $publishedMetaPath=$readyRoot.'\\'.$token.'.json';
    if(!@rename($tempPath,$publishedPath)) throw new RuntimeException('publish_failed');
    $mediaMeta = json_encode([
        'engine'=>'xtts-v2',
        'profile'=>$voiceProfile,
        'format'=>'mp3',
        'language'=>$language,
        'created_at'=>time(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($mediaMeta === false || @file_put_contents($publishedMetaPath, $mediaMeta, LOCK_EX) === false) throw new RuntimeException('metadata_publish_failed');

    header('X-Meso-Voice: xtts-v2');
    header('X-Meso-Voice-Format: mp3');
    header('X-Meso-Voice-Profile: '.$voiceProfile);
    header('X-Meso-Voice-Location: master-pc-local');
    header('X-Meso-Voice-Language: '.$language);
    meso_tts_json(200, ['ok'=>true,'engine'=>'xtts-v2','profile'=>$voiceProfile,'format'=>'mp3','audio_url'=>'/meso/api/tts-audio.php?id='.$token,'expires_in'=>1800]);
} catch(Throwable $e) {
    if($publishedPath!==null) @unlink($publishedPath);
    if($publishedMetaPath!==null) @unlink($publishedMetaPath);
    if(!headers_sent()) meso_tts_error(503,'xtts_unavailable','Meso voice is temporarily unavailable.');
} finally {
    @unlink($tempPath); flock($lock,LOCK_UN); fclose($lock);
}
