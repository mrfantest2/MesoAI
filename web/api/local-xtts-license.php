<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'localhost_only']);
    exit;
}

$envPath = 'C:\\Users\\Administrator\\Khalil-Digital-Twin\\.env';
if (!is_file($envPath) || !is_readable($envPath)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'accepted' => false, 'readable' => false]);
    exit;
}

$accepted = false;
$lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
foreach ($lines as $line) {
    if (preg_match('/^\s*(KHALIL_XTTS_TOS_AGREED|COQUI_TOS_AGREED)\s*=\s*(.+?)\s*$/i', $line, $m)) {
        $value = strtolower(trim(trim($m[2]), "\"'"));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            $accepted = true;
        }
    }
}

echo json_encode(['ok' => true, 'accepted' => $accepted, 'readable' => true]);
