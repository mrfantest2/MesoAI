'use strict';

(() => {
  const synth=window.speechSynthesis||null;
  const messages=document.getElementById('messages');
  const status=document.getElementById('status');
  const autoToggle=document.getElementById('voiceAutoToggle');
  if(!messages)return;

  const AUTO_KEY='meso.autoVoice.v1';
  let autoVoice=false;
  try{autoVoice=localStorage.getItem(AUTO_KEY)==='1';}catch(_){}
  let activeButton=null,activeNote=null,activeAudio=null,activeAbort=null;

  const baseStatus=()=> 'Private · Memory ON · Meso voice · Local XTTS';
  const voiceLabel=(profile)=>profile==='meso-v2.2'?'Meso Voice v2.2':profile==='meso-v2'?'Meso Voice v2':'Meso voice';

  function refreshAutoToggle(){
    if(autoToggle){
      autoToggle.textContent=autoVoice?'🔊 Auto':'🔈 Voice';
      autoToggle.setAttribute('aria-pressed',autoVoice?'true':'false');
      autoToggle.title=autoVoice?'Play each Meso voice reply automatically when ready':'Prepare Meso voice, but play only when tapped';
    }
    const strip=document.querySelector('[data-mobile-state="voice"]');
    if(strip){
      strip.textContent=autoVoice?'Voice · Auto':'Voice · Manual';
      strip.classList.toggle('good',autoVoice);
    }
  }

  function setReady(button,note,profile=''){
    button.textContent='▶ Play';button.disabled=false;button.setAttribute('aria-pressed','false');
    button.title='Play prepared Meso voice reply';
    note.textContent=` ${voiceLabel(profile)} ready`;
  }

  function setIdle(button=activeButton,note=activeNote){
    if(button){
      if(button._mesoXttsUrl)setReady(button,note||{textContent:''},button._mesoProfile||'');
      else{button.textContent='▶ Play';button.disabled=false;button.setAttribute('aria-pressed','false');}
    }
    activeButton=null;activeNote=null;activeAudio=null;
    if(status)status.textContent=baseStatus();
  }

  function stopPlayback(){
    if(activeAbort){try{activeAbort.abort();}catch(_){}activeAbort=null;}
    if(activeAudio){try{activeAudio.pause();activeAudio.currentTime=0;}catch(_){}}
    if(synth&&(synth.speaking||synth.pending))synth.cancel();
    setIdle();
  }

  function containsArabic(text){return /[\u0600-\u06ff\u0750-\u077f\u08a0-\u08ff]/.test(text);}

  function browserFallback(text,button,note){
    if(!synth||typeof window.SpeechSynthesisUtterance!=='function'){
      setIdle(button,note);note.textContent=' Voice unavailable';return;
    }
    const u=new SpeechSynthesisUtterance(text);
    u.lang=containsArabic(text)?'ar-AE':'en-US';u.rate=.96;
    activeButton=button;activeNote=note;
    button.textContent='■ Stop';button.setAttribute('aria-pressed','true');note.textContent=' Browser fallback';
    u.addEventListener('end',()=>setIdle(button,note),{once:true});
    u.addEventListener('error',()=>setIdle(button,note),{once:true});
    synth.speak(u);
  }

  function createPreparedAudio(url){
    const a=new Audio();a.preload='auto';a.playsInline=true;a.muted=false;a.volume=1;a.src=url;
    try{a.load();}catch(_){}
    return a;
  }

  function clearPrepared(button){
    if(!button)return;
    if(button._mesoXttsAudio){try{button._mesoXttsAudio.pause();}catch(_){}}
    button._mesoXttsAudio=null;button._mesoXttsUrl='';button._mesoProfile='';
  }

  function playPreparedXtts(button,note){
    const url=button._mesoXttsUrl;if(!url)return false;
    if(activeAudio&&activeAudio!==button._mesoXttsAudio){try{activeAudio.pause();activeAudio.currentTime=0;}catch(_){}}
    if(synth&&(synth.speaking||synth.pending))synth.cancel();
    const audio=button._mesoXttsAudio||createPreparedAudio(url);
    button._mesoXttsAudio=audio;activeAudio=audio;activeButton=button;activeNote=note;
    audio.muted=false;audio.volume=1;try{audio.currentTime=0;}catch(_){}
    button.textContent='■ Stop';button.disabled=false;button.setAttribute('aria-pressed','true');
    note.textContent=` ${voiceLabel(button._mesoProfile)}`;
    if(status)status.textContent='Starting · Meso voice…';
    audio.onplaying=()=>{if(activeAudio===audio&&status)status.textContent=`Speaking · ${voiceLabel(button._mesoProfile)} · Local XTTS`;};
    audio.onended=()=>{if(activeAudio===audio)setIdle(button,note);};
    audio.onerror=()=>{
      if(activeAudio===audio){
        activeAudio=null;activeButton=null;activeNote=null;clearPrepared(button);
        button.textContent='▶ Play';button.disabled=false;
        note.textContent=' Meso voice media expired · tap Play to regenerate';
      }
    };
    const p=audio.play();
    if(p&&typeof p.catch==='function')p.catch((error)=>{
      if(activeAudio!==audio)return;
      activeAudio=null;activeButton=null;activeNote=null;
      const name=String(error?.name||'PlaybackError');
      if(name==='NotSupportedError')clearPrepared(button);
      button.textContent='▶ Play';button.disabled=false;button.setAttribute('aria-pressed','false');
      note.textContent=` Playback blocked · ${name}`;
      if(status)status.textContent=`Playback blocked · ${name}`;
    });
    return true;
  }

  function normalizeAudioUrl(value){
    const parsed=new URL(String(value||'').trim(),window.location.origin);
    if(parsed.origin!==window.location.origin||parsed.pathname!=='/meso/api/tts-audio.php'||!/^[a-f0-9]{64}$/.test(parsed.searchParams.get('id')||''))throw new Error('Invalid Meso audio URL');
    return parsed.pathname+parsed.search;
  }

  async function requestVoice(clean,controller){
    const response=await fetch('/meso/api/tts.php',{
      method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body:JSON.stringify({text:clean}),signal:controller.signal
    });
    if(response.status===403)throw new Error('chat_auth_required');
    const payload=await response.json().catch(()=>({}));
    if(response.status===429)throw new Error('tts_busy_retryable');
    const engine=String(payload?.engine||'').toLowerCase();
    const format=String(payload?.format||'').toLowerCase();
    const profile=String(payload?.profile||'').toLowerCase();
    const audioUrl=normalizeAudioUrl(payload?.audio_url);
    if(!response.ok||payload?.ok!==true||engine!=='xtts-v2'||format!=='mp3'||!['meso-a','meso-v2','meso-v2.2'].includes(profile)||!audioUrl.includes('tts-audio.php'))throw new Error(`Meso voice HTTP ${response.status}`);
    return {profile,audioUrl};
  }

  async function prepareXtts(text,button,note,{background=false}={}){
    const clean=String(text||'').trim();
    if(!clean||button._mesoXttsUrl||button._mesoXttsPreparing)return Boolean(button._mesoXttsUrl);
    const controller=new AbortController();
    button._mesoXttsPreparing=true;button._mesoXttsAbort=controller;
    if(!background)activeAbort=controller;
    button.textContent='…';button.disabled=true;note.textContent=' Preparing · Meso voice';
    let timedOut=false;const timer=setTimeout(()=>{timedOut=true;controller.abort();},300000);
    try{
      let prepared=null,attempts=background?1:2;
      for(let attempt=1;attempt<=attempts;attempt++){
        try{prepared=await requestVoice(clean,controller);break;}
        catch(error){
          if(error?.message==='tts_busy_retryable'&&attempt<attempts&&!timedOut){
            note.textContent=' Meso voice busy · retrying…';
            await new Promise(resolve=>setTimeout(resolve,3000));continue;
          }
          throw error;
        }
      }
      if(!prepared)return false;
      button._mesoXttsUrl=prepared.audioUrl;button._mesoProfile=prepared.profile;
      button._mesoXttsAudio=createPreparedAudio(prepared.audioUrl);
      setReady(button,note,prepared.profile);
      if(!background&&status)status.textContent=`${voiceLabel(prepared.profile)} ready · tap Play`;
      if(background&&autoVoice)queueMicrotask(()=>playPreparedXtts(button,note));
      return true;
    }catch(error){
      if(error?.name==='AbortError'&&!timedOut)return false;
      clearPrepared(button);button.textContent='▶ Play';button.disabled=false;button.setAttribute('aria-pressed','false');
      note.textContent=timedOut?' Meso voice preparation timed out':' Meso voice unavailable · browser fallback';
      return false;
    }finally{
      clearTimeout(timer);button._mesoXttsPreparing=false;button._mesoXttsAbort=null;
      if(activeAbort===controller)activeAbort=null;
    }
  }

  async function handlePlay(text,button,note){
    const clean=String(text||'').trim();if(!clean)return;
    if(activeButton===button&&activeAudio&&!activeAudio.paused){stopPlayback();return;}
    if(button._mesoXttsUrl){playPreparedXtts(button,note);return;}
    const prepared=await prepareXtts(clean,button,note,{background:false});
    if(!prepared)browserFallback(clean,button,note);
  }

  function finalizedText(card){
    if(!(card instanceof HTMLElement))return '';
    if(card.classList.contains('streaming')||card.classList.contains('thinking'))return '';
    const explicit=String(card.dataset.plainText||'').trim();
    if(explicit)return explicit;
    const body=card.querySelector('.messageBody');
    return body?String(body.textContent||'').trim():'';
  }

  function decorate(card,autoPrepare=false){
    if(!(card instanceof HTMLElement)||!card.classList.contains('assistant')||!card.classList.contains('msg'))return;
    const text=finalizedText(card);
    if(!text||/^Chat error:/i.test(text)||/^Microphone .*error:/i.test(text))return;
    if(card.dataset.replyAudioReady==='1'){
      if(card.dataset.replyAudioText===text)return;
      const old=card.querySelector('[data-reply-audio-tools]');
      if(old)old.remove();
      card.dataset.replyAudioReady='0';
    }
    card.dataset.replyAudioReady='1';card.dataset.replyAudioText=text;
    const tools=document.createElement('div');tools.dataset.replyAudioTools='1';tools.style.marginTop='9px';
    const button=document.createElement('button');
    button.type='button';button.textContent='▶ Play';button.setAttribute('aria-label','Play assistant reply using Meso voice');
    Object.assign(button.style,{padding:'6px 10px',borderRadius:'9px',border:'1px solid #4c5263',background:'#171b27',color:'#f6f7fb',cursor:'pointer',fontSize:'12px'});
    const note=document.createElement('span');
    note.textContent=autoPrepare?' Preparing · Meso voice':' Meso voice';
    Object.assign(note.style,{marginLeft:'7px',color:'#9299aa',fontSize:'10px'});
    button.addEventListener('click',()=>handlePlay(text,button,note));
    tools.append(button,note);card.appendChild(tools);
    if(autoPrepare)queueMicrotask(()=>prepareXtts(text,button,note,{background:true}));
  }

  function decorateAll(root=messages,autoPrepare=false){
    if(root instanceof HTMLElement&&root.matches('.msg.assistant'))decorate(root,autoPrepare);
    if(root.querySelectorAll)for(const card of root.querySelectorAll('.msg.assistant'))decorate(card,autoPrepare);
  }

  for(const row of document.querySelectorAll('.side .status')){
    const label=row.querySelector('span:first-child'),pill=row.querySelector('.pill');
    if(label&&pill&&String(label.textContent||'').trim()==='Cloned voice'){
      pill.textContent='MESO VOICE';pill.classList.add('good');
    }
  }

  if(autoToggle)autoToggle.addEventListener('click',()=>{
    autoVoice=!autoVoice;
    try{localStorage.setItem(AUTO_KEY,autoVoice?'1':'0');}catch(_){}
    refreshAutoToggle();
  });
  refreshAutoToggle();

  decorateAll();
  new MutationObserver(records=>{
    for(const record of records){
      const owner=record.target instanceof Element?record.target.closest('.msg.assistant'):null;
      if(owner)decorate(owner,false);
      for(const node of record.addedNodes){
        if(node instanceof HTMLElement)decorateAll(node,false);
      }
    }
  }).observe(messages,{childList:true,subtree:true});

  window.addEventListener('meso:reply-finalized',(event)=>{
    const card=event?.detail?.card;
    if(card instanceof HTMLElement)decorate(card,true);
  });

  window.addEventListener('pagehide',stopPlayback);
  window.addEventListener('beforeunload',stopPlayback);
})();
