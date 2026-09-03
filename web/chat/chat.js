'use strict';

(() => {
  const ACTIVE_CONVERSATION_KEY='meso.activeConversation.v1';
  const $=(id)=>document.getElementById(id);
  const messages=$('messages'),input=$('message'),send=$('send'),mic=$('mic'),status=$('status'),newChatButton=$('newChatBtn');
  if(!messages||!input||!send||!status)return;

  let recorder=null,stream=null,chunks=[],autoStopTimer=null,recording=false,transcribing=false;
  let activePersona='meso-v1';
  let activeGrounding='style-only';
  let activeConversationId='';
  let bootstrapped=false;

  const validId=(value)=>/^[a-f0-9]{64}$/.test(String(value||''));
  const baseStatus=()=>`Private · Persona ${activePersona} · Memory meso-v1 · Local STT`;
  const stateJsonHeaders={'Content-Type':'application/json','Accept':'application/json'};

  function getStoredConversationId(){
    try{const value=localStorage.getItem(ACTIVE_CONVERSATION_KEY)||'';return validId(value)?value:'';}catch(_){return '';}
  }
  function storeConversationId(value){
    try{if(validId(value))localStorage.setItem(ACTIVE_CONVERSATION_KEY,value);else localStorage.removeItem(ACTIVE_CONVERSATION_KEY);}catch(_){}
  }
  function emitConversationChanged(){window.dispatchEvent(new CustomEvent('meso:conversation-changed',{detail:{conversation_id:activeConversationId}}));}
  function showEmpty(title,detail){messages.replaceChildren();const wrap=document.createElement('div');wrap.className='empty';const orb=document.createElement('div');orb.className='orb';orb.textContent='✦';const strong=document.createElement('strong');strong.textContent=title;const body=document.createElement('div');body.style.marginTop='7px';body.textContent=detail;wrap.append(orb,strong,body);messages.appendChild(wrap);}
  function messageMeta(item){if(!item||item.role!=='assistant')return '';const parts=[];if(item.provider)parts.push(String(item.provider));if(item.model)parts.push(String(item.model));if(item.persona_version)parts.push(`persona · ${item.persona_version}`);if(item.persona_grounding&&item.persona_grounding!=='off')parts.push(String(item.persona_grounding));if(Number(item.persona_evidence_count||0)>0)parts.push(`evidence ${Number(item.persona_evidence_count)}`);return parts.join(' · ');}
  function addMessage(role,text,meta=''){const empty=messages.querySelector('.empty');if(empty)messages.replaceChildren();const card=document.createElement('div');card.className=`msg ${role}`;const label=document.createElement('div');label.className='role';label.textContent=meta?`${role} · ${meta}`:role;const body=document.createElement('div');body.textContent=String(text??'');card.append(label,body);messages.appendChild(card);messages.scrollTop=messages.scrollHeight;}
  function setBusy(value,label=''){send.disabled=value||!bootstrapped;input.disabled=value||!bootstrapped;if(mic&&!recording)mic.disabled=value||!bootstrapped;status.textContent=label||(value?'Thinking…':baseStatus());}
  function stopTracks(){if(stream)for(const track of stream.getTracks())track.stop();stream=null;}
  function resetRecorderUi(){recording=false;clearTimeout(autoStopTimer);autoStopTimer=null;if(mic){mic.classList.remove('recording');mic.setAttribute('aria-pressed','false');mic.textContent='🎙';}stopTracks();}

  function applyPersonaState(state){
    activePersona=String(state?.version||'off');
    activeGrounding=String(state?.grounding||'off');
    const evidenceMode=activeGrounding==='evidence-retrieval';
    for(const row of document.querySelectorAll('.side .status')){
      const label=row.querySelector('span:first-child');const pill=row.querySelector('.pill');
      if(!label||!pill)continue;
      const name=String(label.textContent||'').trim();
      if(name==='Persona'){
        pill.textContent=activePersona==='meso-v2'?'MESO v2':activePersona==='meso-v1'?'MESO v1':'OFF';
        pill.classList.toggle('good',activePersona!=='off');
      }else if(name==='Memory'){
        pill.textContent=state?.memory_enabled===false?'OFF':'MESO v1';
        pill.classList.toggle('good',state?.memory_enabled!==false);
      }
    }
    const empty=messages.querySelector('.empty');
    if(empty){
      const strong=empty.querySelector('strong');const detail=empty.querySelector('div:last-child');
      if(strong)strong.textContent=activePersona==='meso-v2'?'Meso Persona v2 is ready':activePersona==='meso-v1'?'Meso Persona v1 is ready':'MesoAI chat is ready';
      if(detail)detail.textContent=evidenceMode
        ?`Historical evidence retrieval is available from ${Number(state?.record_count||0).toLocaleString()} Maissoun-authored records. Conversation Memory v1 is stored separately.`
        :'Source-grounded Persona and Conversation Memory v1 are separate private stores.';
    }
    const composerNote=document.querySelector('.composer small');
    if(composerNote)composerNote.textContent=evidenceMode
      ?'Local STT + Meso voice · Persona MESO v2 · Memory MESO v1 · Historical evidence separate'
      :'Local STT + Meso voice · Persona MESO v1 · Memory MESO v1 · Source-grounded style';
    if(!send.disabled)status.textContent=baseStatus();
  }

  async function readJson(response){let body={};try{body=await response.json();}catch(_){body={ok:false,error:'invalid_json'};}if(response.status===403){throw new Error(String(body?.error||'chat_auth_required'));}return body;}

  async function loadPersonaState(){
    try{
      const response=await fetch('/meso/api/persona-status.php',{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
      const body=await readJson(response);
      if(response.ok&&body.ok)applyPersonaState(body);
    }catch(error){if(error?.message!=='chat_auth_required')status.textContent='Private · Memory meso-v1 · Persona status unavailable';}
  }

  async function createConversation(title='New conversation'){
    const response=await fetch('/meso/api/conversations.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:stateJsonHeaders,body:JSON.stringify({title})});
    const body=await readJson(response);
    const id=String(body?.conversation?.id||'');
    if(!response.ok||!body.ok||!validId(id))throw new Error(body.message||body.error||`HTTP ${response.status}`);
    activeConversationId=id;
    storeConversationId(id);
    emitConversationChanged();
    return id;
  }

  async function loadMessages(conversationId){
    if(!validId(conversationId))throw new Error('invalid_conversation_id');
    const url=`/meso/api/messages.php?conversation_id=${encodeURIComponent(conversationId)}&limit=100`;
    const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
    const body=await readJson(response);
    if(!response.ok||!body.ok)throw new Error(body.error||`HTTP ${response.status}`);
    messages.replaceChildren();
    const items=Array.isArray(body.items)?body.items:[];
    if(items.length===0){showEmpty('Private conversation ready','Conversation Memory v1 is ON. Historical Persona evidence remains a separate store.');return;}
    for(const item of items){const role=String(item?.role||'');if(role!=='user'&&role!=='assistant')continue;addMessage(role,String(item?.content||''),messageMeta(item));}
  }

  async function activateConversation(conversationId){
    const id=String(conversationId||'');
    if(!validId(id))throw new Error('invalid_conversation_id');
    if(recording||transcribing)throw new Error('conversation_busy');
    setBusy(true,'Loading private conversation…');
    try{
      await loadMessages(id);
      activeConversationId=id;
      storeConversationId(id);
      emitConversationChanged();
      return id;
    }finally{
      setBusy(false);
      input.focus();
    }
  }

  async function ensureConversation(){
    const stored=getStoredConversationId();
    if(stored){
      try{await loadMessages(stored);activeConversationId=stored;emitConversationChanged();return stored;}catch(error){if(error?.message==='chat_auth_required')throw error;storeConversationId('');}
    }
    const id=await createConversation();
    await loadMessages(id);
    return id;
  }

  async function newChat(){
    if(recording||transcribing||send.disabled)return '';
    setBusy(true,'Creating private conversation…');
    try{
      const id=await createConversation();
      await loadMessages(id);
      showEmpty('New conversation','A new private conversation is ready. Previous conversations remain stored privately on MASTER-PC.');
      return id;
    }catch(error){
      addMessage('assistant',`Could not create conversation: ${error.message}`,'system');
      return '';
    }finally{
      setBusy(false);input.focus();
    }
  }

  async function sendText(){
    const text=input.value.trim();
    if(!text||send.disabled||!validId(activeConversationId))return;
    input.value='';
    addMessage('user',text);
    setBusy(true,`Thinking · Persona ${activePersona} · Memory v1…`);
    try{
      const response=await fetch('/meso/api/chat.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:stateJsonHeaders,body:JSON.stringify({conversation_id:activeConversationId,message:text})});
      const body=await readJson(response);
      if(!response.ok||!body.ok)throw new Error(body.message||body.error||`HTTP ${response.status}`);
      if(!validId(String(body.conversation_id||''))||String(body.conversation_id)!==activeConversationId)throw new Error('conversation_response_mismatch');
      if(!validId(String(body.message_id||'')))throw new Error('invalid_message_id');
      activePersona=String(body.persona||'off');
      activeGrounding=String(body.persona_grounding||'off');
      const persona=`persona · ${activePersona}`;
      const grounding=activeGrounding&&activeGrounding!=='off'?` · ${activeGrounding}`:'';
      const evidence=Number(body.persona_evidence||0)>0?` · evidence ${Number(body.persona_evidence)}`:'';
      const remembered=Number(body.memory_items_used||0)>0?` · memory ${Number(body.memory_items_used)}`:'';
      const meta=`${body.provider||''}${body.model?` · ${body.model}`:''} · ${persona}${grounding}${evidence}${remembered}`;
      addMessage('assistant',body.reply,meta);
      window.dispatchEvent(new CustomEvent('meso:memory-changed',{detail:{conversation_id:activeConversationId}}));
    }catch(error){
      addMessage('assistant',`Chat error: ${error.message}`,'system');
    }finally{
      setBusy(false);input.focus();
    }
  }

  function bestRecorderMime(){if(!window.MediaRecorder||typeof MediaRecorder.isTypeSupported!=='function')return '';return ['audio/webm;codecs=opus','audio/ogg;codecs=opus','audio/mp4','audio/webm'].find(v=>MediaRecorder.isTypeSupported(v))||'';}
  async function transcribeBlob(blob){transcribing=true;setBusy(true,'Transcribing locally…');try{const response=await fetch('/meso/api/transcribe.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':blob.type||'audio/webm','Accept':'application/json'},body:blob});const body=await readJson(response);if(!response.ok||!body.ok||!String(body.transcript||'').trim())throw new Error(body.error||`HTTP ${response.status}`);input.value=String(body.transcript).trim();transcribing=false;setBusy(false,`Transcribed locally${body.language?` · ${body.language}`:''}`);await sendText();}catch(error){if(error?.message!=='chat_auth_required')addMessage('assistant',`Microphone transcription error: ${error.message}`,'local STT');}finally{transcribing=false;if(!send.disabled)status.textContent=baseStatus();if(mic)mic.disabled=false;}}
  async function startRecording(){if(!mic||recording||transcribing||send.disabled)return;if(!navigator.mediaDevices?.getUserMedia||!window.MediaRecorder){addMessage('assistant','This browser does not provide microphone recording support.','local STT');return;}try{stream=await navigator.mediaDevices.getUserMedia({audio:true});const mime=bestRecorderMime();recorder=mime?new MediaRecorder(stream,{mimeType:mime}):new MediaRecorder(stream);chunks=[];recorder.addEventListener('dataavailable',e=>{if(e.data&&e.data.size>0)chunks.push(e.data);});recorder.addEventListener('stop',async()=>{const type=recorder?.mimeType||mime||'audio/webm';const blob=new Blob(chunks,{type});chunks=[];recorder=null;resetRecorderUi();if(!blob.size){addMessage('assistant','No microphone audio was captured.','local STT');setBusy(false);return;}await transcribeBlob(blob);},{once:true});recorder.start(250);recording=true;mic.classList.add('recording');mic.setAttribute('aria-pressed','true');mic.textContent='■';send.disabled=true;input.disabled=true;status.textContent='Recording locally… tap ■ to stop';autoStopTimer=setTimeout(()=>stopRecording(),60000);}catch(error){resetRecorderUi();setBusy(false);addMessage('assistant',`Microphone unavailable: ${error.message}`,'local STT');}}
  function stopRecording(){if(!recording||!recorder)return;try{if(recorder.state!=='inactive')recorder.stop();}catch(error){resetRecorderUi();setBusy(false);addMessage('assistant',`Could not stop microphone recording: ${error.message}`,'local STT');}}

  async function bootstrapChat(){
    setBusy(true,'Loading private conversation…');
    try{
      await loadPersonaState();
      await ensureConversation();
      bootstrapped=true;
      setBusy(false);
      input.focus();
    }catch(error){
      if(error?.message!=='chat_auth_required'){
        showEmpty('Conversation unavailable','MesoAI could not initialize private Conversation Memory v1.');
        status.textContent=`Memory unavailable · ${error.message}`;
      }
    }
  }

  window.mesoActiveConversationId=()=>activeConversationId;
  window.mesoChatBridge={
    activateConversation,
    newConversation:newChat,
    reloadActive:()=>validId(activeConversationId)?activateConversation(activeConversationId):Promise.reject(new Error('invalid_conversation_id')),
  };
  send.addEventListener('click',sendText);
  if(newChatButton)newChatButton.addEventListener('click',()=>{
    const controller=window.mesoConversationController;
    if(controller&&typeof controller.newConversation==='function')controller.newConversation().catch(error=>addMessage('assistant',`Could not create conversation: ${error.message}`,'system'));
    else newChat();
  });
  if(mic)mic.addEventListener('click',()=>recording?stopRecording():startRecording());
  input.addEventListener('keydown',e=>{if(e.key==='Enter'&&(e.ctrlKey||e.metaKey)){e.preventDefault();sendText();}});
  window.addEventListener('pagehide',stopTracks);
  status.textContent='Private · Loading Memory v1…';
  send.disabled=true;input.disabled=true;if(mic)mic.disabled=true;
  bootstrapChat();
})();

const replyAudioScript=document.createElement('script');
replyAudioScript.src='/meso/chat/reply-audio.js';
replyAudioScript.defer=true;
document.head.appendChild(replyAudioScript);
