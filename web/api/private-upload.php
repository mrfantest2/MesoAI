<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function fail(int $code, string $message): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'POST required');

$privateRoot = 'C:\\MesoAI\\private';
$tokenPath = $privateRoot . '\\upload-token.txt';
$incoming = $privateRoot . '\\incoming';
if (!is_file($tokenPath)) fail(410, 'upload window is closed');
$expectedToken = trim((string)file_get_contents($tokenPath));
$token = trim((string)($_SERVER['HTTP_X_MESO_TOKEN'] ?? ''));
if ($expectedToken === '' || $token === '' || !hash_equals($expectedToken, $token)) fail(403, 'invalid upload token');

$uploadId = strtolower(trim((string)($_SERVER['HTTP_X_MESO_UPLOAD_ID'] ?? '')));
$sha = strtolower(trim((string)($_SERVER['HTTP_X_MESO_SHA256'] ?? '')));
$indexRaw = (string)($_SERVER['HTTP_X_MESO_CHUNK_INDEX'] ?? '');
$countRaw = (string)($_SERVER['HTTP_X_MESO_CHUNK_COUNT'] ?? '');
$sizeRaw = (string)($_SERVER['HTTP_X_MESO_TOTAL_BYTES'] ?? '');
if (!preg_match('/^[a-f0-9]{16,64}$/', $uploadId)) fail(400, 'invalid upload id');
if (!preg_match('/^[a-f0-9]{64}$/', $sha)) fail(400, 'invalid sha256');
if (!ctype_digit($indexRaw) || !ctype_digit($countRaw) || !ctype_digit($sizeRaw)) fail(400, 'invalid numeric headers');
$index = (int)$indexRaw; $count = (int)$countRaw; $totalBytes = (int)$sizeRaw;
if ($count < 1 || $count > 256 || $index < 0 || $index >= $count) fail(400, 'invalid chunk range');
if ($totalBytes < 1 || $totalBytes > 100 * 1024 * 1024) fail(413, 'invalid total size');

$body = file_get_contents('php://input');
if ($body === false || $body === '') fail(400, 'empty chunk');
if (strlen($body) > 1024 * 1024) fail(413, 'chunk too large');

if (!is_dir($incoming) && !mkdir($incoming, 0770, true) && !is_dir($incoming)) fail(500, 'cannot create private incoming directory');
$parts = $incoming . '\\.parts-' . $uploadId;
if (!is_dir($parts) && !mkdir($parts, 0770, true) && !is_dir($parts)) fail(500, 'cannot create chunk directory');
$partPath = $parts . '\\' . sprintf('%04d.part', $index);
if (file_put_contents($partPath, $body, LOCK_EX) !== strlen($body)) fail(500, 'failed to store chunk');

$complete = true;
for ($i = 0; $i < $count; $i++) {
    if (!is_file($parts . '\\' . sprintf('%04d.part', $i))) { $complete = false; break; }
}
if (!$complete) {
    echo json_encode(['ok' => true, 'complete' => false, 'received' => $index], JSON_UNESCAPED_SLASHES);
    exit;
}

$tmp = $incoming . '\\MesoAI-private-reference-pack.zip.tmp';
$target = $incoming . '\\MesoAI-private-reference-pack.zip';
$out = fopen($tmp, 'wb');
if ($out === false) fail(500, 'cannot assemble upload');
for ($i = 0; $i < $count; $i++) {
    $part = $parts . '\\' . sprintf('%04d.part', $i);
    $in = fopen($part, 'rb');
    if ($in === false) { fclose($out); fail(500, 'cannot read chunk'); }
    stream_copy_to_stream($in, $out);
    fclose($in);
}
fclose($out);
if ((int)filesize($tmp) !== $totalBytes) { @unlink($tmp); fail(422, 'assembled size mismatch'); }
$actualSha = strtolower((string)hash_file('sha256', $tmp));
if (!hash_equals($sha, $actualSha)) { @unlink($tmp); fail(422, 'assembled sha256 mismatch'); }
if (is_file($target)) @unlink($target);
if (!rename($tmp, $target)) fail(500, 'cannot finalize upload');
for ($i = 0; $i < $count; $i++) @unlink($parts . '\\' . sprintf('%04d.part', $i));
@rmdir($parts);
@unlink($tokenPath); // one-time token: close the upload window immediately.

echo json_encode([
    'ok' => true,
    'complete' => true,
    'bytes' => $totalBytes,
    'sha256' => $actualSha,
    'stored' => 'private/incoming/MesoAI-private-reference-pack.zip'
], JSON_UNESCAPED_SLASHES);
