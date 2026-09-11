'use strict';

(() => {
  function text(parent,value){parent.appendChild(document.createTextNode(String(value??'')));}

  function appendInline(parent,input){
    const source=String(input??'');
    const token=/(`[^`\n]+`|\*\*[^*\n]+\*\*|\*[^*\n]+\*|\[[^\]\n]+\]\(https?:\/\/[^\s)]+\))/g;
    let index=0;
    for(const match of source.matchAll(token)){
      const start=Number(match.index||0);
      if(start>index)text(parent,source.slice(index,start));
      const raw=String(match[0]);
      if(raw.startsWith('`')){
        const code=document.createElement('code');
        code.textContent=raw.slice(1,-1);
        parent.appendChild(code);
      }else if(raw.startsWith('**')){
        const strong=document.createElement('strong');
        strong.textContent=raw.slice(2,-2);
        parent.appendChild(strong);
      }else if(raw.startsWith('*')){
        const em=document.createElement('em');
        em.textContent=raw.slice(1,-1);
        parent.appendChild(em);
      }else{
        const parts=/^\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)$/.exec(raw);
        if(parts){
          const link=document.createElement('a');
          link.textContent=parts[1];
          link.href=parts[2];
          link.target='_blank';
          link.rel='noopener noreferrer';
          parent.appendChild(link);
        }else text(parent,raw);
      }
      index=start+raw.length;
    }
    if(index<source.length)text(parent,source.slice(index));
  }

  function flushParagraph(root,lines){
    if(lines.length===0)return;
    const p=document.createElement('p');
    appendInline(p,lines.join(' '));
    root.appendChild(p);
    lines.length=0;
  }

  function render(target,input){
    if(!(target instanceof HTMLElement))return;
    target.replaceChildren();
    const lines=String(input??'').replace(/\r\n?/g,'\n').split('\n');
    const paragraph=[];
    let list=null;
    let listType='';
    let fence=false;
    let codeLines=[];

    function closeList(){list=null;listType='';}
    function flushCode(){
      const pre=document.createElement('pre');
      const code=document.createElement('code');
      code.textContent=codeLines.join('\n');
      pre.appendChild(code);
      target.appendChild(pre);
      codeLines=[];
    }

    for(const line of lines){
      if(fence){
        if(/^\s*```/.test(line)){fence=false;flushCode();}
        else codeLines.push(line);
        continue;
      }
      if(/^\s*```/.test(line)){
        flushParagraph(target,paragraph);closeList();fence=true;codeLines=[];continue;
      }
      const unordered=/^\s*[-+]\s+(.+)$/.exec(line);
      const ordered=/^\s*\d+\.\s+(.+)$/.exec(line);
      if(unordered||ordered){
        flushParagraph(target,paragraph);
        const wanted=ordered?'ol':'ul';
        if(!list||listType!==wanted){list=document.createElement(wanted);listType=wanted;target.appendChild(list);}
        const item=document.createElement('li');
        appendInline(item,(unordered||ordered)[1]);
        list.appendChild(item);
        continue;
      }
      if(line.trim()===''){flushParagraph(target,paragraph);closeList();continue;}
      closeList();paragraph.push(line.trim());
    }
    if(fence)flushCode();
    flushParagraph(target,paragraph);
  }

  async function copyText(value){
    const clean=String(value??'');
    if(navigator.clipboard&&typeof navigator.clipboard.writeText==='function'){
      await navigator.clipboard.writeText(clean);
      return;
    }
    const area=document.createElement('textarea');
    area.value=clean;
    area.setAttribute('readonly','');
    area.style.position='fixed';
    area.style.opacity='0';
    document.body.appendChild(area);
    area.select();
    document.execCommand('copy');
    area.remove();
  }

  const ICON_COPY='<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>';
  const ICON_REGEN='<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36M21 3v6h-6"/></svg>';
  function actionButton(label,title,icon){
    const button=document.createElement('button');
    button.type='button';
    button.className='messageAction';
    button.title=title;
    if(icon){const span=document.createElement('span');span.setAttribute('aria-hidden','true');span.innerHTML=icon;button.appendChild(span);}
    button.appendChild(document.createTextNode(label));
    return button;
  }

  function attachActions(card,metadata={}){
    if(!(card instanceof HTMLElement)||card.dataset.messageActions==='1')return;
    const plain=String(metadata.text??card.dataset.plainText??'');
    if(!plain)return;
    card.dataset.messageActions='1';
    card.dataset.plainText=plain;
    const tools=document.createElement('div');
    tools.className='messageActions';

    const copy=actionButton('Copy','Copy reply',ICON_COPY);
    copy.addEventListener('click',async()=>{
      const before=copy.textContent;
      try{await copyText(plain);copy.textContent='Copied';}
      catch(_){copy.textContent='Copy failed';}
      window.setTimeout(()=>{copy.textContent=before;},1200);
    });
    tools.appendChild(copy);

    if(typeof metadata.onRegenerate==='function'){
      const regenerate=actionButton('Regenerate','Regenerate reply from the same user turn',ICON_REGEN);
      regenerate.addEventListener('click',()=>metadata.onRegenerate());
      tools.appendChild(regenerate);
    }
    card.appendChild(tools);
  }

  window.mesoRenderAssistant=render;
  window.mesoAttachMessageActions=attachActions;
})();
