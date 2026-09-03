'use strict';

(() => {
  const ACTIVE_CONVERSATION_KEY='meso.activeConversation.v1';
  const $=(id)=>document.getElementById(id);
  const messages=$('messages'),input=$('message'),send=$('send'),mic=$('mic'),status=$('status'),newChatButton=$('newChatBtn');
  if(!messages||!input||!send||!status)return;

  if(!document.querySelector('link[data-meso-chat-v2]')){
    const css=document.createElement('link');css.rel='stylesheet';css.href='/meso/chat/chat-v2.css';css.dataset.mesoChatV2='1';document.head.appendChild(css);
  }

  let recorder=null,stream=null,chunks=[],autoStopTimer=null,recording=false,transcribing=false;
  let activePersona='meso-v1',activeGrounding='style-only',activeEvidenceCount=0,activeConversationId='',bootstrapped=false;
  let generationController=null,generationStopped=false;

  const validId=(value)=>/^[a-f0-9]{64}$/.test(String(value||''));
  const stateJsonHeaders={'Content-Type':'application/json','Accept':'application/json'};
  const baseStatus=()=>`Private · Persona ${activePersona} · Historical evidence ${activeEvidenceCount} · Conversation memory MESO v1 · STT local · Voice Meso`;

  function ensureStopButton(){
    let button=$('stopGeneration');
    if(button)return button;
    const row=document.querySelector('.composeRow');
    if(!row)return null;
    button=document.createElement('button');
    button.id='stopGeneration';button.type='button';button.className='send stopGeneration';button.textContent='Stop';button.hidden=true;
    row.appendChild(button);
    return button;
  }
  const stopButton=ensureStopButton();

  function ensureStateRows(){
    const panel=document.querySelector('.side .panel');
    if(!panel)return;
    const rows=[...panel.querySelectorAll('.status')];
    const byName=(name)=>rows.find(row=>String(row.querySelector('span:first-child')?.textContent||'').trim()===name);
    const memory=byName('Memory');if(memory){const label=memory.querySelector('span:first-child');if(label)label.textContent='Conversation memory';}
    if(!byName('Historical evidence')){
      const persona=byName('Persona');
      if(persona){
        const row=document.createElement('div');row.className='status';
        const label=document.createElement('span');label.textContent='Historical evidence';
        const pill=document.createElement('span');pill.className='pill';pill.textContent='OFF';
        row.append(label,pill);persona.insertAdjacentElement('afterend',row);
      }
    }
  }
  ensureStateRows();

  function getStoredConversationId(){try{const value=localStorage.getItem(ACTIVE_CONVERSATION_KEY)||'';return validId(value)?value:'';}catch(_){return '';}}
  function storeConversationId(value){try{if(validId(value))localStorage.setItem(ACTIVE_CONVERSATION_KEY,value);else localStorage.removeItem(ACTIVE_CONVERSATION_KEY);}catch(_){}}
  function emitConversationChanged(){window.dispatchEvent(new CustomEvent('meso:conversation-changed',{detail:{conversation_id:activeConversationId}}));}
  function showEmpty(title,detail){messages.replaceChildren();const wrap=document.createElement('div');wrap.className='empty';const orb=document.createElement('div');orb.className='orb';orb.textContent='✦';const strong=document.createElement('strong');strong.textContent=title;const body=document.createElement('div');body.style.marginTop='7px';body.textContent=detail;wrap.append(orb,strong,body);messages.appendChild(wrap);}
  function messageMeta(item){if(!item||item.role!=='assistant')return '';const parts=[];if(item.provider)parts.push(String(item.provider));if(item.model)parts.push(String(item.model));if(item.persona_version)parts.push(`persona · ${item.persona_version}`);if(item.persona_grounding&&item.persona_grounding!=='off')parts.push(String(item.persona_grounding));if(Number(item.persona_evidence_count||0)>0)parts.push(`evidence ${Number(item.persona_evidence_count)}`);return parts.join(' · ');}

  function addMessage(role,text,meta='',options={}){
    const empty=messages.querySelector('.empty');if(empty)messages.replaceChildren();
    const card=document.createElement('div');card.className=`msg ${role}`;
    const label=document.createElement('div');label.className='role';label.textContent=meta?`${role} · ${meta}`:role;
    const body=document.createElement('div');body.className='messageBody';
    const plain=String(text??'');
    if(role==='assistant'&&typeof window.mesoRenderAssistant==='function'&&options.render!==false)window.mesoRenderAssistant(body,plain);else body.textContent=plain;
    card.append(label,body);
    const userMessageId=String(options?.userMessageId||'');
    if(role==='assistant'&&options.actions!==false&&typeof window.mesoAttachMessageActions==='function')window.mesoAttachMessageActions(card,{text:plain,onRegenerate:validId(userMessageId)?()=>regenerateMessage(userMessageId):undefined});
    messages.appendChild(card);messages.scrollTop=messages.scrollHeight;return {card,body,label};
  }

  function createStreamingCard(){const view=addMessage('assistant','',`streaming · persona ${activePersona}`,{actions:false,render:false});view.card.classList.add('streaming');return view;}
  function finalizeStreamingCard(view,text,meta,userMessageId){
    if(!view)return addMessage('assistant',text,meta,{userMessageId});
    view.card.classList.remove('streaming');view.label.textContent=meta?`assistant · ${meta}`:'assistant';
    view.body.replaceChildren();
    if(typeof window.mesoRenderAssistant==='function')window.mesoRenderAssistant(view.body,text);else view.body.textContent=text;
    view.card.dataset.plainText=text;
    if(typeof window.mesoAttachMessageActions==='function')window.mesoAttachMessageActions(view.card,{text,onRegenerate:validId(userMessageId)?()=>regenerateMessage(userMessageId):undefined});
    messages.scrollTop=messages.scrollHeight;return view;
  }

  function setBusy(value,label=''){send.disabled=value||!bootstrapped;input.disabled=value||!bootstrapped;if(mic&&!recording)mic.disabled=value||!bootstrapped;status.textContent=label||(value?'Thinking…':baseStatus());}
  function setGenerationUi(active,label=''){
    const row=document.querySelector('.composeRow');if(row)row.classList.toggle('chatV2Generating',active);
    if(stopButton){stopButton.hidden=!active;stopButton.disabled=!active;}
    setBusy(active,label);
  }
  function stopTracks(){if(stream)for(const track of stream.getTracks())track.stop();stream=null;}
  function resetRecorderUi(){recording=false;clearTimeout(autoStopTimer);autoStopTimer=null;if(mic){mic.classList.remove('recording');mic.setAttribute('aria-pressed','false');mic.textContent='🎙';}stopTracks();}

  function applyPersonaState(state){
    activePersona=String(state?.version||'off');activeGrounding=String(state?.grounding||'off');activeEvidenceCount=Number(state?.record_count||0);
    ensureStateRows();
    for(const row of document.querySelectorAll('.side .status')){
      const label=row.querySelector('span:first-child'),pill=row.querySelector('.pill');if(!label||!pill)continue;
      const name=String(label.textContent||'').trim();
      if(name==='Persona'){pill.textContent=activePersona==='meso-v2'?'MESO v2':activePersona==='meso-v1'?'MESO v1':'OFF';pill.classList.toggle('good',activePersona!=='off');}
      else if(name==='Conversation memory'){pill.textContent=state?.memory_enabled===false?'OFF':'MESO v1';pill.classList.toggle('good',state?.memory_enabled!==false);}
      else if(name==='Historical evidence'){pill.textContent=activeGrounding==='evidence-retrieval'?activeEvidenceCount.toLocaleString():'OFF';pill.classList.toggle('good',activeGrounding==='evidence-retrieval');}
    }
    const empty=messages.querySelector('.empty');
    if(empty){const strong=empty.querySelector('strong'),detail=empty.querySelector('div:last-child');if(strong)strong.textContent=activePersona==='meso-v2'?'Meso Persona v2 is ready':activePersona==='meso-v1'?'Meso Persona v1 is ready':'MesoAI chat is ready';if(detail)detail.textContent=activeGrounding==='evidence-retrieval'?`Historical evidence retrieval is available from ${activeEvidenceCount.toLocaleString()} Maissoun-authored records. Conversation memory remains separate.`:'Persona and Conversation memory are separate private stores.';}
    const composerNote=document.querySelector('.composer small');if(composerNote)composerNote.textContent='Local STT + Meso voice · Persona · Historical evidence · Conversation memory are independent private state';
    if(!send.disabled)status.textContent=baseStatus();
  }

  async function readJson(response){let body={};try{body=await response.json();}catch(_){body={ok:false,error:'invalid_json'};}if(response.status===403)throw new Error(String(body?.error||'chat_auth_required'));return body;}
  async function loadPersonaState(){try{const response=await fetch('/meso/api/persona-status.php',{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const body=await readJson(response);if(response.ok&&body.ok)applyPersonaState(body);}catch(error){if(error?.message!=='chat_auth_required')status.textContent='Private · Persona status unavailable';}}

  async function createConversation(title='New conversation'){
    const response=await fetch('/meso/api/conversations.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:stateJsonHeaders,body:JSON.stringify({title})});const body=await readJson(response);const id=String(body?.conversation?.id||'');if(!response.ok||!body.ok||!validId(id))throw new Error(body.message||body.error||`HTTP ${response.status}`);activeConversationId=id;storeConversationId(id);emitConversationChanged();return id;
  }
  async function loadMessages(conversationId){
    if(!validId(conversationId))throw new Error('invalid_conversation_id');
    const response=await fetch(`/meso/api/messages.php?conversation_id=${encodeURIComponent(conversationId)}&limit=100`,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const body=await readJson(response);if(!response.ok||!body.ok)throw new Error(body.error||`HTTP ${response.status}`);
    messages.replaceChildren();const items=Array.isArray(body.items)?body.items:[];if(items.length===0){showEmpty('Private conversation ready','Conversation memory v1 is ON. Historical Persona evidence remains a separate store.');return;}
    let pendingUserMessageId='';
    for(const item of items){const role=String(item?.role||'');if(role!=='user'&&role!=='assistant')continue;const itemId=String(item?.id||'');if(role==='user'){pendingUserMessageId=validId(itemId)?itemId:'';addMessage(role,String(item?.content||''),messageMeta(item));}else{addMessage(role,String(item?.content||''),messageMeta(item),{userMessageId:pendingUserMessageId});pendingUserMessageId='';}}
  }
  async function activateConversation(conversationId){const id=String(conversationId||'');if(!validId(id))throw new Error('invalid_conversation_id');if(recording||transcribing||generationController)throw new Error('conversation_busy');setBusy(true,'Loading private conversation…');try{await loadMessages(id);activeConversationId=id;storeConversationId(id);emitConversationChanged();return id;}finally{setBusy(false);autoHeight();input.focus();}}
  async function ensureConversation(){const stored=getStoredConversationId();if(stored){try{await loadMessages(stored);activeConversationId=stored;emitConversationChanged();return stored;}catch(error){if(error?.message==='chat_auth_required')throw error;storeConversationId('');}}const id=await createConversation();await loadMessages(id);return id;}
  async function newChat(){if(recording||transcribing||generationController||send.disabled)return '';setBusy(true,'Creating private conversation…');try{const id=await createConversation();await loadMessages(id);showEmpty('New conversation','A new private conversation is ready. Previous conversations remain stored privately on MASTER-PC.');return id;}catch(error){addMessage('assistant',`Could not create conversation: ${error.message}`,'system',{actions:false});return '';}finally{setBusy(false);autoHeight();input.focus();}}

  function responseMeta(body){const persona=`persona · ${String(body?.persona||'off')}`;const grounding=String(body?.persona_grounding||'off');const groundingPart=grounding&&grounding!=='off'?` · ${grounding}`:'';const evidence=Number(body?.persona_evidence||0)>0?` · evidence ${Number(body.persona_evidence)}`:'';const remembered=Number(body?.memory_items_used||0)>0?` · memory ${Number(body.memory_items_used)}`:'';return `${body?.provider||''}${body?.model?` · ${body.model}`:''} · ${persona}${groundingPart}${evidence}${remembered}`;}

  function parseSseFrame(frame){let eventName='message',data='';for(const line of frame.split('\n')){if(line.startsWith('event:'))eventName=line.slice(6).trim();else if(line.startsWith('data:'))data+=line.slice(5).trim();}if(!data)return null;let payload={};try{payload=JSON.parse(data);}catch(_){return null;}return {eventName,payload};}
  async function streamRequest(payload,controller,onEvent){
    const response=await fetch('/meso/api/chat-stream.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:stateJsonHeaders,body:JSON.stringify(payload),signal:controller.signal});
    if(response.status===403)throw new Error('chat_auth_required');
    if(!response.ok){let error=`HTTP ${response.status}`;try{const body=await response.json();error=body?.error||error;}catch(_){}throw new Error(error);}
    if(!response.body||typeof response.body.getReader!=='function')throw new Error('stream_unavailable');
    const reader=response.body.getReader(),decoder=new TextDecoder();let buffer='';
    while(true){const {done,value}=await reader.read();if(done)break;buffer+=decoder.decode(value,{stream:true}).replace(/\r\n/g,'\n');let index;while((index=buffer.indexOf('\n\n'))>=0){const frame=buffer.slice(0,index);buffer=buffer.slice(index+2);const parsed=parseSseFrame(frame);if(parsed)await onEvent(parsed.eventName,parsed.payload);}}
    buffer+=decoder.decode();if(buffer.trim()){const parsed=parseSseFrame(buffer);if(parsed)await onEvent(parsed.eventName,parsed.payload);}
  }

  async function jsonFallback(payload,userMessageId){
    const fallbackPayload={conversation_id:activeConversationId};
    if(validId(String(payload.regenerate_message_id||'')))fallbackPayload.regenerate_message_id=String(payload.regenerate_message_id);
    else if(validId(userMessageId))fallbackPayload.regenerate_message_id=userMessageId;
    else fallbackPayload.message=String(payload.message||'');
    const response=await fetch('/meso/api/chat.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:stateJsonHeaders,body:JSON.stringify(fallbackPayload)});const body=await readJson(response);if(!response.ok||!body.ok)throw new Error(body.message||body.error||`HTTP ${response.status}`);return body;
  }

  async function generate({message='',regenerateMessageId=''}){
    if(generationController||!validId(activeConversationId))return;
    const payload={conversation_id:activeConversationId};if(validId(regenerateMessageId))payload.regenerate_message_id=regenerateMessageId;else payload.message=message;
    const controller=new AbortController();generationController=controller;generationStopped=false;
    let deltaAccepted=false,fallbackEligible=true,userMessageId=validId(regenerateMessageId)?regenerateMessageId:'',streamText='',partial=null,donePayload=null;
    setGenerationUi(true,`Streaming · Persona ${activePersona} · Conversation memory v1…`);
    try{
      await streamRequest(payload,controller,async(eventName,data)=>{
        if(eventName==='meta'){
          const candidate=String(data?.user_message_id||'');if(validId(candidate))userMessageId=candidate;
          activePersona=String(data?.persona||activePersona);activeGrounding=String(data?.persona_grounding||activeGrounding);activeEvidenceCount=Number(data?.persona_records||activeEvidenceCount);
        }else if(eventName==='delta'){
          const text=String(data?.text||'');if(!text)return;if(!partial)partial=createStreamingCard();streamText+=text;partial.body.textContent=streamText;deltaAccepted=true;fallbackEligible=false;messages.scrollTop=messages.scrollHeight;
        }else if(eventName==='done'){
          donePayload=data;
        }else if(eventName==='error'){
          throw new Error(String(data?.error||'stream_error'));
        }
      });
      if(!donePayload||!validId(String(donePayload.message_id||'')))throw new Error('stream_incomplete');
      const doneUserId=String(donePayload.user_message_id||userMessageId);if(!validId(doneUserId))throw new Error('invalid_user_message_id');
      if(streamText==='')throw new Error('empty_provider_response');
      activePersona=String(donePayload.persona||activePersona);activeGrounding=String(donePayload.persona_grounding||activeGrounding);activeEvidenceCount=Number(donePayload.persona_records||activeEvidenceCount);
      finalizeStreamingCard(partial,streamText,responseMeta(donePayload),doneUserId);
      window.dispatchEvent(new CustomEvent('meso:memory-changed',{detail:{conversation_id:activeConversationId}}));emitConversationChanged();
    }catch(error){
      if(error?.name==='AbortError'||generationStopped){if(partial)partial.card.remove();status.textContent='Generation stopped · user turn kept privately';}
      else if(fallbackEligible&&!deltaAccepted){
        try{const body=await jsonFallback(payload,userMessageId);const fallbackUserId=String(body.user_message_id||userMessageId);if(!validId(fallbackUserId))throw new Error('invalid_user_message_id');activePersona=String(body.persona||activePersona);activeGrounding=String(body.persona_grounding||activeGrounding);activeEvidenceCount=Number(body.persona_records||activeEvidenceCount);addMessage('assistant',body.reply,responseMeta(body),{userMessageId:fallbackUserId});window.dispatchEvent(new CustomEvent('meso:memory-changed',{detail:{conversation_id:activeConversationId}}));emitConversationChanged();}
        catch(fallbackError){if(partial)partial.card.remove();addMessage('assistant',`Chat error: ${fallbackError.message}`,'system',{actions:false});}
      }else{if(partial)partial.card.remove();addMessage('assistant',`Streaming error: ${error.message}`,'system',{actions:false});}
    }finally{generationController=null;generationStopped=false;setGenerationUi(false);autoHeight();input.focus();}
  }

  async function regenerateMessage(userMessageId){const id=String(userMessageId||'');if(!validId(id)||generationController||send.disabled)return;await generate({regenerateMessageId:id});}
  async function sendText(){const text=input.value.trim();if(!text||send.disabled||generationController||!validId(activeConversationId))return;input.value='';autoHeight();addMessage('user',text);await generate({message:text});}
  function stopGeneration(){if(!generationController)return;generationStopped=true;const controller=generationController;controller.abort();}

  function autoHeight(){input.style.height='auto';const height=Math.max(64,Math.min(input.scrollHeight,180));input.style.height=`${height}px`;}

  function bestRecorderMime(){if(!window.MediaRecorder||typeof MediaRecorder.isTypeSupported!=='function')return '';return ['audio/webm;codecs=opus','audio/ogg;codecs=opus','audio/mp4','audio/webm'].find(v=>MediaRecorder.isTypeSupported(v))||'';}
  async function transcribeBlob(blob){transcribing=true;setBusy(true,'Transcribing locally…');try{const response=await fetch('/meso/api/transcribe.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':blob.type||'audio/webm','Accept':'application/json'},body:blob});const body=await readJson(response);if(!response.ok||!body.ok||!String(body.transcript||'').trim())throw new Error(body.error||`HTTP ${response.status}`);input.value=String(body.transcript).trim();autoHeight();transcribing=false;setBusy(false,`Transcribed locally${body.language?` · ${body.language}`:''}`);await sendText();}catch(error){if(error?.message!=='chat_auth_required')addMessage('assistant',`Microphone transcription error: ${error.message}`,'local STT',{actions:false});}finally{transcribing=false;if(!send.disabled)status.textContent=baseStatus();if(mic)mic.disabled=false;}}
  async function startRecording(){if(!mic||recording||transcribing||generationController||send.disabled)return;if(!navigator.mediaDevices?.getUserMedia||!window.MediaRecorder){addMessage('assistant','This browser does not provide microphone recording support.','local STT',{actions:false});return;}try{stream=await navigator.mediaDevices.getUserMedia({audio:true});const mime=bestRecorderMime();recorder=mime?new MediaRecorder(stream,{mimeType:mime}):new MediaRecorder(stream);chunks=[];recorder.addEventListener('dataavailable',e=>{if(e.data&&e.data.size>0)chunks.push(e.data);});recorder.addEventListener('stop',async()=>{const type=recorder?.mimeType||mime||'audio/webm';const blob=new Blob(chunks,{type});chunks=[];recorder=null;resetRecorderUi();if(!blob.size){addMessage('assistant','No microphone audio was captured.','local STT',{actions:false});setBusy(false);return;}await transcribeBlob(blob);},{once:true});recorder.start(250);recording=true;mic.classList.add('recording');mic.setAttribute('aria-pressed','true');mic.textContent='■';send.disabled=true;input.disabled=true;status.textContent='Recording locally… tap ■ to stop';autoStopTimer=setTimeout(()=>stopRecording(),60000);}catch(error){resetRecorderUi();setBusy(false);addMessage('assistant',`Microphone unavailable: ${error.message}`,'local STT',{actions:false});}}
  function stopRecording(){if(!recording||!recorder)return;try{if(recorder.state!=='inactive')recorder.stop();}catch(error){resetRecorderUi();setBusy(false);addMessage('assistant',`Could not stop microphone recording: ${error.message}`,'local STT',{actions:false});}}

  async function bootstrapChat(){setBusy(true,'Loading private conversation…');try{await loadPersonaState();await ensureConversation();bootstrapped=true;setBusy(false);autoHeight();input.focus();}catch(error){if(error?.message!=='chat_auth_required'){showEmpty('Conversation unavailable','MesoAI could not initialize private Conversation memory v1.');status.textContent=`Memory unavailable · ${error.message}`;}}}

  window.mesoActiveConversationId=()=>activeConversationId;
  window.mesoChatBridge={activateConversation,newConversation:newChat,reloadActive:()=>validId(activeConversationId)?activateConversation(activeConversationId):Promise.reject(new Error('invalid_conversation_id'))};
  send.addEventListener('click',sendText);
  if(stopButton)stopButton.addEventListener('click',stopGeneration);
  if(newChatButton)newChatButton.addEventListener('click',()=>{const controller=window.mesoConversationController;if(controller&&typeof controller.newConversation==='function')controller.newConversation().catch(error=>addMessage('assistant',`Could not create conversation: ${error.message}`,'system',{actions:false}));else newChat();});
  if(mic)mic.addEventListener('click',()=>recording?stopRecording():startRecording());
  input.addEventListener('input',autoHeight);
  input.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();sendText();}});
  window.addEventListener('pagehide',()=>{if(generationController){generationStopped=true;generationController.abort();}stopTracks();});
  status.textContent='Private · Loading Conversation memory v1…';send.disabled=true;input.disabled=true;if(mic)mic.disabled=true;bootstrapChat();
})();

const replyAudioScript=document.createElement('script');replyAudioScript.src='/meso/chat/reply-audio.js';replyAudioScript.defer=true;document.head.appendChild(replyAudioScript);
