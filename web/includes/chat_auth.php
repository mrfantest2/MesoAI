<?php
declare(strict_types=1);

const MESO_CHAT_COOKIE = 'meso_chat_auth';
const MESO_CHAT_COOKIE_DAYS = 30;

function meso_private_root(): string {
    $test = PHP_SAPI === 'cli' ? trim((string)(getenv('MESO_TEST_PRIVATE_ROOT') ?: '')) : '';
    return $test !== '' ? $test : 'C:\\MesoAI\\private';
}

function meso_chat_secret_path(): string {
    return meso_private_root() . '\\chat-cookie-secret.txt';
}

// The server operator provisions independent, >=32-character random invitation codes.
// Only SHA-256 digests live in the private (non-web) configuration file.
function meso_chat_account_hash_path(): string {
    return meso_private_root() . DIRECTORY_SEPARATOR . 'chat-accounts.json';
}
function meso_chat_secret(): ?string {
    $path = meso_chat_secret_path();
    if (!is_file($path) || !is_readable($path)) return null;
    $value = trim((string)file_get_contents($path));
    return strlen($value) >= 32 ? $value : null;
}
function meso_b64url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}
function meso_chat_valid_audience(string $audience): bool {
    return in_array($audience, ['majid','mira'], true);
}
function meso_chat_account_digest(string $audience): ?string {
    if (!meso_chat_valid_audience($audience)) return null;
    $path=meso_chat_account_hash_path();
    if (!is_file($path) || !is_readable($path)) return null;
    $config=json_decode((string)file_get_contents($path),true);
    $digest=is_array($config)?($config['accounts'][$audience]??null):null;
    return is_string($digest) && preg_match('/^[a-f0-9]{64}$/D',$digest)===1 ? $digest : null;
}
function meso_chat_verify_invite(string $audience, string $token): bool {
    if (strlen($token)<32 || strlen($token)>256) return false;
    $expected=meso_chat_account_digest($audience);
    return $expected!==null && hash_equals($expected,hash('sha256',$token));
}
function meso_chat_invite_rate_ok(string $ip): bool {
    $dir = meso_private_root() . DIRECTORY_SEPARATOR . 'chat-auth-rate';
    if (!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) return false;
    $file = $dir . DIRECTORY_SEPARATOR . hash('sha256',$ip) . '.json';
    $fh = @fopen($file,'c+');
    if (!$fh) return false;
    try {
        if (!flock($fh,LOCK_EX)) return false;
        $events = json_decode((string)stream_get_contents($fh),true);
        if (!is_array($events)) $events=[];
        $now=time();
        $events=array_values(array_filter($events,static fn($x)=>is_int($x)&&$x>$now-600));
        if (count($events)>=8) return false;
        $events[]=$now;
        rewind($fh);ftruncate($fh,0);fwrite($fh,(string)json_encode($events));fflush($fh);
        return true;
    } finally { flock($fh,LOCK_UN);fclose($fh); }
}
function meso_chat_cookie_value(string $audience, int $expires): ?string {
    if (!meso_chat_valid_audience($audience)) return null;
    $secret=meso_chat_secret();
    $accountDigest=meso_chat_account_digest($audience);
    if ($secret===null || $accountDigest===null) return null;
    // Changing or removing an account's invitation digest invalidates existing sessions.
    $payload='meso-v2|'.$audience.'|'.$expires.'|'.$accountDigest;
    return 'v2.'.$audience.'.'.$expires.'.'.meso_b64url(hash_hmac('sha256',$payload,$secret,true));
}
function meso_chat_current_audience(): ?string {
    $raw=(string)($_COOKIE[MESO_CHAT_COOKIE] ?? '');
    if (!preg_match('/^v2\.(majid|mira)\.([0-9]{10,12})\.([A-Za-z0-9_-]{43})$/D',$raw,$m)) return null;
    $exp=(int)$m[2];
    if ($exp<time() || $exp>time()+MESO_CHAT_COOKIE_DAYS*86400+3600) return null;
    $expected=meso_chat_cookie_value($m[1],$exp);
    return $expected!==null && hash_equals($expected,$raw) ? $m[1] : null;
}
function meso_chat_session_can_access(?string $conversationAudience): bool {
    $actor=meso_chat_current_audience();
    return $actor!==null && $conversationAudience!==null && $actor===$conversationAudience;
}
function meso_chat_require_conversation_audience(string $conversationId): void {
    if (!function_exists('meso_memory_get_conversation')) throw new RuntimeException('memory_unavailable');
    $conversation=meso_memory_get_conversation($conversationId);
    if (!is_array($conversation) || !meso_chat_session_can_access($conversation['audience']??null)) {
        throw new InvalidArgumentException('conversation_access_denied');
    }
}
function meso_request_is_https(): bool {
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $forwarded = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return ($https !== '' && $https !== 'off') || $forwarded === 'https' || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function meso_chat_set_authorized_cookie(string $audience): bool {
    $expires=time()+MESO_CHAT_COOKIE_DAYS*86400;
    $value=meso_chat_cookie_value($audience,$expires);
    if ($value===null) return false;
    return setcookie(MESO_CHAT_COOKIE,$value,[
        'expires'=>$expires,'path'=>'/meso/','secure'=>meso_request_is_https(),
        'httponly'=>true,'samesite'=>'Strict',
    ]);
}
function meso_chat_is_authorized(): bool {
    // Fail closed. In particular, GET /meso/chat/ NEVER auto-issues a cookie.
    return meso_chat_current_audience()!==null;
}
function meso_chat_clear_cookie(): void {
    setcookie(MESO_CHAT_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/meso/',
        'secure' => meso_request_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function meso_chat_require_json_auth(): void {
    if (meso_chat_is_authorized()) return;
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => 'chat_auth_required']);
    exit;
}

function meso_chat_state_request_allowed(array $server): bool {
    $contentType = strtolower(trim(explode(';', (string)($server['CONTENT_TYPE'] ?? ''), 2)[0]));
    if ($contentType !== 'application/json') return false;

    $fetchSite = strtolower(trim((string)($server['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($fetchSite === 'cross-site') return false;

    $origin = trim((string)($server['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') return true;
    $parts = parse_url($origin);
    if (!is_array($parts)) return false;
    $originHost = strtolower((string)($parts['host'] ?? ''));
    $originScheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($originHost === '' || !in_array($originScheme, ['http','https'], true)) return false;

    $hostHeader = strtolower(trim((string)($server['HTTP_HOST'] ?? '')));
    if ($hostHeader === '') return false;
    $requestAuthority = parse_url('http://' . $hostHeader);
    if (!is_array($requestAuthority) || strtolower((string)($requestAuthority['host'] ?? '')) !== $originHost) return false;

    $https = strtolower((string)($server['HTTPS'] ?? ''));
    $forwarded = strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $requestIsHttps = ($https !== '' && $https !== 'off') || $forwarded === 'https' || (string)($server['SERVER_PORT'] ?? '') === '443';
    $requestScheme = $requestIsHttps ? 'https' : 'http';
    if ($originScheme !== $requestScheme) return false;

    $requestPort = isset($requestAuthority['port']) ? (int)$requestAuthority['port'] : ($requestIsHttps ? 443 : 80);
    if ($requestPort <= 0) $requestPort = $requestIsHttps ? 443 : 80;
    $originPort = isset($parts['port']) ? (int)$parts['port'] : ($originScheme === 'https' ? 443 : 80);
    return $requestPort === $originPort;
}

function meso_chat_require_json_state_auth(): void {
    meso_chat_require_json_auth();
    if (meso_chat_state_request_allowed($_SERVER)) return;
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok'=>false,'error'=>'state_request_rejected']);
    exit;
}
