'use strict';

(() => {
  const labels=['A','B','C','D'];
  const cards=[...document.querySelectorAll('[data-phrase]')];
  const summary=document.querySelector('[data-summary]');
  const winner=document.querySelector('[data-winner]');
  const votes=new Map();
  const cache=new Map();
  let activeAudio=null;
  let activeButton=null;

  function key(phrase,label){return `${phrase}:${label}`;}
  function validAudioUrl(value){
    const u=new URL(String(value||''),location.origin);
    if(u.origin!==location.origin||u.pathname!=='/meso/api/voice-lab-audio.php'||!/^[a-f0-9]{64}$/.test(u.searchParams.get('id')||''))throw new Error('Invalid lab audio URL');
    return u.pathname+u.search;
  }
  function stop(){
    if(activeAudio){try{activeAudio.pause();activeAudio.currentTime=0;}catch(_){}activeAudio=null;}
    if(activeButton){activeButton.classList.remove('active');activeButton.textContent=activeButton.dataset.label;activeButton=null;}
  }
  async function prepare(phrase,label,button,status){
    const k=key(phrase,label);
    if(cache.has(k))return cache.get(k);
    button.disabled=true;button.textContent='…';status.textContent=`Preparing ${label}…`;
    try{
      const response=await fetch('/meso/api/voice-lab.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({phrase_id:phrase,label})});
      if(response.status===403){location.href='/meso/chat/';throw new Error('Authentication refreshed');}
      const body=await response.json();
      if(!response.ok||body?.ok!==true||String(body?.label||'')!==label)throw new Error(body?.error||`HTTP ${response.status}`);
      const url=validAudioUrl(body.audio_url);cache.set(k,url);return url;
    }finally{button.disabled=false;button.textContent=label;}
  }
  async function play(phrase,label,button,status){
    if(activeButton===button&&activeAudio&&!activeAudio.paused){stop();return;}
    stop();
    try{
      const url=await prepare(phrase,label,button,status);
      const audio=new Audio(url);audio.preload='auto';audio.playsInline=true;audio.volume=1;
      activeAudio=audio;activeButton=button;button.classList.add('active');button.textContent='■';status.textContent=`Playing ${label}…`;
      audio.onended=()=>{if(activeAudio===audio){stop();status.textContent='Choose the closest voice below.';}};
      audio.onerror=()=>{if(activeAudio===audio){cache.delete(key(phrase,label));stop();status.textContent='Audio expired. Tap again to regenerate.';}};
      await audio.play();
    }catch(error){stop();status.textContent=`Could not play ${label}: ${String(error?.message||error)}`;}
  }
  function renderSummary(){
    const selected=[...votes.entries()];
    summary.textContent=selected.length?selected.map(([p,l])=>`${p}: ${l}`).join(' · '):'none yet';
    const counts=Object.fromEntries(labels.map(l=>[l,0]));
    for(const [,label] of selected)counts[label]++;
    const ranked=labels.slice().sort((a,b)=>counts[b]-counts[a]);
    const top=ranked[0];
    const tied=ranked.filter(l=>counts[l]===counts[top]);
    winner.textContent=selected.length<4?'Complete all 4 rounds':tied.length===1?`Current winner: ${top} (${counts[top]}/4)`:`Tie: ${tied.join(' / ')} (${counts[top]}/4)`;
  }

  for(const card of cards){
    const phrase=String(card.dataset.phrase||'');
    const plays=card.querySelector('[data-plays]');
    const voteBox=card.querySelector('[data-votes]');
    const status=card.querySelector('.status');
    for(const label of labels){
      const button=document.createElement('button');button.type='button';button.className='play';button.dataset.label=label;button.textContent=label;button.setAttribute('aria-label',`Play anonymous profile ${label}`);button.addEventListener('click',()=>play(phrase,label,button,status));plays.appendChild(button);
      const vote=document.createElement('button');vote.type='button';vote.className='vote';vote.textContent=`Best ${label}`;vote.addEventListener('click',()=>{votes.set(phrase,label);for(const b of voteBox.querySelectorAll('.vote'))b.classList.toggle('active',b===vote);renderSummary();});voteBox.appendChild(vote);
    }
  }
  renderSummary();
  addEventListener('pagehide',stop);
})();
