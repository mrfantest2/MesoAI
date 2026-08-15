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

$privateRoot = 'C:\\MesoAI\\private';
$tokenPath = $privateRoot . '\\review-token.txt';
$token = trim((string)($_GET['token'] ?? ''));

if ($token !== '') {
    $expected = is_file($tokenPath) ? trim((string)file_get_contents($tokenPath)) : '';
    if ($expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit('Invalid or expired review token.');
    }
    $_SESSION['meso_review_ok'] = true;
    $_SESSION['meso_review_started'] = time();
    @unlink($tokenPath); // consume the token immediately; session remains valid.
    header('Location: /meso/private-review.php', true, 303);
    exit;
}

if (($_SESSION['meso_review_ok'] ?? false) !== true) {
    http_response_code(403);
    exit('Private review session required.');
}

$variants = [
    ['id' => 'A', 'title' => 'A — 1 reference', 'subtitle' => 'Single best reference', 'file' => 'meso-A-1ref-ar.wav', 'duration' => '10.295 s'],
    ['id' => 'B', 'title' => 'B — 3 references', 'subtitle' => 'Three diverse references', 'file' => 'meso-B-3refs-ar.wav', 'duration' => '11.180 s'],
    ['id' => 'C', 'title' => 'C — 5 references', 'subtitle' => 'Five diverse references', 'file' => 'meso-C-5refs-ar.wav', 'duration' => '11.319 s'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>MesoAI Private Voice Review</title>
<style>
:root{color-scheme:dark;--bg:#090711;--card:#151120;--line:#2c2440;--text:#f5f1ff;--muted:#a99fbe;--accent:#aa78ff;--accent2:#6e46c6}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,#1a1230 0,#090711 44%);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;min-height:100vh}.wrap{max-width:760px;margin:0 auto;padding:28px 18px 48px}.eyebrow{font-size:12px;letter-spacing:.16em;text-transform:uppercase;color:#c5a9ff;font-weight:700}.hero{padding:14px 0 24px}.hero h1{font-size:clamp(30px,7vw,50px);line-height:1.02;margin:8px 0 12px}.hero p{margin:0;color:var(--muted);max-width:650px}.notice{border:1px solid var(--line);background:#100d19;border-radius:16px;padding:13px 15px;margin:0 0 18px;color:#d8cfe8}.grid{display:grid;gap:14px}.card{background:linear-gradient(180deg,#171222,#110d1a);border:1px solid var(--line);border-radius:20px;padding:18px;box-shadow:0 12px 30px #0005}.tag{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:11px;background:var(--accent2);font-weight:800;font-size:18px}.row{display:flex;align-items:center;gap:12px;margin-bottom:12px}.title{font-weight:750;font-size:18px}.sub{color:var(--muted);font-size:13px}.duration{margin-left:auto;color:#cdbbf0;font-size:12px}audio{width:100%;height:46px}.footer{margin-top:22px;color:var(--muted);font-size:12px}.footer strong{color:#ddd3ef}.question{margin-top:20px;padding:16px;border-radius:16px;border:1px dashed #4b3b6d;color:#d7caee}.question b{color:#fff}
</style>
</head>
<body>
<main class="wrap">
  <section class="hero">
    <div class="eyebrow">MesoAI · Private evaluation</div>
    <h1>First XTTS voice comparison</h1>
    <p>Same Arabic phrase, same engine, same GPU. Only the number of Maissoun reference clips changes between A, B and C.</p>
  </section>
  <div class="notice">This page does not contain public audio files. Playback is streamed from the private MASTER-PC evaluation folder and requires this authenticated review session.</div>
  <section class="grid">
    <?php foreach ($variants as $v): ?>
      <article class="card">
        <div class="row">
          <span class="tag"><?= htmlspecialchars($v['id']) ?></span>
          <div><div class="title"><?= htmlspecialchars($v['title']) ?></div><div class="sub"><?= htmlspecialchars($v['subtitle']) ?></div></div>
          <span class="duration"><?= htmlspecialchars($v['duration']) ?></span>
        </div>
        <audio controls preload="metadata" src="/meso/api/private-audio.php?sample=<?= rawurlencode($v['id']) ?>"></audio>
      </article>
    <?php endforeach; ?>
  </section>
  <div class="question"><b>Pick the closest voice:</b> A, B or C. Also tell me what sounds wrong—accent, pitch, age, speed, emotion, pronunciation, or overall identity.</div>
  <div class="footer"><strong>Private:</strong> raw references and generated WAVs stay outside the web root and are not committed to GitHub.</div>
</main>
</body>
</html>
