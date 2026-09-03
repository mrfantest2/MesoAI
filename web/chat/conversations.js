'use strict';

(() => {
  const ACTIVE_KEY='meso.activeConversation.v1';
  const activeList=document.getElementById('conversationList');
  const archivedList=document.getElementById('archivedConversationList');
  const drawer=document.getElementById('conversationDrawer');
  const drawerToggle=document.getElementById('conversationDrawerToggle');
  const drawerClose=document.getElementById('conversationDrawerClose');
  const newButtons=Array.from(document.querySelectorAll('[data-new-conversation]'));
  if(!activeList||!archivedList)return;

  const validId=value=>/^[a-f0-9]{64}$/.test(String(value||''));
  const stateHeaders={'Content-Type':'application/json','Accept':'application/json'};
  let refreshing=false;

  function activeId(){
    try{
      const live=typeof window.mesoActiveConversationId==='function'?String(window.mesoActiveConversationId()||''):'';
      if(validId(live))return live;
      const stored=String(localStorage.getItem(ACTIVE_KEY)||'');
      return validId(stored)?stored:'';
    }catch(_){return '';}
  }

  function storeActive(id){
    try{if(validId(id))localStorage.setItem(ACTIVE_KEY,id);else localStorage.removeItem(ACTIVE_KEY);}catch(_){}
  }

  async function readJson(response){
    let body={};
    try{body=await response.json();}catch(_){body={ok:false,error:'invalid_json'};}
    if(response.status===403){throw new Error(String(body?.error||'chat_auth_required'));}
    if(!response.ok||!body.ok)throw new Error(body.error||`HTTP ${response.status}`);
    return body;
  }

  async function listConversations(archived){
    const response=await fetch(`/meso/api/conversations.php?archived=${archived?'1':'0'}&limit=50`,{
      credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}
    });
    const body=await readJson(response);
    return Array.isArray(body.items)?body.items:[];
  }

  async function mutate(method,payload){
    const response=await fetch('/meso/api/conversations.php',{
      method,credentials:'same-origin',cache:'no-store',headers:stateHeaders,body:JSON.stringify(payload)
    });
    return readJson(response);
  }

  function formatTime(value){
    const n=Number(value||0);
    if(!Number.isFinite(n)||n<=0)return '';
    try{return new Date(n*1000).toLocaleString([], {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});}catch(_){return '';}
  }

  function actionButton(label,title,handler){
    const button=document.createElement('button');
    button.type='button';
    button.className='conversationAction';
    button.textContent=label;
    button.title=title;
    button.setAttribute('aria-label',title);
    button.addEventListener('click',event=>{event.stopPropagation();handler();});
    return button;
  }

  async function activate(id){
    if(!validId(id))throw new Error('invalid_conversation_id');
    const bridge=window.mesoChatBridge;
    if(bridge&&typeof bridge.activateConversation==='function'){
      await bridge.activateConversation(id);
    }else{
      const verify=await fetch(`/meso/api/messages.php?conversation_id=${encodeURIComponent(id)}&limit=1`,{
        credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}
      });
      await readJson(verify);
      storeActive(id);
      window.dispatchEvent(new CustomEvent('meso:conversation-changed',{detail:{conversation_id:id}}));
      location.reload();
      return;
    }
    storeActive(id);
    closeDrawer();
    await refresh();
  }

  async function newConversation(){
    const bridge=window.mesoChatBridge;
    if(bridge&&typeof bridge.newConversation==='function'){
      const id=await bridge.newConversation();
      if(validId(id))storeActive(id);
    }else{
      const body=await mutate('POST',{title:'New conversation'});
      const id=String(body?.conversation?.id||'');
      if(!validId(id))throw new Error('invalid_conversation_id');
      storeActive(id);
      location.reload();
      return id;
    }
    closeDrawer();
    await refresh();
    return activeId();
  }

  async function renameConversation(item){
    const id=String(item?.id||'');
    if(!validId(id))return;
    const current=String(item?.title||'New conversation');
    const next=window.prompt('Rename conversation',current);
    if(next===null)return;
    const title=String(next).trim();
    if(!title||title===current)return;
    await mutate('PATCH',{conversation_id:id,title});
    await refresh();
  }

  async function archiveConversation(item,archived){
    const id=String(item?.id||'');
    if(!validId(id))return;
    await mutate('PATCH',{conversation_id:id,archived});
    if(archived&&id===activeId()){
      const active=await listConversations(false);
      const replacement=active.find(row=>validId(row?.id)&&String(row.id)!==id);
      if(replacement)await activate(String(replacement.id));
      else await newConversation();
    }
    await refresh();
  }

  async function deleteConversation(item){
    const id=String(item?.id||'');
    if(!validId(id))return;
    const title=String(item?.title||'this conversation');
    if(!window.confirm(`Delete “${title}”? Its transcript and conversation memory will no longer be available.`))return;
    await mutate('DELETE',{conversation_id:id,mode:'conversation'});
    if(id===activeId()){
      storeActive('');
      const active=await listConversations(false);
      const replacement=active.find(row=>validId(row?.id));
      if(replacement)await activate(String(replacement.id));
      else await newConversation();
    }
    await refresh();
  }

  function renderList(target,items,archived){
    target.replaceChildren();
    if(items.length===0){
      const empty=document.createElement('div');
      empty.className='conversationEmpty';
      empty.textContent=archived?'No archived conversations.':'No conversations yet.';
      target.appendChild(empty);
      return;
    }
    const current=activeId();
    for(const item of items){
      const id=String(item?.id||'');
      if(!validId(id))continue;
      const row=document.createElement('article');
      row.className='conversationRow';
      if(id===current)row.classList.add('active');
      row.tabIndex=0;
      row.setAttribute('role','button');
      row.setAttribute('aria-label',`Open conversation ${String(item?.title||'New conversation')}`);

      const main=document.createElement('div');
      main.className='conversationMain';
      const title=document.createElement('strong');
      title.textContent=String(item?.title||'New conversation');
      const time=document.createElement('span');
      time.textContent=formatTime(item?.updated_at||item?.created_at);
      main.append(title,time);

      const actions=document.createElement('div');
      actions.className='conversationActions';
      actions.append(actionButton('✎','Rename conversation',()=>renameConversation(item).catch(showError)));
      actions.append(actionButton(archived?'↩':'⌁',archived?'Unarchive conversation':'Archive conversation',()=>archiveConversation(item,!archived).catch(showError)));
      actions.append(actionButton('×','Delete conversation',()=>deleteConversation(item).catch(showError)));
      row.append(main,actions);
      row.addEventListener('click',()=>activate(id).catch(showError));
      row.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();activate(id).catch(showError);}});
      target.appendChild(row);
    }
  }

  function showError(error){
    const detail=String(error?.message||'conversation_error');
    const status=document.getElementById('status');
    if(status)status.textContent=`Conversation error · ${detail}`;
  }

  async function refresh(){
    if(refreshing)return;
    refreshing=true;
    try{
      const [active,archived]=await Promise.all([listConversations(false),listConversations(true)]);
      renderList(activeList,active,false);
      renderList(archivedList,archived,true);
      for(const clone of document.querySelectorAll('[data-conversation-list="active"]'))renderList(clone,active,false);
      for(const clone of document.querySelectorAll('[data-conversation-list="archived"]'))renderList(clone,archived,true);
    }catch(error){showError(error);}
    finally{refreshing=false;}
  }

  function openDrawer(){if(!drawer)return;drawer.hidden=false;drawer.setAttribute('aria-hidden','false');if(drawerClose)drawerClose.focus();}
  function closeDrawer(){if(!drawer)return;drawer.hidden=true;drawer.setAttribute('aria-hidden','true');}

  window.mesoConversationController={refresh,activate,newConversation,rename:renameConversation,archive:archiveConversation,delete:deleteConversation};
  newButtons.forEach(button=>button.addEventListener('click',()=>newConversation().catch(showError)));
  if(drawerToggle)drawerToggle.addEventListener('click',openDrawer);
  if(drawerClose)drawerClose.addEventListener('click',closeDrawer);
  if(drawer)drawer.addEventListener('click',event=>{if(event.target===drawer)closeDrawer();});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&drawer&&!drawer.hidden)closeDrawer();});
  window.addEventListener('meso:conversation-changed',refresh);
  window.addEventListener('meso:memory-changed',refresh);
  refresh();
})();
