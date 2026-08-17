(() => {
  'use strict';

  const buttons = Array.from(document.querySelectorAll('[data-install-app]'));
  const sheet = document.getElementById('installSheet');
  const sheetTitle = document.getElementById('installSheetTitle');
  const sheetText = document.getElementById('installSheetText');
  const close = document.getElementById('installSheetClose');
  let deferredPrompt = null;

  const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const ua = navigator.userAgent || '';
  const isiOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

  function setButtonsVisible(visible) {
    buttons.forEach(button => {
      button.hidden = !visible;
      button.setAttribute('aria-hidden', visible ? 'false' : 'true');
    });
  }

  function openSheet(title, text) {
    if (!sheet) return;
    if (sheetTitle) sheetTitle.textContent = title;
    if (sheetText) sheetText.textContent = text;
    sheet.hidden = false;
    sheet.setAttribute('aria-hidden', 'false');
    if (close) close.focus();
  }

  function closeSheet() {
    if (!sheet) return;
    sheet.hidden = true;
    sheet.setAttribute('aria-hidden', 'true');
  }

  async function install() {
    if (standalone) {
      openSheet('MesoAI is installed', 'This window is already running as the installed MesoAI app.');
      return;
    }

    if (deferredPrompt) {
      deferredPrompt.prompt();
      const choice = await deferredPrompt.userChoice;
      if (choice && choice.outcome === 'accepted') setButtonsVisible(false);
      deferredPrompt = null;
      return;
    }

    if (isiOS) {
      openSheet('Add MesoAI to Home Screen', 'In Safari, tap Share, then choose Add to Home Screen. Open the new MesoAI icon to run it as a standalone app.');
      return;
    }

    openSheet('Install MesoAI', 'Open this page in Chrome or Edge on Android, then use the browser menu and choose Install app or Add to Home screen.');
  }

  if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/meso/sw.js', { scope: '/meso/' }).catch(() => {});
    });
  }

  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    deferredPrompt = event;
    if (!standalone) setButtonsVisible(true);
  });

  window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    setButtonsVisible(false);
    openSheet('MesoAI installed', 'MesoAI is now available from your home screen/app launcher.');
  });

  buttons.forEach(button => button.addEventListener('click', install));
  if (close) close.addEventListener('click', closeSheet);
  if (sheet) sheet.addEventListener('click', event => { if (event.target === sheet) closeSheet(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') closeSheet(); });

  if (standalone) {
    setButtonsVisible(false);
  } else if (isiOS) {
    setButtonsVisible(true);
  } else {
    setButtonsVisible(false);
    window.setTimeout(() => {
      if (!deferredPrompt && /Android/i.test(ua)) setButtonsVisible(true);
    }, 1800);
  }
})();
