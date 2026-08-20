<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_auth.php';

if (!meso_chat_is_authorized() && !meso_chat_set_authorized_cookie()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Meso Voice Lab is unavailable.';
    exit;
}
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; media-src 'self'; connect-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Meso Voice Lab</title>
<style>
:root{color-scheme:dark;--bg:#0b0e14;--card:#141924;--line:#2b3344;--text:#f3f5f8;--muted:#9ba5b7;--accent:#9dd1ff}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.45 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:920px;margin:auto;padding:24px 16px 48px}header{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:22px}h1{font-size:24px;margin:0}.sub{color:var(--muted);margin-top:5px}.badge{border:1px solid var(--line);border-radius:999px;padding:7px 10px;color:var(--accent);white-space:nowrap}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;margin:14px 0}.phrase{font-size:17px;margin:4px 0 14px}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}.play,.vote{min-height:44px;border-radius:11px;border:1px solid var(--line);background:#1c2331;color:var(--text);font-weight:700;cursor:pointer}.play[disabled]{opacity:.5;cursor:wait}.play.active,.vote.active{outline:2px solid var(--accent);border-color:transparent}.hint,.status{color:var(--muted);font-size:13px;margin-top:10px}.summary{position:sticky;bottom:10px;background:#10151f;border:1px solid var(--line);border-radius:14px;padding:14px;margin-top:18px;box-shadow:0 8px 30px #0008}.summary strong{color:var(--accent)}a{color:var(--accent)}@media(max-width:600px){header{align-items:flex-start;flex-direction:column}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
</head>
<body>
<div class="wrap">
<header><div><h1>Meso Voice Blind Lab</h1><div class="sub">Listen first. The profiles behind A/B/C/D stay hidden until a winner is selected.</div></div><div class="badge">Voice v2.1 evaluation</div></header>

<section class="card" data-phrase="ar-casual">
<div class="hint">Arabic · casual</div><div class="phrase" dir="rtl">مرحبا، كيفك اليوم؟ شو أخبارك؟ خبرني شو صار معك.</div>
<div class="grid" data-plays></div><div class="status">Tap a letter to generate and play the same sentence.</div><div class="grid" data-votes></div>
</section>
<section class="card" data-phrase="ar-warm">
<div class="hint">Arabic · warm</div><div class="phrase" dir="rtl">والله اشتقتلك، احكيلي شوي عن يومك وكيف كانت أمورك.</div>
<div class="grid" data-plays></div><div class="status">Compare identity, pronunciation, rhythm and naturalness.</div><div class="grid" data-votes></div>
</section>
<section class="card" data-phrase="en-casual">
<div class="hint">English · casual</div><div class="phrase">Hey, how are you today? Tell me what happened and how your day went.</div>
<div class="grid" data-plays></div><div class="status">Use headphones if possible.</div><div class="grid" data-votes></div>
</section>
<section class="card" data-phrase="mixed">
<div class="hint">Arabic + English</div><div class="phrase" dir="auto">شو الأخبار؟ I hope your day was good. احكيلي شو صار معك.</div>
<div class="grid" data-plays></div><div class="status">This round checks code-switching.</div><div class="grid" data-votes></div>
</section>

<div class="summary">Selections: <span data-summary>none yet</span><br><strong data-winner>No winner yet</strong> · <a href="/meso/chat/">Back to MesoAI chat</a></div>
</div>
<script src="/meso/voice-lab/voice-lab.js" defer></script>
</body>
</html>
