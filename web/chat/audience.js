'use strict';
(() => {
  const sheet=document.getElementById('audienceSheet');
  const options=sheet?[...sheet.querySelectorAll('[data-meso-audience]')]:[];
  // The selection is required for each new conversation and each fresh page session.
  window.mesoPickAudience=()=>{
    if(!sheet||options.length!==2)return Promise.reject(new Error('identity_picker_unavailable'));
    return new Promise(resolve=>{
      sheet.hidden=false;sheet.setAttribute('aria-hidden','false');
      const previous=document.activeElement;
      function stopEscape(event){if(event.key==='Escape'){event.preventDefault();event.stopPropagation();}}
      function choose(event){
        const audience=String(event.currentTarget.dataset.mesoAudience||'');
        if(audience!=='majid'&&audience!=='mira')return;
        options.forEach(button=>button.removeEventListener('click',choose));
        document.removeEventListener('keydown',stopEscape,true);
        sheet.hidden=true;sheet.setAttribute('aria-hidden','true');
        if(previous&&typeof previous.focus==='function')previous.focus();
        resolve(audience);
      }
      options.forEach(button=>button.addEventListener('click',choose));
      document.addEventListener('keydown',stopEscape,true);
      options[0].focus();
    });
  };
})();
