<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_auth.php';

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function meso_tts_json(int $status, string $error, string $message = ''): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $body = ['ok' => false, 'error' => $error];
    if ($message !== '') $body['message'] = $message;
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
        $raw = stream_get_contents($fh);
        $items = json_decode((string)$raw, true);
        if (!is_array($items)) $items = [];
        $now = time();
        $items = array_values(array_filter($items, static fn($t) => is_int($t) && $t > $now - 60));
        if (count($items) >= 8) return false;
        $items[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($items));
        fflush($fh);
        flock($fh, LOCK_UN);
        return true;
    } finally {
        fclose($fh);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    meso_tts_json(405, 'method_not_allowed');
    exit;
}

meso_chat_require_json_auth();

$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length <= 0 || $length > 8192) {
    meso_tts_json(400, 'invalid_request_size');
    exit;
}
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    meso_tts_json(400, 'invalid_json');
    exit;
}
$text = trim((string)($body['text'] ?? ''));
if ($text === '' || mb_strlen($text) > 1200 || str_contains($text, "\0")) {
    meso_tts_json(400, 'invalid_text');
    exit;
}
if (!meso_tts_rate_limit()) {
    meso_tts_json(429, 'tts_rate_limited', 'Local XTTS is busy or rate limited.');
    exit;
}

$language = preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', $text) === 1 ? 'ar' : 'en';
$privateRoot = meso_private_root() . '\\xtts-live';
$python = 'C:\\ProgramData\\KhalilDigitalTwin\\meso\\xtts-venv\\Scripts\\python.exe';
$helper = 'C:\\ProgramData\\KhalilDigitalTwin\\meso\\xtts-bridge\\meso_xtts_client.py';
if (!is_file($python) || !is_file($helper)) {
    meso_tts_json(503, 'xtts_unavailable', 'Local XTTS voice is offline.');
    exit;
}

if (!is_dir($privateRoot) && !@mkdir($privateRoot, 0700, true) && !is_dir($privateRoot)) {
    meso_tts_json(503, 'xtts_unavailable');
    exit;
}
$lockPath = $privateRoot . '\\tts.lock';
$lock = @fopen($lockPath, 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (is_resource($lock)) fclose($lock);
    meso_tts_json(429, 'tts_busy', 'Local XTTS is generating another reply.');
    exit;
}

$requestRoot = $privateRoot . '\\requests';
if (!is_dir($requestRoot) && !@mkdir($requestRoot, 0700, true) && !is_dir($requestRoot)) {
    flock($lock, LOCK_UN);
    fclose($lock);
    meso_tts_json(503, 'xtts_unavailable');
    exit;
}
$wav = $requestRoot . '\\' . bin2hex(random_bytes(16)) . '.wav';

try {
    @set_time_limit(305);
    $command = [$python, $helper, '--output', $wav];
    $pipes = [];
    $process = @proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('process_start_failed');

    fwrite($pipes[0], json_encode([
        'text' => $text,
        'language' => $language,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || !is_file($wav)) throw new RuntimeException('synthesis_failed');
    $size = filesize($wav);
    if ($size === false || $size < 44 || $size > 33554432) throw new RuntimeException('invalid_wav_size');
    $fh = fopen($wav, 'rb');
    if (!$fh) throw new RuntimeException('wav_open_failed');
    $head = fread($fh, 12);
    fclose($fh);
    if (strlen($head) < 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') {
        throw new RuntimeException('invalid_wav_header');
    }

    header('Content-Type: audio/wav');
    header('Content-Length: ' . $size);
    header('Content-Disposition: inline; filename="meso-xtts-reply.wav"');
    header('X-Meso-Voice: xtts-v2');
    header('X-Meso-Voice-Location: master-pc-local');
    header('X-Meso-Voice-Language: ' . $language);
    readfile($wav);
} catch (Throwable $e) {
    if (!headers_sent()) {
        // Do not expose child stderr, local paths, reply text, profile filenames,
        // Docker details, or internal XTTS response bodies to the browser.
        meso_tts_json(503, 'xtts_unavailable', 'Local XTTS voice is temporarily unavailable.');
    }
} finally {
    @unlink($wav);
    flock($lock, LOCK_UN);
    fclose($lock);
}
