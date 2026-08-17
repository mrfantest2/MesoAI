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

function fail_review(string $message, int $status = 403): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MesoAI Review</title><style>body{margin:0;background:#090711;color:#f5f1ff;font:16px/1.5 system-ui;padding:32px}.box{max-width:620px;margin:10vh auto;background:#151120;border:1px solid #2c2440;border-radius:18px;padding:22px}</style></head><body><div class="box"><h2>Private review unavailable</h2><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></div></body></html>';
    exit;
}

// GET never consumes the token. Only explicit POST opens the review session.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['open_review'])) {
    $submitted = trim((string)($_POST['token'] ?? ''));
    $expected = is_file($tokenPath) ? trim((string)file_get_contents($tokenPath)) : '';
    if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
        fail_review('Invalid or expired review token.');
    }
    session_regenerate_id(true);
    $_SESSION['meso_review_ok'] = true;
    $_SESSION['meso_review_started'] = time();
    @unlink($tokenPath);
    header('Location: /meso/private-review.php', true, 303);
    exit;
}

$token = trim((string)($_GET['token'] ?? ''));
if (($_SESSION['meso_review_ok'] ?? false) !== true) {
    if ($token === '') { fail_review('A review token is required.'); }
    $expected = is_file($tokenPath) ? trim((string)file_get_contents($tokenPath)) : '';
    if ($expected === '' || !hash_equals($expected, $token)) { fail_review('Invalid or expired review token.'); }
    ?>
    <!doctype html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>MesoAI Fish S2 Review</title>
    <style>
    :root{color-scheme:dark;--card:#151120;--line:#2c2440;--text:#f5f1ff;--muted:#a99fbe}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,#1a1230 0,#090711 48%);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;min-height:100vh;display:grid;place-items:center;padding:20px}.card{width:min(100%,520px);background:linear-gradient(180deg,#171222,#110d1a);border:1px solid var(--line);border-radius:22px;padding:24px;box-shadow:0 18px 50px #0008}.eyebrow{font-size:12px;letter-spacing:.15em;text-transform:uppercase;color:#c5a9ff;font-weight:800}h1{font-size:30px;line-height:1.08;margin:8px 0 10px}p{color:var(--muted);margin:0 0 20px}.btn{width:100%;border:0;border-radius:14px;padding:15px 18px;background:linear-gradient(135deg,#9a65f4,#7044c5);color:white;font:700 16px system-ui;cursor:pointer}.small{font-size:12px;color:#827893;margin-top:14px}
    </style>
    </head>
    <body>
      <main class="card">
        <div class="eyebrow">MesoAI · Private evaluation</div>
        <h1>Fish S2 Pro samples are ready</h1>
        <p>Three controlled Fish S2 Pro variants use the same private reference and the same Arabic benchmark phrase. The raw reference is never served by this page.</p>
        <form method="post" action="/meso/private-review.php" autocomplete="off">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
          <button class="btn" type="submit" name="open_review" value="1">Open private review</button>
        </form>
        <div class="small">The single-use token is consumed only after you press the button.</div>
      </main>
      <script>try{history.replaceState(null,'','/meso/private-review.php')}catch(e){}</script>
    </body>
    </html>
    <?php
    exit;
}

$variants = [
    ['id' => 'F1', 'title' => 'F1 — balanced', 'subtitle' => 'Temperature 1.00 · top-p 0.90 · top-k 30'],
    ['id' => 'F2', 'title' => 'F2 — conservative', 'subtitle' => 'Temperature 0.85 · top-p 0.85 · top-k 25'],
    ['id' => 'F3', 'title' => 'F3 — expressive', 'subtitle' => 'Temperature 1.10 · top-p 0.92 · top-k 35'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>MesoAI Fish S2 Pro Voice Review</title>
<style>
:root{color-scheme:dark;--card:#151120;--line:#2c2440;--text:#f5f1ff;--muted:#a99fbe;--accent2:#6e46c6}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,#1a1230 0,#090711 44%);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;min-height:100vh}.wrap{max-width:760px;margin:0 auto;padding:28px 18px 48px}.eyebrow{font-size:12px;letter-spacing:.16em;text-transform:uppercase;color:#c5a9ff;font-weight:700}.hero{padding:14px 0 24px}.hero h1{font-size:clamp(30px,7vw,50px);line-height:1.02;margin:8px 0 12px}.hero p{margin:0;color:var(--muted);max-width:650px}.notice{border:1px solid var(--line);background:#100d19;border-radius:16px;padding:13px 15px;margin:0 0 18px;color:#d8cfe8}.grid{display:grid;gap:14px}.card{background:linear-gradient(180deg,#171222,#110d1a);border:1px solid var(--line);border-radius:20px;padding:18px;box-shadow:0 12px 30px #0005}.tag{display:inline-flex;align-items:center;justify-content:center;min-width:42px;height:34px;padding:0 8px;border-radius:11px;background:var(--accent2);font-weight:800;font-size:16px}.row{display:flex;align-items:center;gap:12px;margin-bottom:12px}.title{font-weight:750;font-size:18px}.sub{color:var(--muted);font-size:13px}audio{width:100%;height:46px}.footer{margin-top:22px;color:var(--muted);font-size:12px}.footer strong{color:#ddd3ef}.question{margin-top:20px;padding:16px;border-radius:16px;border:1px dashed #4b3b6d;color:#d7caee}.question b{color:#fff}@media(max-width:520px){.row{align-items:flex-start}.title{font-size:16px}}
</style>
</head>
<body>
<main class="wrap">
  <section class="hero">
    <div class="eyebrow">MesoAI · Fish Audio S2 Pro</div>
    <h1>Voice identity comparison</h1>
    <p>F1–F3 use one verified private reference and identical benchmark text. Only sampling controls change.</p>
  </section>
  <div class="notice">Generated WAV files remain under the private MASTER-PC evaluation folder and are streamed only to this authenticated review session.</div>
  <section class="grid">
    <?php foreach ($variants as $v): ?>
      <article class="card">
        <div class="row">
          <span class="tag"><?= htmlspecialchars($v['id']) ?></span>
          <div><div class="title"><?= htmlspecialchars($v['title']) ?></div><div class="sub"><?= htmlspecialchars($v['subtitle']) ?></div></div>
        </div>
        <audio controls preload="metadata" src="/meso/api/private-audio.php?sample=<?= rawurlencode($v['id']) ?>"></audio>
      </article>
    <?php endforeach; ?>
  </section>
  <div class="question"><b>Judge identity first:</b> reply F1, F2 or F3 if one is genuinely close. If none are convincing, reply <b>none</b>.</div>
  <div class="footer"><strong>Private:</strong> generated voice and reference audio are not committed to GitHub or placed in the public web root.</div>
</main>
</body>
</html>
