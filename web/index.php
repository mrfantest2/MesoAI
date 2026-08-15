<?php declare(strict_types=1); /* MesoAI voice lab */ ?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#090b12"><title>MesoAI Voice Lab</title>
<style>
:root{color-scheme:dark;--bg:#07080d;--panel:#0f121b;--panel2:#151925;--line:#252b3a;--txt:#f6f7fb;--muted:#9299aa;--accent:#d8b4fe;--accent2:#8b5cf6;--good:#63dda9;--warn:#f1c66f}
*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:radial-gradient(circle at 50% -15%,#2b203f 0,#0b0d14 31%,var(--bg) 62%);font:15px/1.45 Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;color:var(--txt)}
.app{min-height:100dvh;display:grid;grid-template-columns:280px minmax(0,1fr)}.side{border-right:1px solid var(--line);padding:20px;display:flex;flex-direction:column;background:rgba(8,10,16,.82);backdrop-filter:blur(16px)}
.brand{display:flex;align-items:center;gap:12px;font-size:20px;font-weight:850}.mark{width:42px;height:42px;border-radius:14px;display:grid;place-items:center;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#111;font-weight:950}.badge{display:inline-flex;align-items:center;gap:7px;border:1px solid #315347;background:#10241e;color:#91e9c0;border-radius:999px;padding:6px 10px;font-size:12px}.dot{width:7px;height:7px;border-radius:50%;background:var(--good)}
.sideCard{margin-top:22px;padding:15px;border:1px solid var(--line);border-radius:17px;background:var(--panel)}.sideCard small,.muted{color:var(--muted)}.sideCard strong{display:block;margin:5px 0 10px}.sideNote{margin-top:auto;color:var(--muted);font-size:12px;line-height:1.55}
.main{min-width:0}.top{height:68px;position:sticky;top:0;z-index:5;display:flex;align-items:center;padding:0 24px;border-bottom:1px solid var(--line);background:rgba(7,8,13,.64);backdrop-filter:blur(16px)}.top span{margin-left:auto;color:var(--muted);font-size:12px}.wrap{width:min(980px,100%);margin:auto;padding:48px 22px 70px}.hero{padding:10px 0 30px}.hero h1{font-size:clamp(34px,5vw,56px);letter-spacing:-.05em;margin:0 0 12px}.hero p{max-width:700px;color:var(--muted);font-size:16px;margin:0}
.metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.card{border:1px solid var(--line);border-radius:18px;padding:16px;background:linear-gradient(180deg,var(--panel2),var(--panel));box-shadow:0 20px 60px rgba(0,0,0,.28)}.card small{color:var(--muted)}.card strong{display:block;font-size:27px;margin-top:7px}.card em{display:block;color:var(--muted);font-size:12px;font-style:normal;margin-top:3px}
.section{margin-top:22px;border:1px solid var(--line);border-radius:20px;background:var(--panel);overflow:hidden}.section h2{font-size:15px;margin:0;padding:16px 18px;border-bottom:1px solid var(--line)}.steps{padding:0 18px}.step{display:grid;grid-template-columns:34px 1fr auto;gap:12px;align-items:center;padding:15px 0;border-bottom:1px solid #1d2230}.step:last-child{border-bottom:0}.n{width:30px;height:30px;border-radius:10px;background:#1a1f2d;display:grid;place-items:center;font-weight:800;color:var(--accent)}.step b{display:block}.step small{color:var(--muted)}.ok{color:var(--good);font-size:12px}.lock{color:var(--warn);font-size:12px}
.alert{margin-top:18px;padding:14px 16px;border:1px solid #5a4724;background:#211b10;border-radius:16px;color:#e9d29d}.alert b{color:#ffd98a}.footer{margin-top:22px;color:#6f7687;font-size:12px;text-align:center}
@media(max-width:820px){.app{grid-template-columns:1fr}.side{display:none}.top{height:60px;padding:0 16px}.wrap{padding:30px 14px 50px}.metrics{grid-template-columns:1fr 1fr}.step{grid-template-columns:34px 1fr}.step>span:last-child{grid-column:2}}
</style></head><body>
<div class="app"><aside class="side"><div class="brand"><div class="mark">M</div>MesoAI</div><div class="sideCard"><small>Mode</small><strong>APPLY</strong><span class="badge"><i class="dot"></i> Voice fidelity</span></div><div class="sideNote">MesoAI is isolated from KDT. Raw WhatsApp audio and private text remain outside the public web root and outside Git.</div></aside>
<main class="main"><header class="top"><b>MesoAI · Voice Lab</b><span id="statusText">Loading status…</span></header><div class="wrap">
<div class="hero"><h1>Voice first.</h1><p>All source voice notes have now gone through the deeper acoustic pass. The first 20-reference private pack is prepared; synthesis is the only remaining gated step before engine comparison.</p></div>
<div class="metrics"><div class="card"><small>Maissoun audio</small><strong id="target">156</strong><em>sender-labelled clips</em></div><div class="card"><small>Jamal negatives</small><strong id="negative">36</strong><em>speaker separation</em></div><div class="card"><small>Deep usable</small><strong id="passed">130</strong><em>after acoustic analysis</em></div><div class="card"><small>Reference set</small><strong id="selected">20</strong><em>private normalized pack</em></div></div>
<div class="section"><h2>Phase 1 pipeline</h2><div class="steps">
<div class="step"><div class="n">1</div><div><b>Sender separation</b><small>Maissoun vs Jamal from 1-to-1 WhatsApp metadata</small></div><span class="ok">READY</span></div>
<div class="step"><div class="n">2</div><div><b>Deep acoustic screening</b><small>Duration, silence, noise floor/SNR proxy, clipping, spectral consistency and pitch diversity</small></div><span class="ok" id="deep">192 ANALYZED</span></div>
<div class="step"><div class="n">3</div><div><b>Reference normalization</b><small>Derived mono 24 kHz PCM16 WAV; originals remain immutable</small></div><span class="ok" id="norm">PREPARED</span></div>
<div class="step"><div class="n">4</div><div><b>Voice profile build</b><small>SHA256-backed local profile with provenance</small></div><span class="ok" id="profile">PREPARED</span></div>
<div class="step"><div class="n">5</div><div><b>XTTS / Fish synthesis comparison</b><small>Matched Arabic test phrases, engine A/B output and speaker-fidelity scoring</small></div><span class="lock" id="synth">LOCKED</span></div>
</div></div>
<div class="alert"><b>Authorization required:</b> before generating a clone of a real person’s voice, MesoAI requires an explicit record that Maissoun authorized this voice-cloning use. Preparation can continue, but synthesis remains disabled until that authorization is confirmed.</div>
<div class="footer">Memory: disabled · Persona: disabled · Public audio: disabled · Private dataset stays off-web</div>
</div></main></div>
<script>
fetch('api/status.php',{cache:'no-store'}).then(r=>r.json()).then(s=>{
 const text=v=>String(v??'').replaceAll('_',' ');
 document.getElementById('statusText').textContent=text(s.status||'deep_reference_pack_ready_authorization_pending');
 if(s.target_audio!=null) target.textContent=s.target_audio;
 if(s.negative_audio!=null) negative.textContent=s.negative_audio;
 if(s.deep_quality_usable!=null) passed.textContent=s.deep_quality_usable;
 if(s.selected_references!=null) selected.textContent=s.selected_references;
 if(s.deep_analyzed!=null) deep.textContent=s.deep_analyzed+' ANALYZED';
 if(s.normalization) norm.textContent=text(s.normalization).toUpperCase();
 if(s.profile_builder) profile.textContent=text(s.profile_builder).toUpperCase();
 if(s.synthesis) synth.textContent=text(s.synthesis).toUpperCase();
}).catch(()=>statusText.textContent='voice lab local');
</script></body></html>
