<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'localhost_only']);
    exit;
}

$privateRoot = 'C:\\MesoAI\\private';
$enablePath = $privateRoot . '\\chat-bootstrap-enabled';
if (!is_file($enablePath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'bootstrap_disabled']);
    exit;
}

function read_env_value(array $lines, string $key): ?string {
    $pattern = '/^\s*' . preg_quote($key, '/') . '\s*=\s*(.*?)\s*$/i';
    foreach ($lines as $line) {
        if (preg_match($pattern, (string)$line, $m)) {
            $value = trim((string)$m[1]);
            if (strlen($value) >= 2 && (($value[0] === '"' && $value[strlen($value)-1] === '"') || ($value[0] === "'" && $value[strlen($value)-1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            return trim($value);
        }
    }
    return null;
}

try {
    if (!is_dir($privateRoot) && !mkdir($privateRoot, 0700, true) && !is_dir($privateRoot)) throw new RuntimeException('private_root_unavailable');
    $envPath = 'C:\\Users\\Administrator\\Khalil-Digital-Twin\\.env';
    if (!is_file($envPath) || !is_readable($envPath)) throw new RuntimeException('provider_source_unreadable');
    $lines = @file($envPath, FILE_IGNORE_NEW_LINES) ?: [];

    $selected = strtolower(read_env_value($lines, 'KHALIL_BRAIN_PROVIDER') ?: 'auto');
    $openaiKey = read_env_value($lines, 'OPENAI_API_KEY');
    $provider = null;
    $model = null;
    $providerConfig = null;

    if ($selected === 'ollama') {
        $model = read_env_value($lines, 'KHALIL_OLLAMA_MODEL') ?: 'qwen2.5:7b';
        $rawBase = read_env_value($lines, 'KHALIL_OLLAMA_BASE_URL') ?: 'http://host.docker.internal:11434';
        $parts = parse_url($rawBase);
        $port = isset($parts['port']) ? (int)$parts['port'] : 11434;
        if ($port < 1 || $port > 65535) throw new RuntimeException('invalid_ollama_port');
        $provider = 'ollama';
        $providerConfig = ['provider' => 'ollama', 'base_url' => 'http://127.0.0.1:' . $port, 'model' => $model];
    } elseif ($selected === 'openai' || ($selected === 'auto' && $openaiKey)) {
        if ($openaiKey === null || $openaiKey === '') throw new RuntimeException('openai_key_not_found');
        $model = read_env_value($lines, 'KHALIL_OPENAI_MODEL') ?: 'gpt-5';
        $provider = 'openai';
        $providerConfig = ['provider' => 'openai', 'api_key' => $openaiKey, 'model' => $model];
    } elseif (($selected === 'auto' || $selected === '') && ($openaiKey === null || $openaiKey === '')) {
        $model = read_env_value($lines, 'KHALIL_OLLAMA_MODEL') ?: 'qwen2.5:7b';
        $provider = 'ollama';
        $providerConfig = ['provider' => 'ollama', 'base_url' => 'http://127.0.0.1:11434', 'model' => $model];
    } else {
        throw new RuntimeException('unsupported_brain_provider');
    }

    $providerPath = $privateRoot . '\\provider.json';
    $tmp = $providerPath . '.tmp-' . bin2hex(random_bytes(6));
    $encoded = json_encode($providerConfig, JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($tmp, $encoded, LOCK_EX) === false) throw new RuntimeException('provider_write_failed');
    if (!@rename($tmp, $providerPath)) {
        @unlink($tmp);
        throw new RuntimeException('provider_commit_failed');
    }

    $secretPath = $privateRoot . '\\chat-cookie-secret.txt';
    if (!is_file($secretPath) || trim((string)@file_get_contents($secretPath)) === '') {
        if (file_put_contents($secretPath, bin2hex(random_bytes(32)), LOCK_EX) === false) throw new RuntimeException('cookie_secret_write_failed');
    }

    $invite = bin2hex(random_bytes(32));
    $invitePath = $privateRoot . '\\chat-invite-token.txt';
    if (file_put_contents($invitePath, $invite, LOCK_EX) === false) throw new RuntimeException('invite_write_failed');

    @unlink($enablePath);
    echo json_encode([
        'ok' => true,
        'provider_configured' => true,
        'provider' => $provider,
        'model' => $model,
        'curl_available' => function_exists('curl_init'),
        'invite_token' => $invite,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    @unlink($enablePath);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
