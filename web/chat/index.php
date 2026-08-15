<?php
declare(strict_types=1);
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'chat_auth.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

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
<meta name="theme-color" content="#090b12"><title>MesoAI · Chat</title>
<style>
:root{color-scheme:dark;--bg:#07080d;--panel:#0f121b;--panel2:#151925;--line:#252b3a;--txt:#f6f7fb;--muted:#9299aa;--accent:#d8b4fe;--accent2:#8b5cf6;--good:#63dda9;--warn:#f1c66f;--bad:#ff8b9a}
*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:radial-gradient(circle at 50% -15%,#2b203f 0,#0b0d14 31%,var(--bg) 62%);font:15px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;color:var(--txt)}button,textarea,a{font:inherit}.shell{min-height:100dvh;display:grid;grid-template-columns:260px minmax(0,1fr)}.side{border-right:1px solid var(--line);padding:18px;background:rgba(8,10,16,.86);backdrop-filter:blur(16px);display:flex;flex-direction:column;gap:14px}.brand{display:flex;align-items:center;gap:11px;font-weight:850;font-size:19px}.mark{width:40px;height:40px;border-radius:13px;display:grid;place-items:center;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#111;font-weight:950}.panel{padding:14px;border:1px solid var(--line);border-radius:16px;background:var(--panel)}.label{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);font-weight:750}.status{display:flex;justify-content:space-between;gap:10px;margin-top:9px}.pill{border:1px solid var(--line);border-radius:999px;padding:4px 8px;font-size:11px;color:var(--muted)}.pill.good{color:var(--good);border-color:#315347}.pill.warn{color:var(--warn);border-color:#66552e}.side button,.side a{width:100%;display:block;text-align:left;color:var(--txt);background:#171b27;border:1px solid var(--line);border-radius:11px;padding:10px;text-decoration:none;cursor:pointer}.side form{margin:0}.note{margin-top:auto;font-size:11px;color:var(--muted);line-height:1.55}.main{min-width:0;display:flex;flex-direction:column;min-height:100dvh}.top{height:64px;position:sticky;top:0;z-index:5;border-bottom:1px solid var(--line);background:rgba(7,8,13,.74);backdrop-filter:blur(16px);display:flex;align-items:center;padding:0 20px;gap:12px}.top strong{font-size:15px}.top span{margin-left:auto;color:var(--muted);font-size:12px}.chat{width:min(920px,100%);margin:0 auto;display:flex;flex-direction:column;flex:1;padding:18px 18px calc(18px + env(safe-area-inset-bottom))}.messages{flex:1;min-height:420px;overflow:auto;padding:28px 4px 18px;display:flex;flex-direction:column;gap:12px}.empty{margin:auto;text-align:center;color:var(--muted);max-width:460px}.empty .orb{width:56px;height:56px;border-radius:18px;margin:0 auto 13px;display:grid;place-items:center;background:linear-gradient(145deg,#241a34,#171322);border:1px solid #44335d;color:var(--accent);font-size:24px}.msg{max-width:min(78%,720px);white-space:pre-wrap;word-break:break-word;border:1px solid var(--line);padding:11px 13px;box-shadow:0 12px 36px rgba(0,0,0,.2)}.msg.user{align-self:flex-end;background:linear-gradient(145deg,#3b2858,#291d42);border-radius:16px 16px 5px 16px}.msg.assistant{align-self:flex-start;background:var(--panel);border-radius:16px 16px 16px 5px}.role{font-size:10px;text-transform:uppercase;letter-spacing:.09em;color:var(--muted);font-weight:800;margin-bottom:5px}.composer{position:sticky;bottom:0;background:rgba(7,8,13,.88);backdrop-filter:blur(16px);border:1px solid var(--line);border-radius:18px;padding:10px;box-shadow:0 -14px 44px rgba(0,0,0,.3)}.composeRow{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:end}.composer textarea{width:100%;min-height:64px;max-height:180px;resize:vertical;background:#0a0d14;color:var(--txt);border:1px solid var(--line);border-radius:13px;padding:12px;outline:none}.composer textarea:focus{border-color:#6e54a0;box-shadow:0 0 0 3px rgba(139,92,246,.1)}.send{height:44px;padding:0 18px;border:1px solid #7e5cb2;border-radius:12px;color:white;background:linear-gradient(180deg,#7650aa,#583b82);cursor:pointer}.send:disabled{opacity:.55;cursor:not-allowed}.composer small{display:block;color:var(--muted);padding:8px 2px 0}.gate{min-height:100dvh;display:grid;place-items:center;padding:20px}.gateCard{width:min(520px,100%);border:1px solid var(--line);border-radius:22px;background:linear-gradient(180deg,var(--panel2),var(--panel));padding:24px;box-shadow:0 24px 80px rgba(0,0,0,.35)}.gateCard h1{margin:0 0 9px;font-size:28px}.gateCard p{color:var(--muted)}.gateCard button{width:100%;height:46px;border:1px solid #7e5cb2;border-radius:12px;color:white;background:linear-gradient(180deg,#7650aa,#583b82);cursor:pointer}.err{padding:10px 12px;border:1px solid #693b45;background:#2a171c;color:#ffb0ba;border-radius:12px;margin:12px 0}.back{color:var(--accent);text-decoration:none}@media(max-width:760px){.shell{grid-template-columns:1fr}.side{display:none}.top{height:58px;padding:0 13px}.chat{padding:8px 8px calc(8px + env(safe-area-inset-bottom))}.messages{min-height:calc(100dvh - 220px);padding:16px 2px 10px}.msg{max-width:92%}.composeRow{grid-template-columns:1fr}.send{width:100%}.top span{display:none}}
</style></head><body>
<?php if (!$authorized): ?>
<div class="gate"><main class="gateCard"><div class="brand"><div class="mark">M</div>MesoAI</div><h1>Private chat</h1>
<?php if (isset($gateError)): ?><div class="err"><?=htmlspecialchars($gateError, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
<?php if ($inviteValid): ?><p>Your private chat invite is valid. Opening it creates a signed browser cookie; the invite itself is deleted after confirmation.</p><form method="post"><input type="hidden" name="action" value="authorize"><input type="hidden" name="token" value="<?=htmlspecialchars($queryToken, ENT_QUOTES, 'UTF-8')?>"><button type="submit">Open MesoAI Chat</button></form>
<?php else: ?><p>This page requires a private MesoAI chat invite.</p><a class="back" href="/meso/">Return to MesoAI</a><?php endif; ?></main></div>
<?php else: ?>
<div class="shell"><aside class="side"><div class="brand"><div class="mark">M</div>MesoAI</div>
<div class="panel"><div class="label">Preflight state</div><div class="status"><span>Memory</span><span class="pill">OFF</span></div><div class="status"><span>Persona</span><span class="pill">OFF</span></div><div class="status"><span>Voice</span><span class="pill warn">PENDING</span></div><div class="status"><span>Chat</span><span class="pill good">PRIVATE</span></div></div>
<div class="panel"><button type="button" onclick="newChat()">＋ New conversation</button><a href="/meso/" style="margin-top:8px">← Voice Lab</a><form method="post" style="margin-top:8px"><input type="hidden" name="action" value="logout"><button type="submit">Sign out</button></form></div>
<div class="note">Stateless preflight chat. No MesoAI memory lookup, no persona simulation, no KDT database access, and no server-side conversation archive.</div></aside>
<main class="main"><header class="top"><strong>MesoAI · Chat</strong><span id="status">Private text preflight</span></header><div class="chat"><section id="messages" class="messages"><div class="empty"><div class="orb">✦</div><strong>Text chat is ready</strong><div style="margin-top:7px">This stage is intentionally generic. MesoAI is not yet using Maissoun memory, persona, or cloned voice.</div></div></section><section class="composer"><div class="composeRow"><textarea id="message" maxlength="8000" placeholder="Message MesoAI…"></textarea><button id="send" class="send" type="button" onclick="sendText()">Send</button></div><small>Current-page context only · Memory off · Persona off · Voice pending</small></section></div></main></div>
<script>
'use strict';
const history=[],$=id=>document.getElementById(id);
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function add(role,text,meta=''){const root=$('messages');if(root.querySelector('.empty'))root.innerHTML='';const d=document.createElement('div');d.className='msg '+role;d.innerHTML='<div class="role">'+esc(role)+(meta?' · '+esc(meta):'')+'</div>'+esc(text);root.appendChild(d);root.scrollTop=root.scrollHeight}
function busy(v){$('send').disabled=v;$('message').disabled=v;$('status').textContent=v?'Thinking…':'Private text preflight'}
function newChat(){history.length=0;$('messages').innerHTML='<div class="empty"><div class="orb">✦</div><strong>New conversation</strong><div style="margin-top:7px">No history was retained.</div></div>';$('message').focus()}
async function sendText(){const text=$('message').value.trim();if(!text)return;const prior=history.slice(-12);$('message').value='';add('user',text);history.push({role:'user',content:text});busy(true);try{const r=await fetch('/meso/api/chat.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({message:text,history:prior})});let b={};try{b=await r.json()}catch(_){b={ok:false,error:'invalid_json'}}if(r.status===403){location.reload();return}if(!r.ok||!b.ok)throw new Error(b.message||b.error||('HTTP '+r.status));add('assistant',b.reply,(b.provider||'')+(b.model?' · '+b.model:''));history.push({role:'assistant',content:b.reply})}catch(e){add('assistant','Chat error: '+e.message,'system')}finally{busy(false);$('message').focus()}}
$('message').addEventListener('keydown',e=>{if(e.key==='Enter'&&(e.ctrlKey||e.metaKey)){e.preventDefault();sendText()}});$('message').focus();
</script>
<?php endif; ?></body></html>
