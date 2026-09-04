'use strict';

(() => {
  const labels=['A','B','C','D','E'];
  const phraseEl=document.querySelector('#phrase');
  const phraseText=document.querySelector('[data-phrase-text]');
  const laneEl=document.querySelector('[data-batch]');
  const laneCountEl=document.querySelector('[data-batch-count]');
  const statusEl=document.querySelector('[data-status]');
  const anchorButton=document.querySelector('[data-anchor]');
  const playButtons=[...document.querySelectorAll('[data-label]')];
  const choiceButtons=[...document.querySelectorAll('[data-choice]')];
  const rejectButton=document.querySelector('[data-reject]');
  const prevButton=document.querySelector('[data-prev]');
  const nextButton=document.querySelector('[data-next]');
  const phrases={
    'ar-casual':'مرحبا، كيفك اليوم؟ شو أخبارك؟ خبرني شو صار معك.',
    'ar-warm':'والله اشتقتلك، احكيلي شوي عن يومك وكيف كانت أمورك.',
    'en-casual':'Hey, how are you today? Tell me what happened and how your day went.'
  };
  const names={
    'ar-casual':'Arabic · casual',
    'ar-warm':'Arabic · warm/emotional',
    'en-casual':'English · casual'
  };
  let lanes=[];
  let laneIndex=0;
  let activeAudio=null;
  let activeButton=null;
  const cache=new Map();

  function currentLane(){return lanes[laneIndex]||phraseEl.value||'ar-casual';}
  function cacheKey(kind,label=''){return `${currentLane()}:${kind}:${label}`;}
  function stop(){
    if(activeAudio){try{activeAudio.pause();activeAudio.currentTime=0;}catch(_){}activeAudio=null;}
    if(activeButton){
      activeButton.classList.remove('active');
      if(activeButton===anchorButton)activeButton.textContent='Play Real Meso reference';
      else activeButton.textContent=activeButton.dataset.label||'Play';
      activeButton=null;
    }
  }
  function renderLane(){
    stop();
    const id=currentLane();
    phraseEl.value=id;
    phraseText.textContent=phrases[id]||'';
    phraseText.dir=id.startsWith('en-')?'ltr':'rtl';
    laneEl.textContent=String(laneIndex+1);
    laneCountEl.textContent=String(Math.max(1,lanes.length));
    prevButton.disabled=laneIndex<=0;
    nextButton.disabled=laneIndex>=lanes.length-1;
    for(const b of choiceButtons)b.classList.remove('active');
    statusEl.textContent='Start with the Real Meso reference, then compare Generated candidate A–E.';
  }
  function validAudioUrl(value){
    const u=new URL(String(value||''),location.origin);
    if(u.origin!==location.origin||u.pathname!=='/meso/api/voice-lab-v24-audio.php'||!/^[a-f0-9]{64}$/.test(u.searchParams.get('id')||''))throw new Error('Invalid Voice v2.4 audio URL');
    return u.pathname+u.search;
  }
  async function post(payload){
    const response=await fetch('/meso/api/voice-lab-v24.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
    if(response.status===403){location.href='/meso/chat/';throw new Error('Authentication refreshed');}
    const body=await response.json().catch(()=>({}));
    if(!response.ok||body?.ok!==true)throw new Error(body?.error||`HTTP ${response.status}`);
    return body;
  }
  async function prepareAnchor(){
    const k=cacheKey('anchor');
    if(cache.has(k))return cache.get(k);
    anchorButton.disabled=true;anchorButton.textContent='Preparing…';statusEl.textContent='Preparing Real Meso reference…';
    try{
      const body=await post({action:'anchor',lane:currentLane()});
      if(String(body.kind)!=='anchor'||String(body.lane)!==currentLane())throw new Error('Anchor response mismatch');
      const url=validAudioUrl(body.audio_url);cache.set(k,url);return url;
    }finally{anchorButton.disabled=false;anchorButton.textContent='Play Real Meso reference';}
  }
  async function prepareCandidate(label,button){
    const k=cacheKey('candidate',label);
    if(cache.has(k))return cache.get(k);
    button.disabled=true;button.textContent='…';statusEl.textContent=`Preparing Generated candidate ${label}…`;
    try{
      const body=await post({action:'synthesize',lane:currentLane(),label});
      if(String(body.kind)!=='candidate'||String(body.lane)!==currentLane()||String(body.label)!==label)throw new Error('Candidate response mismatch');
      const url=validAudioUrl(body.audio_url);cache.set(k,url);return url;
    }finally{button.disabled=false;button.textContent=label;}
  }
  async function playUrl(url,button,playingText){
    if(activeButton===button&&activeAudio&&!activeAudio.paused){stop();return;}
    stop();
    const audio=new Audio(url);audio.preload='auto';audio.playsInline=true;audio.volume=1;
    activeAudio=audio;activeButton=button;button.classList.add('active');button.textContent='■';statusEl.textContent=playingText;
    audio.onended=()=>{if(activeAudio===audio){stop();statusEl.textContent='Compare against the real Meso recording, then choose or reject.';}};
    audio.onerror=()=>{if(activeAudio===audio){stop();statusEl.textContent='Audio expired. Tap again to regenerate.';}};
    await audio.play();
  }
  async function playAnchor(){
    try{await playUrl(await prepareAnchor(),anchorButton,'Playing Real Meso reference…');}
    catch(error){stop();cache.delete(cacheKey('anchor'));statusEl.textContent=`Could not play real reference: ${String(error?.message||error)}`;}
  }
  async function playCandidate(label,button){
    try{await playUrl(await prepareCandidate(label,button),button,`Playing Generated candidate ${label}…`);}
    catch(error){stop();cache.delete(cacheKey('candidate',label));statusEl.textContent=`Could not play candidate ${label}: ${String(error?.message||error)}`;}
  }
  function nextLane(){if(laneIndex<lanes.length-1){laneIndex++;renderLane();}}
  async function vote(choice){
    try{
      await post({action:'vote',lane:currentLane(),choice});
      statusEl.textContent=choice==='reject'?'Lane rejected. Moving on…':`Choice ${choice} recorded. Moving on…`;
      for(const b of choiceButtons)b.classList.toggle('active',b.dataset.choice===choice);
      setTimeout(nextLane,350);
    }catch(error){statusEl.textContent=`Could not save choice: ${String(error?.message||error)}`;}
  }
  async function init(){
    try{
      const body=await post({action:'status'});
      if(String(body.version||'')!=='meso-v2.4')throw new Error('Unexpected Voice Lab version');
      lanes=(Array.isArray(body.lanes)?body.lanes:[]).map(x=>typeof x==='string'?x:String(x?.id||'')).filter(x=>Object.hasOwn(phrases,x));
      if(lanes.length<1)throw new Error('No v2.4 identity lanes available');
      phraseEl.innerHTML='';
      for(const id of lanes){const option=document.createElement('option');option.value=id;option.textContent=names[id]||id;phraseEl.append(option);}
      laneIndex=0;renderLane();
    }catch(error){statusEl.textContent=`Voice v2.4 unavailable: ${String(error?.message||error)}`;anchorButton.disabled=true;for(const b of playButtons)b.disabled=true;}
  }

  anchorButton.addEventListener('click',playAnchor);
  for(const b of playButtons)b.addEventListener('click',()=>playCandidate(String(b.dataset.label||''),b));
  for(const b of choiceButtons)b.addEventListener('click',()=>vote(String(b.dataset.choice||'')));
  rejectButton.addEventListener('click',()=>vote('reject'));
  prevButton.addEventListener('click',()=>{if(laneIndex>0){laneIndex--;renderLane();}});
  nextButton.addEventListener('click',nextLane);
  phraseEl.addEventListener('change',()=>{const index=lanes.indexOf(phraseEl.value);if(index>=0){laneIndex=index;renderLane();}});
  addEventListener('pagehide',stop);
  init();
})();
