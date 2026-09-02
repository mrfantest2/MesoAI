'use strict';

(() => {
  const button=document.getElementById('memoryBtn');
  const sheet=document.getElementById('memorySheet');
  const close=document.getElementById('memoryClose');
  const list=document.getElementById('memoryList');
  const clear=document.getElementById('memoryClear');
  if(!button||!sheet||!close||!list||!clear)return;

  let loading=false;
  const validId=(value)=>/^[a-f0-9]{64}$/.test(String(value||''));
  const activeConversationId=()=>{
    try{
      const value=typeof window.mesoActiveConversationId==='function'?window.mesoActiveConversationId():'';
      return validId(value)?String(value):'';
    }catch(_){return '';}
  };

  async function readJson(response){
    let body={};
    try{body=await response.json();}catch(_){body={ok:false,error:'invalid_json'};}
    if(response.status===403){location.reload();throw new Error('chat_auth_required');}
    return body;
  }

  function setOpen(value){
    sheet.hidden=!value;
    sheet.setAttribute('aria-hidden',value?'false':'true');
    if(value)close.focus();
  }

  function empty(text){
    list.replaceChildren();
    const row=document.createElement('div');
    row.textContent=text;
    Object.assign(row.style,{padding:'12px',color:'#9299aa',border:'1px solid #252b3a',borderRadius:'12px'});
    list.appendChild(row);
  }

  function actionButton(label,handler){
    const b=document.createElement('button');
    b.type='button';
    b.textContent=label;
    Object.assign(b.style,{width:'auto',display:'inline-block',padding:'6px 9px',fontSize:'11px',marginRight:'6px',marginTop:'8px'});
    b.addEventListener('click',handler);
    return b;
  }

  async function mutate(method,payload){
    const response=await fetch('/meso/api/memory.php',{
      method,
      credentials:'same-origin',
      cache:'no-store',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body:JSON.stringify(payload),
    });
    const body=await readJson(response);
    if(!response.ok||!body.ok)throw new Error(body.error||`HTTP ${response.status}`);
    return body;
  }

  async function verifyItem(id){await mutate('POST',{action:'verify',memory_id:id});await load();}
  async function rejectItem(id){await mutate('POST',{action:'reject',memory_id:id});await load();}
  async function deleteItem(id){await mutate('DELETE',{action:'item',memory_id:id});await load();}

  function renderItem(item){
    const card=document.createElement('article');
    Object.assign(card.style,{padding:'11px 12px',border:'1px solid #252b3a',borderRadius:'13px',background:'#0f121b'});
    const meta=document.createElement('div');
    const kind=String(item?.kind||'memory');
    const status=String(item?.status||'candidate');
    meta.textContent=`${kind} · ${status}`;
    Object.assign(meta.style,{fontSize:'10px',textTransform:'uppercase',letterSpacing:'.08em',color:'#9299aa',fontWeight:'800'});
    const text=document.createElement('div');
    text.textContent=String(item?.text||'');
    Object.assign(text.style,{marginTop:'6px',whiteSpace:'pre-wrap',wordBreak:'break-word'});
    card.append(meta,text);

    const id=String(item?.id||'');
    if(validId(id)){
      const tools=document.createElement('div');
      if(status==='candidate'){
        tools.append(actionButton('Verify',()=>verifyItem(id).catch(error=>empty(`Memory error: ${error.message}`))));
        tools.append(actionButton('Reject',()=>rejectItem(id).catch(error=>empty(`Memory error: ${error.message}`))));
      }
      tools.append(actionButton('Delete',()=>deleteItem(id).catch(error=>empty(`Memory error: ${error.message}`))));
      card.appendChild(tools);
    }
    return card;
  }

  async function load(){
    if(loading)return;
    const conversationId=activeConversationId();
    if(!conversationId){empty('No active conversation yet.');return;}
    loading=true;
    button.disabled=true;
    empty('Loading conversation memory…');
    try{
      const response=await fetch(`/meso/api/memory.php?conversation_id=${encodeURIComponent(conversationId)}&limit=100`,{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
      const body=await readJson(response);
      if(!response.ok||!body.ok)throw new Error(body.error||`HTTP ${response.status}`);
      const items=Array.isArray(body.items)?body.items:[];
      list.replaceChildren();
      if(items.length===0){empty('No saved memory items for this conversation.');return;}
      for(const item of items)list.appendChild(renderItem(item));
    }catch(error){
      if(error?.message!=='chat_auth_required')empty(`Memory unavailable: ${error.message}`);
    }finally{
      loading=false;
      button.disabled=false;
    }
  }

  async function clearConversationMemory(){
    const conversationId=activeConversationId();
    if(!conversationId)return;
    if(!window.confirm('Clear saved memory items for this conversation? The transcript will be kept.'))return;
    clear.disabled=true;
    try{
      await mutate('DELETE',{action:'conversation_memory',conversation_id:conversationId});
      await load();
    }catch(error){empty(`Memory error: ${error.message}`);}
    finally{clear.disabled=false;}
  }

  button.addEventListener('click',()=>{setOpen(true);load();});
  close.addEventListener('click',()=>setOpen(false));
  clear.addEventListener('click',clearConversationMemory);
  sheet.addEventListener('click',event=>{if(event.target===sheet)setOpen(false);});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!sheet.hidden)setOpen(false);});
  window.addEventListener('meso:conversation-changed',()=>{if(!sheet.hidden)load();});
  window.addEventListener('meso:memory-changed',()=>{if(!sheet.hidden)load();});
})();
