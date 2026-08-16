<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
meso_chat_require_json_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

const MESO_STT_MAX_BYTES = 12582912;
$mime = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
$suffixes = [
    'audio/webm' => '.webm',
    'audio/ogg' => '.ogg',
    'audio/mp4' => '.m4a',
    'audio/mpeg' => '.mp3',
    'audio/mp3' => '.mp3',
    'audio/wav' => '.wav',
    'audio/x-wav' => '.wav',
    'audio/aac' => '.aac',
];
if (!isset($suffixes[$mime])) {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => 'unsupported_microphone_audio_type']);
    exit;
}

$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length <= 0 || $length > MESO_STT_MAX_BYTES) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'microphone_audio_size_invalid']);
    exit;
}

$audio = file_get_contents('php://input');
if (!is_string($audio) || $audio === '' || strlen($audio) > MESO_STT_MAX_BYTES) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'microphone_audio_invalid']);
    exit;
}

$privateRoot = meso_private_root() . '\\chat-stt';
$tmpRoot = $privateRoot . '\\tmp';
if (!is_dir($tmpRoot) && !@mkdir($tmpRoot, 0700, true) && !is_dir($tmpRoot)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'private_stt_temp_unavailable']);
    exit;
}

$python = trim((string)(getenv('MESO_CHAT_STT_PYTHON') ?: 'C:\\ProgramData\\KhalilDigitalTwin\\meso\\fish-whisper-venv\\Scripts\\python.exe'));
$script = trim((string)(getenv('MESO_CHAT_STT_SCRIPT') ?: 'C:\\ProgramData\\KhalilDigitalTwin\\meso\\chat-stt\\transcribe_chat_audio.py'));
if (!is_file($python) || !is_file($script)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'local_stt_runtime_not_ready']);
    exit;
}

try {
    $name = 'meso-chat-' . bin2hex(random_bytes(16)) . $suffixes[$mime];
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'secure_temp_name_failed']);
    exit;
}
$path = $tmpRoot . DIRECTORY_SEPARATOR . $name;

try {
    if (file_put_contents($path, $audio, LOCK_EX) !== strlen($audio)) {
        throw new RuntimeException('audio_temp_write_failed');
    }
    unset($audio);

    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open([$python, $script, $path], $spec, $pipes, null, [
        'MESO_CHAT_STT_TMP' => $tmpRoot,
        'MESO_CHAT_STT_MODEL_ROOT' => meso_private_root() . '\\models\\faster-whisper',
        'MESO_CHAT_STT_MODEL' => trim((string)(getenv('MESO_CHAT_STT_MODEL') ?: 'small')),
    ], ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('local_stt_process_unavailable');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $result = json_decode(trim((string)$stdout), true);
    if (!is_array($result)) {
        throw new RuntimeException('local_stt_invalid_response');
    }
    if (($result['ok'] ?? false) !== true) {
        http_response_code(($result['error'] ?? '') === 'no_speech_detected' ? 422 : 502);
        echo json_encode([
            'ok' => false,
            'error' => (string)($result['error'] ?? 'local_stt_failed'),
            'provider_upload' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($exitCode !== 0) {
        throw new RuntimeException('local_stt_nonzero_exit');
    }

    echo json_encode([
        'ok' => true,
        'transcript' => (string)($result['transcript'] ?? ''),
        'language' => $result['language'] ?? null,
        'language_probability' => $result['language_probability'] ?? null,
        'duration_seconds' => $result['duration_seconds'] ?? null,
        'model' => (string)($result['model'] ?? ''),
        'provider_upload' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => 'local_stt_failed',
        'provider_upload' => false,
    ]);
} finally {
    if (is_file($path)) @unlink($path);
}
