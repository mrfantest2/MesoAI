'use strict';

(() => {
  const synth = window.speechSynthesis || null;
  const messages = document.getElementById('messages');
  const status = document.getElementById('status');
  if (!messages) return;

  let activeButton = null;
  let activeNote = null;
  let activeAudio = null;
  let activeAbort = null;
  let operationId = 0;

  function baseStatus() {
    return 'Private text + local STT + Meso voice · Local XTTS';
  }

  function markMesoVoiceUi() {
    if (status) status.textContent = baseStatus();
    for (const row of document.querySelectorAll('.side .status')) {
      const label = row.querySelector('span:first-child');
      const pill = row.querySelector('.pill');
      if (label && pill && String(label.textContent || '').trim() === 'Cloned voice') {
        pill.textContent = 'MESO VOICE';
        pill.classList.remove('warn');
        pill.classList.add('good');
      }
    }
    const emptyDetail = messages.querySelector('.empty > div:last-child');
    if (emptyDetail) {
      emptyDetail.textContent = String(emptyDetail.textContent || '')
        .replace('Memory, persona and cloned voice remain off during this stage.', 'Memory and persona remain off. Reply audio uses the reviewed Meso voice locally on MASTER-PC.');
    }
    const composerNote = document.querySelector('.composer small');
    if (composerNote) {
      composerNote.textContent = String(composerNote.textContent || '')
        .replace('Local STT only', 'Local STT + Meso voice replies');
    }
  }

  function setButtonReady(button, note) {
    button.textContent = '▶ Play';
    button.disabled = false;
    button.setAttribute('aria-pressed', 'false');
    button.title = 'Play prepared Meso voice reply';
    note.textContent = ' Meso voice ready';
  }

  function setIdle(button = activeButton, note = activeNote) {
    if (button) {
      if (button._mesoXttsUrl) setButtonReady(button, note || { textContent: '' });
      else {
        button.textContent = '▶ Play';
        button.disabled = false;
        button.setAttribute('aria-pressed', 'false');
        button.title = 'Prepare Meso voice reply';
      }
    }
    activeButton = null;
    activeNote = null;
    activeAudio = null;
    if (status) status.textContent = baseStatus();
  }

  function stopPlayback() {
    operationId += 1;
    if (activeAbort) {
      try { activeAbort.abort(); } catch (_) {}
      activeAbort = null;
    }
    if (activeAudio) {
      try { activeAudio.pause(); activeAudio.currentTime = 0; } catch (_) {}
    }
    if (synth && (synth.speaking || synth.pending)) synth.cancel();
    setIdle();
  }

  function containsArabic(text) {
    return /[\u0600-\u06ff\u0750-\u077f\u08a0-\u08ff]/.test(text);
  }

  function chooseVoice(languagePrefix) {
    if (!synth) return null;
    const voices = synth.getVoices();
    const prefix = languagePrefix.toLowerCase();
    return voices.find((voice) => String(voice.lang || '').toLowerCase().startsWith(prefix)) || null;
  }

  function browserFallback(text, button, note, reason = '') {
    if (!synth || typeof window.SpeechSynthesisUtterance !== 'function') {
      setIdle(button, note);
      note.textContent = ' Voice unavailable';
      if (status) status.textContent = 'Meso voice offline · browser speech unavailable';
      return;
    }

    if (synth.speaking || synth.pending) synth.cancel();
    const arabic = containsArabic(text);
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = arabic ? 'ar-AE' : 'en-US';
    utterance.rate = 0.96;
    utterance.pitch = 1.0;
    const voice = chooseVoice(arabic ? 'ar' : 'en');
    if (voice) utterance.voice = voice;

    activeButton = button;
    activeNote = note;
    button.textContent = '■ Stop';
    button.disabled = false;
    button.setAttribute('aria-pressed', 'true');
    button.title = 'Stop reply audio';
    note.textContent = ' Browser fallback';
    if (status) status.textContent = `Speaking · Browser fallback · ${utterance.lang}${reason ? ' · Meso voice offline' : ''}`;

    utterance.addEventListener('end', () => setIdle(button, note), { once: true });
    utterance.addEventListener('error', (event) => {
      setIdle(button, note);
      if (status) status.textContent = `Reply audio unavailable · ${event.error || 'speech error'}`;
    }, { once: true });
    synth.speak(utterance);
  }

  function createPreparedAudio(url) {
    const audio = new Audio();
    audio.preload = 'auto';
    audio.playsInline = true;
    audio.muted = false;
    audio.volume = 1;
    audio.src = url;
    try { audio.load(); } catch (_) {}
    return audio;
  }

  function clearPrepared(button) {
    if (!button) return;
    if (button._mesoXttsAudio) {
      try { button._mesoXttsAudio.pause(); } catch (_) {}
    }
    button._mesoXttsAudio = null;
    button._mesoXttsUrl = '';
  }

  function playPreparedXtts(button, note) {
    const url = button._mesoXttsUrl;
    if (!url) return false;

    if (activeAudio && activeAudio !== button._mesoXttsAudio) {
      try { activeAudio.pause(); activeAudio.currentTime = 0; } catch (_) {}
    }
    if (synth && (synth.speaking || synth.pending)) synth.cancel();

    const audio = button._mesoXttsAudio || createPreparedAudio(url);
    button._mesoXttsAudio = audio;
    activeAudio = audio;
    activeButton = button;
    activeNote = note;

    audio.muted = false;
    audio.volume = 1;
    try { audio.currentTime = 0; } catch (_) {}

    button.textContent = '■ Stop';
    button.disabled = false;
    button.setAttribute('aria-pressed', 'true');
    button.title = 'Stop Meso voice reply audio';
    note.textContent = ' Meso voice';
    if (status) status.textContent = 'Starting · Meso voice…';

    audio.onplaying = () => {
      if (activeAudio === audio && status) status.textContent = 'Speaking · Meso voice · Local XTTS';
    };
    audio.onended = () => {
      if (activeAudio === audio) setIdle(button, note);
    };
    audio.onerror = () => {
      if (activeAudio === audio) {
        activeAudio = null;
        activeButton = null;
        activeNote = null;
        clearPrepared(button);
        button.textContent = '▶ Play';
        button.disabled = false;
        button.setAttribute('aria-pressed', 'false');
        note.textContent = ' Meso voice media expired · tap Play to regenerate';
        if (status) status.textContent = 'Meso voice media could not be loaded · tap Play to regenerate';
      }
    };

    const playPromise = audio.play();
    if (playPromise && typeof playPromise.catch === 'function') {
      playPromise.catch((error) => {
        if (activeAudio !== audio) return;
        activeAudio = null;
        activeButton = null;
        activeNote = null;
        const name = String(error?.name || 'PlaybackError');
        if (name === 'NotSupportedError') clearPrepared(button);
        button.textContent = '▶ Play';
        button.disabled = false;
        button.setAttribute('aria-pressed', 'false');
        note.textContent = ` Playback blocked · ${name}`;
        if (status) status.textContent = `Playback blocked · ${name}`;
      });
    }
    return true;
  }

  function normalizeAudioUrl(value) {
    const raw = String(value || '').trim();
    if (!raw) throw new Error('Missing Meso audio URL');
    const parsed = new URL(raw, window.location.origin);
    if (parsed.origin !== window.location.origin
        || parsed.pathname !== '/meso/api/tts-audio.php'
        || !/^[a-f0-9]{64}$/.test(parsed.searchParams.get('id') || '')) {
      throw new Error('Invalid Meso audio URL');
    }
    return parsed.pathname + parsed.search;
  }

  async function prepareXtts(text, button, note, { background = false } = {}) {
    const clean = String(text || '').trim();
    if (!clean || button._mesoXttsUrl || button._mesoXttsPreparing) return Boolean(button._mesoXttsUrl);

    const controller = new AbortController();
    button._mesoXttsPreparing = true;
    button._mesoXttsAbort = controller;
    if (!background) activeAbort = controller;

    button.textContent = '…';
    button.disabled = true;
    button.setAttribute('aria-pressed', 'false');
    button.title = 'Preparing Meso voice reply audio';
    note.textContent = ' Preparing · Meso voice';
    if (!background && status) status.textContent = 'Preparing · Meso voice…';

    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, 300000);

    try {
      const response = await fetch('/meso/api/tts.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ text: clean }),
        signal: controller.signal
      });

      if (response.status === 403) {
        location.reload();
        return false;
      }
      const payload = await response.json();
      const engine = String(payload?.engine || response.headers.get('x-meso-voice') || '').toLowerCase();
      const format = String(payload?.format || response.headers.get('x-meso-voice-format') || '').toLowerCase();
      const profile = String(payload?.profile || response.headers.get('x-meso-voice-profile') || '').toLowerCase();
      const audioUrl = normalizeAudioUrl(payload?.audio_url);
      if (!response.ok || payload?.ok !== true || engine !== 'xtts-v2' || format !== 'mp3' || profile !== 'meso-a' || !audioUrl.includes('tts-audio.php')) {
        throw new Error(`Meso voice HTTP ${response.status}`);
      }

      button._mesoXttsUrl = audioUrl;
      button._mesoXttsAudio = createPreparedAudio(audioUrl);
      setButtonReady(button, note);
      if (!background && status) status.textContent = 'Meso voice ready · tap Play';
      return true;
    } catch (error) {
      if (error?.name === 'AbortError' && !timedOut) return false;
      clearPrepared(button);
      button.textContent = '▶ Play';
      button.disabled = false;
      button.setAttribute('aria-pressed', 'false');
      button.title = 'Retry Meso voice preparation';
      note.textContent = timedOut ? ' Meso voice preparation timed out' : ' Meso voice unavailable · browser fallback';
      if (!background && status) status.textContent = timedOut ? 'Meso voice preparation timed out' : 'Meso voice unavailable';
      return false;
    } finally {
      clearTimeout(timer);
      button._mesoXttsPreparing = false;
      button._mesoXttsAbort = null;
      if (activeAbort === controller) activeAbort = null;
    }
  }

  async function handlePlayClick(text, button, note) {
    const clean = String(text || '').trim();
    if (!clean) return;

    if (activeButton === button && activeAudio && !activeAudio.paused) {
      stopPlayback();
      return;
    }

    if (button._mesoXttsUrl) {
      playPreparedXtts(button, note);
      return;
    }

    const prepared = await prepareXtts(clean, button, note, { background: false });
    if (!prepared) browserFallback(clean, button, note, 'unavailable');
    // The next explicit click plays the already-prepared same-origin media URL.
  }

  function decorate(card, autoPrepare = false) {
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
    button.setAttribute('aria-label', 'Play assistant reply using Meso voice');
    button.setAttribute('aria-pressed', 'false');
    button.title = 'Prepare or play reply using Meso voice';
    Object.assign(button.style, {
      padding: '6px 10px', borderRadius: '9px', border: '1px solid #4c5263',
      background: '#171b27', color: '#f6f7fb', cursor: 'pointer', fontSize: '12px'
    });

    const note = document.createElement('span');
    note.textContent = autoPrepare ? ' Preparing · Meso voice' : ' Meso voice';
    Object.assign(note.style, { marginLeft: '7px', color: '#9299aa', fontSize: '10px' });
    button.addEventListener('click', () => handlePlayClick(text, button, note));

    tools.append(button, note);
    card.appendChild(tools);

    if (autoPrepare) {
      queueMicrotask(() => prepareXtts(text, button, note, { background: true }));
    }
  }

  function decorateAll(root = messages, autoPrepare = false) {
    if (root instanceof HTMLElement && root.matches('.msg.assistant')) decorate(root, autoPrepare);
    if (root.querySelectorAll) {
      for (const card of root.querySelectorAll('.msg.assistant')) decorate(card, autoPrepare);
    }
  }

  markMesoVoiceUi();
  decorateAll();
  const observer = new MutationObserver((records) => {
    for (const record of records) {
      for (const node of record.addedNodes) {
        if (node instanceof HTMLElement) decorateAll(node, true);
      }
    }
  });
  observer.observe(messages, { childList: true, subtree: true });

  function cleanup() {
    stopPlayback();
    for (const button of messages.querySelectorAll('button')) {
      if (button._mesoXttsAbort) {
        try { button._mesoXttsAbort.abort(); } catch (_) {}
      }
      if (button._mesoXttsAudio) {
        try { button._mesoXttsAudio.pause(); } catch (_) {}
      }
    }
  }
  window.addEventListener('pagehide', cleanup);
  window.addEventListener('beforeunload', cleanup);
})();
