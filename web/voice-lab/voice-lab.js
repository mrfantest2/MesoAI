'use strict';

(() => {
  const labels=['A','B','C','D','E'];
  const phraseEl=document.querySelector('#phrase');
  const phraseText=document.querySelector('[data-phrase-text]');
  const batchEl=document.querySelector('[data-batch]');
  const batchCountEl=document.querySelector('[data-batch-count]');
  const statusEl=document.querySelector('[data-status]');
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
  let batch=0;
  let batchCount=1;
  let activeAudio=null;
  let activeButton=null;
  const cache=new Map();

  function cacheKey(label){return `${batch}:${phraseEl.value}:${label}`;}
  function stop(){
    if(activeAudio){try{activeAudio.pause();activeAudio.currentTime=0;}catch(_){}activeAudio=null;}
    if(activeButton){activeButton.classList.remove('active');activeButton.textContent=activeButton.dataset.label;activeButton=null;}
  }
  function renderBatch(){
    stop();
    batchEl.textContent=String(batch+1);
    batchCountEl.textContent=String(batchCount);
    prevButton.disabled=batch<=0;
    nextButton.disabled=batch>=batchCount-1;
    for(const b of choiceButtons)b.classList.remove('active');
    statusEl.textContent='Listen to all five, then choose the closest voice or reject the batch.';
  }
  function validAudioUrl(value){
    const u=new URL(String(value||''),location.origin);
    if(u.origin!==location.origin||u.pathname!=='/meso/api/voice-lab-v23-audio.php'||!/^[a-f0-9]{64}$/.test(u.searchParams.get('id')||''))throw new Error('Invalid sweep audio URL');
    return u.pathname+u.search;
  }
  async function post(payload){
    const response=await fetch('/meso/api/voice-lab-v23.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
    if(response.status===403){location.href='/meso/chat/';throw new Error('Authentication refreshed');}
    const body=await response.json().catch(()=>({}));
    if(!response.ok||body?.ok!==true)throw new Error(body?.error||`HTTP ${response.status}`);
    return body;
  }
  async function prepare(label,button){
    const k=cacheKey(label);
    if(cache.has(k))return cache.get(k);
    button.disabled=true;button.textContent='…';statusEl.textContent=`Preparing ${label}…`;
    try{
      const body=await post({action:'synthesize',batch,label,phrase_id:phraseEl.value});
      if(Number(body.batch)!==batch||String(body.label)!==label)throw new Error('Sweep response mismatch');
      const url=validAudioUrl(body.audio_url);cache.set(k,url);return url;
    }finally{button.disabled=false;button.textContent=label;}
  }
  async function play(label,button){
    if(activeButton===button&&activeAudio&&!activeAudio.paused){stop();return;}
    stop();
    try{
      const url=await prepare(label,button);
      const audio=new Audio(url);audio.preload='auto';audio.playsInline=true;audio.volume=1;
      activeAudio=audio;activeButton=button;button.classList.add('active');button.textContent='■';statusEl.textContent=`Playing ${label}…`;
      audio.onended=()=>{if(activeAudio===audio){stop();statusEl.textContent='Compare the five voices, then choose or reject.';}};
      audio.onerror=()=>{if(activeAudio===audio){cache.delete(cacheKey(label));stop();statusEl.textContent='Audio expired. Tap again to regenerate.';}};
      await audio.play();
    }catch(error){stop();statusEl.textContent=`Could not play ${label}: ${String(error?.message||error)}`;}
  }
  function nextBatch(){if(batch<batchCount-1){batch++;renderBatch();}}
  async function vote(choice){
    try{
      const body=await post({action:'vote',batch,choice});
      statusEl.textContent=choice==='reject'?'Batch rejected. Moving on…':`Choice ${choice} recorded. Moving on…`;
      for(const b of choiceButtons)b.classList.toggle('active',b.dataset.choice===choice);
      setTimeout(nextBatch,350);
      return body;
    }catch(error){statusEl.textContent=`Could not save choice: ${String(error?.message||error)}`;}
  }
  async function rejectAll(){await vote('reject');}
  async function init(){
    try{
      const body=await post({action:'status'});
      if(String(body.version||'')!=='meso-v2.3')throw new Error('Unexpected Voice Lab version');
      batchCount=Math.max(1,Number(body.batch_count)||1);renderBatch();
    }catch(error){statusEl.textContent=`Voice sweep unavailable: ${String(error?.message||error)}`;for(const b of playButtons)b.disabled=true;}
  }

  for(const b of playButtons)b.addEventListener('click',()=>play(String(b.dataset.label||''),b));
  for(const b of choiceButtons)b.addEventListener('click',()=>vote(String(b.dataset.choice||'')));
  rejectButton.addEventListener('click',rejectAll);
  prevButton.addEventListener('click',()=>{if(batch>0){batch--;renderBatch();}});
  nextButton.addEventListener('click',nextBatch);
  phraseEl.addEventListener('change',()=>{phraseText.textContent=phrases[phraseEl.value]||'';phraseText.dir=phraseEl.value.startsWith('en-')?'ltr':'rtl';stop();statusEl.textContent='Phrase changed. Generate the five candidates again.';});
  addEventListener('pagehide',stop);
  init();
})();
