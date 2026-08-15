<?php declare(strict_types=1); /* MesoAI Phase 1 */ ?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#090b12">
<title>MesoAI</title>
<style>
:root{color-scheme:dark;--bg:#07080d;--panel:#0f121b;--panel2:#151925;--line:#242a39;--txt:#f6f7fb;--muted:#9299aa;--accent:#d8b4fe;--accent2:#8b5cf6;--good:#61d8a6;--shadow:0 24px 80px rgba(0,0,0,.35)}
*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:radial-gradient(circle at 50% -20%,#29203e 0,#0b0d14 30%,var(--bg) 62%);font:15px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;color:var(--txt)}
button,input,textarea{font:inherit}.app{min-height:100dvh;display:grid;grid-template-columns:280px minmax(0,1fr)}
.sidebar{border-right:1px solid var(--line);padding:18px;display:flex;flex-direction:column;background:rgba(8,10,16,.78);backdrop-filter:blur(16px)}
.brand{display:flex;align-items:center;gap:12px;font-weight:800;letter-spacing:-.02em;font-size:20px}.mark{width:40px;height:40px;border-radius:14px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:grid;place-items:center;color:#111;font-weight:950;box-shadow:0 8px 30px rgba(139,92,246,.28)}
.phase{margin-top:22px;padding:14px;border:1px solid var(--line);border-radius:16px;background:var(--panel)}.phase small{color:var(--muted);display:block}.phase strong{display:block;margin:4px 0 8px}.pill{display:inline-flex;align-items:center;gap:7px;border:1px solid #2b4d40;background:#10231d;color:#8ae7bd;border-radius:999px;padding:5px 9px;font-size:12px}.dot{width:7px;height:7px;border-radius:50%;background:var(--good);box-shadow:0 0 12px var(--good)}
.note{margin-top:auto;color:var(--muted);font-size:12px;line-height:1.55}.main{min-width:0;display:flex;flex-direction:column;min-height:100dvh}.top{height:68px;border-bottom:1px solid var(--line);display:flex;align-items:center;padding:0 22px;background:rgba(7,8,13,.55);backdrop-filter:blur(14px);position:sticky;top:0;z-index:5}.top b{font-size:14px}.top span{margin-left:auto;color:var(--muted);font-size:12px}
.chat{width:min(850px,100%);margin:auto;padding:56px 22px 150px}.hero{text-align:center;padding:28px 0 36px}.hero h1{font-size:clamp(32px,5vw,54px);letter-spacing:-.045em;margin:0 0 12px}.hero p{max-width:620px;margin:auto;color:var(--muted);font-size:16px}.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:28px 0}.card{border:1px solid var(--line);border-radius:18px;padding:16px;background:linear-gradient(180deg,var(--panel2),var(--panel));box-shadow:var(--shadow)}.card small{color:var(--muted)}.card strong{display:block;margin-top:8px;font-size:24px}.card em{display:block;color:var(--muted);font-style:normal;font-size:12px;margin-top:3px}
.msg{max-width:690px;border:1px solid var(--line);background:var(--panel);border-radius:22px 22px 22px 6px;padding:16px 18px;margin:18px auto}.msg .who{font-size:12px;color:var(--accent);font-weight:700;margin-bottom:6px}.msg p{margin:0;color:#d7dbe7}.composerWrap{position:fixed;bottom:0;left:280px;right:0;padding:18px 22px calc(18px + env(safe-area-inset-bottom));background:linear-gradient(180deg,transparent,var(--bg) 28%)}.composer{max-width:850px;margin:auto;border:1px solid #32394b;background:#10131c;border-radius:24px;padding:10px 10px 10px 16px;display:flex;align-items:flex-end;gap:10px;box-shadow:0 18px 70px rgba(0,0,0,.5)}textarea{flex:1;resize:none;min-height:42px;max-height:130px;border:0;outline:0;background:transparent;color:var(--txt);padding:10px 4px}button{width:42px;height:42px;border:0;border-radius:14px;background:linear-gradient(135deg,var(--accent),var(--accent2));font-size:18px;cursor:pointer}.disabled{opacity:.55;pointer-events:none}.fine{max-width:850px;margin:7px auto 0;text-align:center;color:#666d7d;font-size:11px}
@media(max-width:760px){.app{grid-template-columns:1fr}.sidebar{display:none}.composerWrap{left:0}.top{height:60px;padding:0 16px}.chat{padding:34px 14px 145px}.cards{grid-template-columns:1fr 1fr}.card:last-child{grid-column:1/-1}.hero{padding-top:18px}.hero h1{font-size:38px}}
</style>
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand"><div class="mark">M</div>MesoAI</div>
    <div class="phase"><small>Current phase</small><strong>Voice fidelity</strong><span class="pill"><i class="dot"></i> Dataset preparation</span></div>
    <div class="note">Private voice material is kept outside the public web root. Memory and persona modelling remain disabled until the voice is accepted.</div>
  </aside>
  <main class="main">
    <header class="top"><b>MesoAI</b><span id="statusText">Checking voice lab…</span></header>
    <section class="chat">
      <div class="hero"><h1>Build the voice first.</h1><p>MesoAI is currently focused only on identifying the cleanest real voice references, comparing synthesis attempts, and improving fidelity.</p></div>
      <div class="cards">
        <div class="card"><small>Maissoun candidates</small><strong id="targetCount">156</strong><em>sender-labelled voice notes</em></div>
        <div class="card"><small>Jamal negatives</small><strong id="negativeCount">36</strong><em>speaker-separation references</em></div>
        <div class="card"><small>Preferred clips</small><strong id="preferredCount">101</strong><em>6–24 second initial window</em></div>
      </div>
      <div class="msg"><div class="who">MesoAI</div><p>Phase 1 is active. Private WhatsApp text is not published here. Chat and memory behavior will be enabled only after the voice pipeline is validated.</p></div>
    </section>
    <div class="composerWrap"><div class="composer disabled" title="Chat is intentionally disabled during voice-only Phase 1"><textarea placeholder="Chat unlocks after voice validation" disabled></textarea><button disabled>↑</button></div><div class="fine">Voice-only Phase 1 · no memory/persona inference</div></div>
  </main>
</div>
<script>
fetch('api/status.php',{cache:'no-store'}).then(r=>r.json()).then(s=>{
  document.getElementById('statusText').textContent=(s.status||'voice_fidelity').replaceAll('_',' ');
  if(s.target_audio!=null) document.getElementById('targetCount').textContent=s.target_audio;
  if(s.negative_audio!=null) document.getElementById('negativeCount').textContent=s.negative_audio;
  if(s.preferred_target_clips!=null) document.getElementById('preferredCount').textContent=s.preferred_target_clips;
}).catch(()=>document.getElementById('statusText').textContent='Voice lab local');
</script>
</body></html>
