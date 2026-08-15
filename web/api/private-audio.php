<?php
declare(strict_types=1);

session_name('MESO_REVIEW');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/meso',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (($_SESSION['meso_review_ok'] ?? false) !== true) {
    http_response_code(403);
    exit;
}

$map = [
    'A' => 'meso-A-1ref-ar.wav',
    'B' => 'meso-B-3refs-ar.wav',
    'C' => 'meso-C-5refs-ar.wav',
];
$key = strtoupper(trim((string)($_GET['sample'] ?? '')));
if (!isset($map[$key])) {
    http_response_code(404);
    exit;
}

$root = 'C:\\MesoAI\\private\\evaluation\\generated\\xtts-v1';
$rootReal = realpath($root);
$file = $root . '\\' . $map[$key];
$fileReal = realpath($file);
if ($rootReal === false || $fileReal === false || !is_file($fileReal)) {
    http_response_code(404);
    exit;
}
$rootNorm = rtrim(strtolower(str_replace('/', '\\', $rootReal)), '\\') . '\\';
$fileNorm = strtolower(str_replace('/', '\\', $fileReal));
if (!str_starts_with($fileNorm, $rootNorm)) {
    http_response_code(403);
    exit;
}

$size = filesize($fileReal);
if ($size === false || $size < 44) {
    http_response_code(404);
    exit;
}
header('Content-Type: audio/wav');
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="' . basename($fileReal) . '"');
header('Accept-Ranges: none');
readfile($fileReal);
