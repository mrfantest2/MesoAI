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

function meso_chat_direct_page_request(): bool {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') return false;
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    return rtrim($path, '/') === '/meso/chat';
}

function meso_chat_is_authorized(): bool {
    $raw = (string)($_COOKIE[MESO_CHAT_COOKIE] ?? '');
    if ($raw !== '' && str_contains($raw, '.')) {
        [$expRaw, $sig] = explode('.', $raw, 2);
        if (ctype_digit($expRaw)) {
            $exp = (int)$expRaw;
            $now = time();
            if ($exp >= $now && $exp <= $now + (MESO_CHAT_COOKIE_DAYS * 86400) + 3600) {
                $expected = meso_chat_cookie_value($exp);
                if ($expected !== null) {
                    [, $expectedSig] = explode('.', $expected, 2);
                    if (hash_equals($expectedSig, $sig)) return true;
                }
            }
        }
    }

    // Direct browser access no longer requires a one-time invite. The page
    // issues the existing signed cookie; JSON APIs remain cookie-protected.
    if (meso_chat_direct_page_request()) return meso_chat_set_authorized_cookie();
    return false;
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
