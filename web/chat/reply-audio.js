'use strict';

(() => {
  const synth = window.speechSynthesis;
  const messages = document.getElementById('messages');
  const status = document.getElementById('status');
  if (!messages || !synth || typeof window.SpeechSynthesisUtterance !== 'function') return;

  let activeButton = null;

  function baseStatus() {
    return 'Private text + local STT preflight';
  }

  function resetActive() {
    if (activeButton) {
      activeButton.textContent = '▶ Play';
      activeButton.setAttribute('aria-pressed', 'false');
      activeButton.title = 'Play reply using browser voice';
    }
    activeButton = null;
    if (status) status.textContent = baseStatus();
  }

  function stopSpeech() {
    if (synth.speaking || synth.pending) synth.cancel();
    resetActive();
  }

  function containsArabic(text) {
    return /[\u0600-\u06ff\u0750-\u077f\u08a0-\u08ff]/.test(text);
  }

  function chooseVoice(languagePrefix) {
    const voices = synth.getVoices();
    const prefix = languagePrefix.toLowerCase();
    return voices.find((voice) => String(voice.lang || '').toLowerCase().startsWith(prefix)) || null;
  }

  function speak(text, button) {
    const clean = String(text || '').trim();
    if (!clean) return;
    if (activeButton === button && (synth.speaking || synth.pending)) {
      stopSpeech();
      return;
    }

    stopSpeech();
    const arabic = containsArabic(clean);
    const utterance = new SpeechSynthesisUtterance(clean);
    utterance.lang = arabic ? 'ar-AE' : 'en-US';
    utterance.rate = 0.96;
    utterance.pitch = 1.0;
    const voice = chooseVoice(arabic ? 'ar' : 'en');
    if (voice) utterance.voice = voice;

    activeButton = button;
    button.textContent = '■ Stop';
    button.setAttribute('aria-pressed', 'true');
    button.title = 'Stop reply audio';
    if (status) status.textContent = `Speaking · Browser voice · ${utterance.lang}`;

    utterance.addEventListener('end', resetActive, { once: true });
    utterance.addEventListener('error', (event) => {
      resetActive();
      if (status) status.textContent = `Reply audio unavailable · ${event.error || 'speech error'}`;
    }, { once: true });
    synth.speak(utterance);
  }

  function decorate(card) {
    if (!(card instanceof HTMLElement)) return;
    if (!card.classList.contains('assistant') || !card.classList.contains('msg')) return;
    if (card.dataset.replyAudioReady === '1') return;
    card.dataset.replyAudioReady = '1';

    const body = card.children.length >= 2 ? card.children[1] : null;
    const text = body ? String(body.textContent || '').trim() : '';
    if (!text || /^Chat error:/i.test(text) || /^Microphone .*error:/i.test(text)) return;

    const tools = document.createElement('div');
    tools.style.marginTop = '9px';

    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = '▶ Play';
    button.setAttribute('aria-label', 'Play assistant reply using browser voice');
    button.setAttribute('aria-pressed', 'false');
    button.title = 'Play reply using browser voice';
    Object.assign(button.style, {
      padding: '6px 10px', borderRadius: '9px', border: '1px solid #4c5263',
      background: '#171b27', color: '#f6f7fb', cursor: 'pointer', fontSize: '12px'
    });
    button.addEventListener('click', () => speak(text, button));

    const note = document.createElement('span');
    note.textContent = ' Browser voice';
    Object.assign(note.style, { marginLeft: '7px', color: '#9299aa', fontSize: '10px' });

    tools.append(button, note);
    card.appendChild(tools);
  }

  function decorateAll(root = messages) {
    if (root instanceof HTMLElement && root.matches('.msg.assistant')) decorate(root);
    if (root.querySelectorAll) {
      for (const card of root.querySelectorAll('.msg.assistant')) decorate(card);
    }
  }

  decorateAll();
  const observer = new MutationObserver((records) => {
    for (const record of records) {
      for (const node of record.addedNodes) {
        if (node instanceof HTMLElement) decorateAll(node);
      }
    }
  });
  observer.observe(messages, { childList: true, subtree: true });

  window.addEventListener('pagehide', stopSpeech);
  window.addEventListener('beforeunload', stopSpeech);
})();
