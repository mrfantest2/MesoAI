<?php
declare(strict_types=1);

const MESO_CHAT_COOKIE = 'meso_chat_auth';
const MESO_CHAT_COOKIE_DAYS = 30;

function meso_private_root(): string {
    return 'C:\\MesoAI\\private';
}

function meso_chat_secret_path(): string {
    return meso_private_root() . '\\chat-cookie-secret.txt';
}

function meso_chat_invite_path(): string {
    return meso_private_root() . '\\chat-invite-token.txt';
}

function meso_chat_secret(): ?string {
    $path = meso_chat_secret_path();
    if (!is_file($path) || !is_readable($path)) return null;
    $value = trim((string)file_get_contents($path));
    return $value !== '' ? $value : null;
}

function meso_b64url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function meso_chat_cookie_value(int $expires): ?string {
    $secret = meso_chat_secret();
    if ($secret === null) return null;
    $payload = 'meso-chat|' . $expires;
    $sig = meso_b64url(hash_hmac('sha256', $payload, $secret, true));
    return $expires . '.' . $sig;
}

function meso_chat_is_authorized(): bool {
    $raw = (string)($_COOKIE[MESO_CHAT_COOKIE] ?? '');
    if ($raw === '' || !str_contains($raw, '.')) return false;
    [$expRaw, $sig] = explode('.', $raw, 2);
    if (!ctype_digit($expRaw)) return false;
    $exp = (int)$expRaw;
    $now = time();
    if ($exp < $now || $exp > $now + (MESO_CHAT_COOKIE_DAYS * 86400) + 3600) return false;
    $expected = meso_chat_cookie_value($exp);
    if ($expected === null) return false;
    [, $expectedSig] = explode('.', $expected, 2);
    return hash_equals($expectedSig, $sig);
}

function meso_request_is_https(): bool {
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $forwarded = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return ($https !== '' && $https !== 'off') || $forwarded === 'https' || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function meso_chat_set_authorized_cookie(): bool {
    $expires = time() + MESO_CHAT_COOKIE_DAYS * 86400;
    $value = meso_chat_cookie_value($expires);
    if ($value === null) return false;
    return setcookie(MESO_CHAT_COOKIE, $value, [
        'expires' => $expires,
        'path' => '/meso/',
        'secure' => meso_request_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
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
