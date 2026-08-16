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

// Public chat mode. The normal MesoAI chat and chat API no longer require an
// invite/cookie. Private voice-review and private-audio routes use their own
// MESO_REVIEW session gate and remain isolated from this public-chat setting.
function meso_chat_is_authorized(): bool {
    return true;
}

function meso_request_is_https(): bool {
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $forwarded = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return ($https !== '' && $https !== 'off') || $forwarded === 'https' || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

// Kept for compatibility with older invite links. Public chat does not depend
// on this cookie anymore.
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
    // Intentionally public. Request validation, provider isolation and the
    // existing per-IP chat rate limit remain enforced by api/chat.php.
    return;
}
