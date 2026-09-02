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
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#090b12">
<meta name="application-name" content="MesoAI">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="MesoAI">
<link rel="manifest" href="/meso/app.webmanifest">
<link rel="apple-touch-icon" href="/meso/icons/apple-touch-icon.png">
<title>MesoAI · Chat</title>
<style>
:root{color-scheme:dark;--bg:#07080d;--panel:#0f121b;--panel2:#151925;--line:#252b3a;--txt:#f6f7fb;--muted:#9299aa;--accent:#d8b4fe;--accent2:#8b5cf6;--good:#63dda9;--warn:#f1c66f;--bad:#ff8b9a}
*{box-sizing:border-box}[hidden]{display:none!important}
html,body{margin:0;min-height:100%;background:radial-gradient(circle at 50% -15%,#2b203f 0,#0b0d14 31%,var(--bg) 62%);font:15px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;color:var(--txt)}
button,textarea,a{font:inherit}
.shell{min-height:100dvh;display:grid;grid-template-columns:280px minmax(0,1fr)}
.side{border-right:1px solid var(--line);padding:16px;background:rgba(8,10,16,.86);backdrop-filter:blur(16px);display:flex;flex-direction:column;gap:12px;min-height:100dvh;max-height:100dvh;position:sticky;top:0;overflow:hidden}
.brand{display:flex;align-items:center;gap:11px;font-weight:850;font-size:19px}.mark{width:40px;height:40px;border-radius:13px;display:grid;place-items:center;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#111;font-weight:950}
.panel{padding:13px;border:1px solid var(--line);border-radius:16px;background:var(--panel)}.label{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);font-weight:750}.status{display:flex;justify-content:space-between;gap:10px;margin-top:9px}.pill{border:1px solid var(--line);border-radius:999px;padding:4px 8px;font-size:11px;color:var(--muted)}.pill.good{color:var(--good);border-color:#315347}.pill.warn{color:var(--warn);border-color:#66552e}
.side button,.side a{width:100%;display:block;text-align:left;color:var(--txt);background:#171b27;border:1px solid var(--line);border-radius:11px;padding:10px;text-decoration:none;cursor:pointer}.side .installSide{border-color:#654a8a;background:linear-gradient(180deg,#2e2340,#211a30)}.side form{margin:0}.note{margin-top:auto;font-size:11px;color:var(--muted);line-height:1.55}
.conversationPanel{display:flex;flex-direction:column;min-height:0;flex:1}.conversationPanelHead{display:flex;align-items:center;gap:8px}.conversationPanelHead .label{flex:1}.side .conversationPanelHead button{width:34px;height:32px;padding:0;text-align:center;font-size:18px}.conversationList{display:grid;gap:7px;overflow:auto;margin-top:10px;min-height:0}.conversationList.archived{max-height:145px}.conversationRow{border:1px solid transparent;border-radius:12px;background:#111520;padding:8px;cursor:pointer;outline:none}.conversationRow:hover,.conversationRow:focus{border-color:#3a4358}.conversationRow.active{border-color:#6e54a0;background:#1b1728}.conversationMain{display:grid;gap:2px;min-width:0}.conversationMain strong{font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.conversationMain span{font-size:9px;color:var(--muted)}.conversationActions{display:flex;gap:4px;margin-top:6px}.side .conversationAction,.conversationAction{width:28px;height:26px;display:inline-grid;place-items:center;padding:0;border:1px solid #333b4d;border-radius:8px;background:#171b27;color:var(--muted);cursor:pointer}.conversationAction:hover{color:var(--txt);border-color:#5a6682}.conversationEmpty{color:var(--muted);font-size:11px;padding:8px 2px}.archiveBlock{margin-top:10px;border-top:1px solid var(--line);padding-top:8px}.archiveBlock summary{cursor:pointer;color:var(--muted);font-size:11px;user-select:none}
.main{min-width:0;display:flex;flex-direction:column;min-height:100dvh}.top{height:64px;position:sticky;top:0;z-index:5;border-bottom:1px solid var(--line);background:rgba(7,8,13,.74);backdrop-filter:blur(16px);display:flex;align-items:center;padding:0 20px;gap:8px}.top strong{font-size:15px}.top span{margin-left:auto;color:var(--muted);font-size:12px}.installTop,.memoryTop,.conversationTop{border:1px solid #654a8a;border-radius:10px;background:#1d1728;color:var(--txt);padding:7px 10px;cursor:pointer;white-space:nowrap}.memoryTop{border-color:#3f4d67;background:#151b27}.conversationTop{display:none;border-color:#3f4d67;background:#151b27}
.chat{width:min(920px,100%);margin:0 auto;display:flex;flex-direction:column;flex:1;padding:18px 18px calc(18px + env(safe-area-inset-bottom))}.messages{flex:1;min-height:420px;overflow:auto;padding:28px 4px 18px;display:flex;flex-direction:column;gap:12px}.empty{margin:auto;text-align:center;color:var(--muted);max-width:460px}.empty .orb{width:56px;height:56px;border-radius:18px;margin:0 auto 13px;display:grid;place-items:center;background:linear-gradient(145deg,#241a34,#171322);border:1px solid #44335d;color:var(--accent);font-size:24px}.msg{max-width:min(78%,720px);white-space:pre-wrap;word-break:break-word;border:1px solid var(--line);padding:11px 13px;box-shadow:0 12px 36px rgba(0,0,0,.2)}.msg.user{align-self:flex-end;background:linear-gradient(145deg,#3b2858,#291d42);border-radius:16px 16px 5px 16px}.msg.assistant{align-self:flex-start;background:var(--panel);border-radius:16px 16px 16px 5px}.role{font-size:10px;text-transform:uppercase;letter-spacing:.09em;color:var(--muted);font-weight:800;margin-bottom:5px}
.composer{position:sticky;bottom:0;background:rgba(7,8,13,.88);backdrop-filter:blur(16px);border:1px solid var(--line);border-radius:18px;padding:10px;box-shadow:0 -14px 44px rgba(0,0,0,.3)}.composeRow{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:8px;align-items:end}.composer textarea{width:100%;min-height:64px;max-height:180px;resize:vertical;background:#0a0d14;color:var(--txt);border:1px solid var(--line);border-radius:13px;padding:12px;outline:none}.composer textarea:focus{border-color:#6e54a0;box-shadow:0 0 0 3px rgba(139,92,246,.1)}.send,.mic{height:44px;border-radius:12px;color:white;cursor:pointer}.send{padding:0 18px;border:1px solid #7e5cb2;background:linear-gradient(180deg,#7650aa,#583b82)}.mic{width:48px;border:1px solid #4c5263;background:#171b27;font-size:19px}.mic.recording{border-color:#d66c7a;background:#431f27;box-shadow:0 0 0 3px rgba(255,139,154,.12)}.send:disabled,.mic:disabled{opacity:.55;cursor:not-allowed}.composer small{display:block;color:var(--muted);padding:8px 2px 0}
.gate{min-height:100dvh;display:grid;place-items:center;padding:20px}.gateCard{width:min(520px,100%);border:1px solid var(--line);border-radius:22px;background:linear-gradient(180deg,var(--panel2),var(--panel));padding:24px;box-shadow:0 24px 80px rgba(0,0,0,.35)}.gateCard h1{margin:0 0 9px;font-size:28px}.gateCard p{color:var(--muted)}.gateCard button{width:100%;height:46px;border:1px solid #7e5cb2;border-radius:12px;color:white;background:linear-gradient(180deg,#7650aa,#583b82);cursor:pointer}.err{padding:10px 12px;border:1px solid #693b45;background:#2a171c;color:#ffb0ba;border-radius:12px;margin:12px 0}.back{color:var(--accent);text-decoration:none}
.installSheet,.conversationDrawer{position:fixed;inset:0;z-index:30;display:grid;place-items:end center;background:rgba(0,0,0,.56);padding:16px}.installCard,.drawerCard{width:min(520px,100%);position:relative;border:1px solid var(--line);border-radius:20px;background:linear-gradient(180deg,var(--panel2),var(--panel));padding:21px;box-shadow:0 30px 90px rgba(0,0,0,.48);margin-bottom:max(0px,env(safe-area-inset-bottom))}.installCard strong{display:block;font-size:19px;padding-right:38px}.installCard p{color:var(--muted);margin:9px 0 0;line-height:1.6}.installClose{position:absolute;right:12px;top:12px;width:34px;height:34px;border-radius:10px;border:1px solid var(--line);background:#171b27;color:var(--txt);cursor:pointer;font-size:20px}.memoryList{display:grid;gap:9px;max-height:min(52dvh,520px);overflow:auto;margin-top:14px}.memoryClear{width:100%;margin-top:14px;border:1px solid #693b45;border-radius:11px;padding:10px;background:#2a171c;color:#ffb0ba;cursor:pointer}.drawerCard{max-height:86dvh;display:flex;flex-direction:column}.drawerTitle{font-size:19px;font-weight:800;padding-right:40px}.drawerNew{margin:14px 0 4px;border:1px solid #654a8a;border-radius:11px;background:#211a30;color:var(--txt);padding:11px;cursor:pointer}.drawerSection{margin-top:12px;min-height:0}.drawerSection .conversationList{max-height:30dvh}.drawerSection .conversationRow{background:#111520}
@media(min-width:761px){.installSheet{place-items:center}.installCard{margin-bottom:0}.conversationDrawer{display:none!important}}
@media(max-width:760px){.shell{grid-template-columns:1fr}.side{display:none}.top{height:58px;padding:0 10px}.chat{padding:8px 8px calc(8px + env(safe-area-inset-bottom))}.messages{min-height:calc(100dvh - 220px);padding:16px 2px 10px}.msg{max-width:92%}.composeRow{grid-template-columns:auto minmax(0,1fr)}.send{grid-column:1/-1;width:100%}.top span{display:none}.installTop,.memoryTop,.conversationTop{padding:7px 9px}.conversationTop{display:inline-block}.memoryTop{margin-left:auto}.top strong{white-space:nowrap}.installTop{display:none!important}}
</style>
</head>
<body>
<?php if (!$authorized): ?>
<div class="gate"><main class="gateCard"><div class="brand"><div class="mark">M</div>MesoAI</div><h1>Private chat</h1>
<?php if (isset($gateError)): ?><div class="err"><?=htmlspecialchars($gateError, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
<?php if ($inviteValid): ?>
<p>Your private chat invite is valid. Opening it creates a signed browser cookie; the invite itself is deleted after confirmation.</p>
<form method="post" autocomplete="off"><input type="hidden" name="action" value="authorize"><input type="hidden" name="token" value="<?=htmlspecialchars($queryToken, ENT_QUOTES, 'UTF-8')?>"><button type="submit">Open MesoAI Chat</button></form>
<?php else: ?>
<p>This page requires a private MesoAI chat invite.</p><a class="back" href="/meso/">Return to MesoAI</a>
<?php endif; ?>
</main></div>
<?php else: ?>
<div class="shell">
<aside class="side">
  <div class="brand"><div class="mark">M</div>MesoAI</div>
  <div class="panel">
    <div class="label">Private state</div>
    <div class="status"><span>Memory</span><span class="pill good">MESO v1</span></div>
    <div class="status"><span>Persona</span><span class="pill good">MESO v1</span></div>
    <div class="status"><span>Speech to text</span><span class="pill good">LOCAL</span></div>
    <div class="status"><span>Cloned voice</span><span class="pill good">MESO VOICE</span></div>
    <div class="status"><span>Chat</span><span class="pill good">PRIVATE</span></div>
  </div>
  <div class="panel conversationPanel">
    <div class="conversationPanelHead"><div class="label">Conversations</div><button id="newChatBtn" type="button" aria-label="New conversation">＋</button></div>
    <div id="conversationList" class="conversationList" aria-live="polite"></div>
    <details class="archiveBlock"><summary>Archived conversations</summary><div id="archivedConversationList" class="conversationList archived"></div></details>
  </div>
  <div class="panel">
    <button class="installSide" data-install-app type="button" hidden aria-hidden="true">⬇ Install MesoAI app</button>
    <a href="/meso/" style="margin-top:8px">← Voice Lab</a>
    <form method="post" style="margin-top:8px"><input type="hidden" name="action" value="logout"><button type="submit">Sign out</button></form>
  </div>
  <div class="note">Conversation Memory MESO v1 is private and stored separately from Persona historical evidence. Local STT and Meso voice run on MASTER-PC.</div>
</aside>
<main class="main">
  <header class="top">
    <button id="conversationDrawerToggle" class="conversationTop" type="button" aria-label="Open conversations">☰</button>
    <strong>MesoAI · Chat</strong>
    <span id="status">Private · Persona meso-v1 · Memory meso-v1 · Local STT</span>
    <button id="memoryBtn" class="memoryTop" type="button">Memory</button>
    <button class="installTop" data-install-app type="button" hidden aria-hidden="true">Install app</button>
  </header>
  <div class="chat">
    <section id="messages" class="messages"><div class="empty"><div class="orb">✦</div><strong>Meso Persona is ready</strong><div style="margin-top:7px">Conversation Memory v1 is loading. Historical Persona evidence remains separate from generated conversation content.</div></div></section>
    <section class="composer"><div class="composeRow"><button id="mic" class="mic" type="button" aria-label="Record microphone" aria-pressed="false">🎙</button><textarea id="message" maxlength="8000" placeholder="Message MesoAI…"></textarea><button id="send" class="send" type="button">Send</button></div><small>Local STT + Meso voice · Persona + Memory are separate private stores</small></section>
  </div>
</main>
</div>

<div id="conversationDrawer" class="conversationDrawer" hidden aria-hidden="true">
  <section class="drawerCard" role="dialog" aria-modal="true" aria-labelledby="conversationDrawerTitle">
    <button id="conversationDrawerClose" class="installClose" type="button" aria-label="Close conversations">×</button>
    <div id="conversationDrawerTitle" class="drawerTitle">Conversations</div>
    <button class="drawerNew" data-new-conversation type="button">＋ New conversation</button>
    <div class="drawerSection"><div class="label">Recent</div><div class="conversationList" data-conversation-list="active"></div></div>
    <details class="drawerSection"><summary>Archived</summary><div class="conversationList" data-conversation-list="archived"></div></details>
  </section>
</div>

<div id="memorySheet" class="installSheet" hidden aria-hidden="true">
  <section class="installCard" role="dialog" aria-modal="true" aria-labelledby="memorySheetTitle">
    <button id="memoryClose" class="installClose" type="button" aria-label="Close memory inspector">×</button>
    <strong id="memorySheetTitle">Conversation Memory</strong>
    <p>Verified memory can be recalled in future conversations. Candidate memory is never recalled until you verify it. This store is separate from historical Persona evidence.</p>
    <div id="memoryList" class="memoryList"></div>
    <button id="memoryClear" class="memoryClear" type="button">Clear conversation memory</button>
  </section>
</div>

<div id="installSheet" class="installSheet" hidden aria-hidden="true"><section class="installCard" role="dialog" aria-modal="true" aria-labelledby="installSheetTitle"><button id="installSheetClose" class="installClose" type="button" aria-label="Close install instructions">×</button><strong id="installSheetTitle">Install MesoAI</strong><p id="installSheetText">Install MesoAI on your home screen.</p></section></div>
<script src="/meso/chat/chat.js" defer></script>
<script src="/meso/chat/conversations.js" defer></script>
<script src="/meso/chat/memory.js" defer></script>
<script src="/meso/pwa/install.js" defer></script>
<?php endif; ?>
</body>
</html>
