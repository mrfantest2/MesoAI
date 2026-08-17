<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_auth.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(self), geolocation=(), payment=()');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; img-src 'self' data:; manifest-src 'self'; worker-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'logout') {
        meso_chat_clear_cookie();
        header('Location: /meso/chat/', true, 303);
        exit;
    }
    if ($action === 'authorize') {
        $token = trim((string)($_POST['token'] ?? ''));
        $path = meso_chat_invite_path();
        $expected = is_file($path) && is_readable($path) ? trim((string)file_get_contents($path)) : '';
        if ($token !== '' && $expected !== '' && hash_equals($expected, $token) && meso_chat_set_authorized_cookie()) {
            @unlink($path);
            header('Location: /meso/chat/', true, 303);
            exit;
        }
        http_response_code(403);
        $gateError = 'This private chat invite is invalid or has already been used.';
    }
}

$authorized = meso_chat_is_authorized();
$queryToken = trim((string)($_GET['token'] ?? ''));
$inviteValid = false;
if (!$authorized && $queryToken !== '') {
    $path = meso_chat_invite_path();
    $expected = is_file($path) && is_readable($path) ? trim((string)file_get_contents($path)) : '';
    $inviteValid = $expected !== '' && hash_equals($expected, $queryToken);
}
if (!$authorized && !$inviteValid && !isset($gateError)) http_response_code(403);
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#090b12"><meta name="application-name" content="MesoAI"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="apple-mobile-web-app-title" content="MesoAI">
<link rel="manifest" href="/meso/app.webmanifest"><link rel="apple-touch-icon" href="/meso/icons/apple-touch-icon.png"><title>MesoAI · Chat</title>
<style>
:root{color-scheme:dark;--bg:#07080d;--panel:#0f121b;--panel2:#151925;--line:#252b3a;--txt:#f6f7fb;--muted:#9299aa;--accent:#d8b4fe;--accent2:#8b5cf6;--good:#63dda9;--warn:#f1c66f;--bad:#ff8b9a}
*{box-sizing:border-box}[hidden]{display:none!important}html,body{margin:0;min-height:100%;background:radial-gradient(circle at 50% -15%,#2b203f 0,#0b0d14 31%,var(--bg) 62%);font:15px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;color:var(--txt)}button,textarea,a{font:inherit}.shell{min-height:100dvh;display:grid;grid-template-columns:260px minmax(0,1fr)}.side{border-right:1px solid var(--line);padding:18px;background:rgba(8,10,16,.86);backdrop-filter:blur(16px);display:flex;flex-direction:column;gap:14px}.brand{display:flex;align-items:center;gap:11px;font-weight:850;font-size:19px}.mark{width:40px;height:40px;border-radius:13px;display:grid;place-items:center;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#111;font-weight:950}.panel{padding:14px;border:1px solid var(--line);border-radius:16px;background:var(--panel)}.label{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);font-weight:750}.status{display:flex;justify-content:space-between;gap:10px;margin-top:9px}.pill{border:1px solid var(--line);border-radius:999px;padding:4px 8px;font-size:11px;color:var(--muted)}.pill.good{color:var(--good);border-color:#315347}.pill.warn{color:var(--warn);border-color:#66552e}.side button,.side a{width:100%;display:block;text-align:left;color:var(--txt);background:#171b27;border:1px solid var(--line);border-radius:11px;padding:10px;text-decoration:none;cursor:pointer}.side .installSide{border-color:#654a8a;background:linear-gradient(180deg,#2e2340,#211a30)}.side form{margin:0}.note{margin-top:auto;font-size:11px;color:var(--muted);line-height:1.55}.main{min-width:0;display:flex;flex-direction:column;min-height:100dvh}.top{height:64px;position:sticky;top:0;z-index:5;border-bottom:1px solid var(--line);background:rgba(7,8,13,.74);backdrop-filter:blur(16px);display:flex;align-items:center;padding:0 20px;gap:12px}.top strong{font-size:15px}.top span{margin-left:auto;color:var(--muted);font-size:12px}.installTop{border:1px solid #654a8a;border-radius:10px;background:#1d1728;color:var(--txt);padding:7px 10px;cursor:pointer;white-space:nowrap}.chat{width:min(920px,100%);margin:0 auto;display:flex;flex-direction:column;flex:1;padding:18px 18px calc(18px + env(safe-area-inset-bottom))}.messages{flex:1;min-height:420px;overflow:auto;padding:28px 4px 18px;display:flex;flex-direction:column;gap:12px}.empty{margin:auto;text-align:center;color:var(--muted);max-width:460px}.empty .orb{width:56px;height:56px;border-radius:18px;margin:0 auto 13px;display:grid;place-items:center;background:linear-gradient(145deg,#241a34,#171322);border:1px solid #44335d;color:var(--accent);font-size:24px}.msg{max-width:min(78%,720px);white-space:pre-wrap;word-break:break-word;border:1px solid var(--line);padding:11px 13px;box-shadow:0 12px 36px rgba(0,0,0,.2)}.msg.user{align-self:flex-end;background:linear-gradient(145deg,#3b2858,#291d42);border-radius:16px 16px 5px 16px}.msg.assistant{align-self:flex-start;background:var(--panel);border-radius:16px 16px 16px 5px}.role{font-size:10px;text-transform:uppercase;letter-spacing:.09em;color:var(--muted);font-weight:800;margin-bottom:5px}.composer{position:sticky;bottom:0;background:rgba(7,8,13,.88);backdrop-filter:blur(16px);border:1px solid var(--line);border-radius:18px;padding:10px;box-shadow:0 -14px 44px rgba(0,0,0,.3)}.composeRow{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:8px;align-items:end}.composer textarea{width:100%;min-height:64px;max-height:180px;resize:vertical;background:#0a0d14;color:var(--txt);border:1px solid var(--line);border-radius:13px;padding:12px;outline:none}.composer textarea:focus{border-color:#6e54a0;box-shadow:0 0 0 3px rgba(139,92,246,.1)}.send,.mic{height:44px;border-radius:12px;color:white;cursor:pointer}.send{padding:0 18px;border:1px solid #7e5cb2;background:linear-gradient(180deg,#7650aa,#583b82)}.mic{width:48px;border:1px solid #4c5263;background:#171b27;font-size:19px}.mic.recording{border-color:#d66c7a;background:#431f27;box-shadow:0 0 0 3px rgba(255,139,154,.12)}.send:disabled,.mic:disabled{opacity:.55;cursor:not-allowed}.composer small{display:block;color:var(--muted);padding:8px 2px 0}.gate{min-height:100dvh;display:grid;place-items:center;padding:20px}.gateCard{width:min(520px,100%);border:1px solid var(--line);border-radius:22px;background:linear-gradient(180deg,var(--panel2),var(--panel));padding:24px;box-shadow:0 24px 80px rgba(0,0,0,.35)}.gateCard h1{margin:0 0 9px;font-size:28px}.gateCard p{color:var(--muted)}.gateCard button{width:100%;height:46px;border:1px solid #7e5cb2;border-radius:12px;color:white;background:linear-gradient(180deg,#7650aa,#583b82);cursor:pointer}.err{padding:10px 12px;border:1px solid #693b45;background:#2a171c;color:#ffb0ba;border-radius:12px;margin:12px 0}.back{color:var(--accent);text-decoration:none}.installSheet{position:fixed;inset:0;z-index:30;display:grid;place-items:end center;background:rgba(0,0,0,.56);padding:16px}.installCard{width:min(520px,100%);position:relative;border:1px solid var(--line);border-radius:20px;background:linear-gradient(180deg,var(--panel2),var(--panel));padding:21px;box-shadow:0 30px 90px rgba(0,0,0,.48);margin-bottom:max(0px,env(safe-area-inset-bottom))}.installCard strong{display:block;font-size:19px;padding-right:38px}.installCard p{color:var(--muted);margin:9px 0 0;line-height:1.6}.installClose{position:absolute;right:12px;top:12px;width:34px;height:34px;border-radius:10px;border:1px solid var(--line);background:#171b27;color:var(--txt);cursor:pointer;font-size:20px}@media(min-width:761px){.installSheet{place-items:center}.installCard{margin-bottom:0}}@media(max-width:760px){.shell{grid-template-columns:1fr}.side{display:none}.top{height:58px;padding:0 13px}.chat{padding:8px 8px calc(8px + env(safe-area-inset-bottom))}.messages{min-height:calc(100dvh - 220px);padding:16px 2px 10px}.msg{max-width:92%}.composeRow{grid-template-columns:auto minmax(0,1fr)}.send{grid-column:1/-1;width:100%}.top span{display:none}.installTop{margin-left:auto;padding:7px 9px}}
</style></head><body>
<?php if (!$authorized): ?>
<div class="gate"><main class="gateCard"><div class="brand"><div class="mark">M</div>MesoAI</div><h1>Private chat</h1>
<?php if (isset($gateError)): ?><div class="err"><?=htmlspecialchars($gateError, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
<?php if ($inviteValid): ?><p>Your private chat invite is valid. Opening it creates a signed browser cookie; the invite itself is deleted after confirmation.</p><form method="post" autocomplete="off"><input type="hidden" name="action" value="authorize"><input type="hidden" name="token" value="<?=htmlspecialchars($queryToken, ENT_QUOTES, 'UTF-8')?>"><button type="submit">Open MesoAI Chat</button></form>
<?php else: ?><p>This page requires a private MesoAI chat invite.</p><a class="back" href="/meso/">Return to MesoAI</a><?php endif; ?></main></div>
<?php else: ?>
<div class="shell"><aside class="side"><div class="brand"><div class="mark">M</div>MesoAI</div>
<div class="panel"><div class="label">Preflight state</div><div class="status"><span>Memory</span><span class="pill">OFF</span></div><div class="status"><span>Persona</span><span class="pill">OFF</span></div><div class="status"><span>Speech to text</span><span class="pill good">LOCAL</span></div><div class="status"><span>Cloned voice</span><span class="pill warn">PENDING</span></div><div class="status"><span>Chat</span><span class="pill good">PRIVATE</span></div></div>
<div class="panel"><button id="newChatBtn" type="button">＋ New conversation</button><button class="installSide" data-install-app type="button" hidden aria-hidden="true" style="margin-top:8px">⬇ Install MesoAI app</button><a href="/meso/" style="margin-top:8px">← Voice Lab</a><form method="post" style="margin-top:8px"><input type="hidden" name="action" value="logout"><button type="submit">Sign out</button></form></div>
<div class="note">Stateless preflight chat. Microphone audio is transcribed locally on MASTER-PC, then deleted. No MesoAI memory lookup, persona simulation, KDT database access, or server-side conversation archive.</div></aside>
<main class="main"><header class="top"><strong>MesoAI · Chat</strong><span id="status">Private text + local STT preflight</span><button class="installTop" data-install-app type="button" hidden aria-hidden="true">Install app</button></header><div class="chat"><section id="messages" class="messages"><div class="empty"><div class="orb">✦</div><strong>Text and microphone chat are ready</strong><div style="margin-top:7px">Microphone input is transcribed locally. Memory, persona and cloned voice remain off during this stage.</div></div></section><section class="composer"><div class="composeRow"><button id="mic" class="mic" type="button" aria-label="Record microphone" aria-pressed="false">🎙</button><textarea id="message" maxlength="8000" placeholder="Message MesoAI…"></textarea><button id="send" class="send" type="button">Send</button></div><small>Tap microphone to start/stop · Local STT only · Current-page context · Memory off · Persona off</small></section></div></main></div>
<div id="installSheet" class="installSheet" hidden aria-hidden="true"><section class="installCard" role="dialog" aria-modal="true" aria-labelledby="installSheetTitle"><button id="installSheetClose" class="installClose" type="button" aria-label="Close install instructions">×</button><strong id="installSheetTitle">Install MesoAI</strong><p id="installSheetText">Install MesoAI on your home screen.</p></section></div>
<script src="/meso/chat/chat.js" defer></script><script src="/meso/pwa/install.js" defer></script>
<?php endif; ?></body></html>
