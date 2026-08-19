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
  const cachedUrls = new Set();

  function baseStatus() {
    return 'Private text + local STT + local XTTS';
  }

  function markLocalXttsUi() {
    if (status) status.textContent = baseStatus();
    for (const row of document.querySelectorAll('.side .status')) {
      const label = row.querySelector('span:first-child');
      const pill = row.querySelector('.pill');
      if (label && pill && String(label.textContent || '').trim() === 'Cloned voice') {
        pill.textContent = 'LOCAL XTTS';
        pill.classList.remove('warn');
        pill.classList.add('good');
      }
    }
    const emptyDetail = messages.querySelector('.empty > div:last-child');
    if (emptyDetail) {
      emptyDetail.textContent = String(emptyDetail.textContent || '')
        .replace('Memory, persona and cloned voice remain off during this stage.', 'Memory and persona remain off. Reply audio uses local XTTS on MASTER-PC.');
    }
    const composerNote = document.querySelector('.composer small');
    if (composerNote) {
      composerNote.textContent = String(composerNote.textContent || '')
        .replace('Local STT only', 'Local STT + Local XTTS replies');
    }
  }

  function setButtonReady(button, note) {
    button.textContent = '▶ Play';
    button.disabled = false;
    button.setAttribute('aria-pressed', 'false');
    button.title = 'Play prepared local XTTS reply';
    note.textContent = ' Local XTTS ready';
  }

  function setIdle(button = activeButton, note = activeNote) {
    if (button) {
      if (button._mesoXttsUrl) setButtonReady(button, note || { textContent: '' });
      else {
        button.textContent = '▶ Play';
        button.disabled = false;
        button.setAttribute('aria-pressed', 'false');
        button.title = 'Prepare local XTTS reply';
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
      if (status) status.textContent = 'Local XTTS offline · browser speech unavailable';
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
    if (status) status.textContent = `Speaking · Browser fallback · ${utterance.lang}${reason ? ' · XTTS offline' : ''}`;

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
    button.title = 'Stop local XTTS reply audio';
    note.textContent = ' Local XTTS';
    if (status) status.textContent = 'Starting · Local XTTS…';

    audio.onplaying = () => {
      if (activeAudio === audio && status) status.textContent = 'Speaking · Local XTTS';
    };
    audio.onended = () => {
      if (activeAudio === audio) setIdle(button, note);
    };
    audio.onerror = () => {
      if (activeAudio === audio) {
        activeAudio = null;
        activeButton = null;
        activeNote = null;
        setButtonReady(button, note);
        note.textContent = ' Local XTTS MP3 decode error';
        if (status) status.textContent = 'Local XTTS MP3 could not be decoded by this browser';
      }
    };

    const playPromise = audio.play();
    if (playPromise && typeof playPromise.catch === 'function') {
      playPromise.catch((error) => {
        if (activeAudio !== audio) return;
        activeAudio = null;
        activeButton = null;
        activeNote = null;
        setButtonReady(button, note);
        const name = String(error?.name || 'PlaybackError');
        note.textContent = ` Playback blocked · ${name}`;
        if (status) status.textContent = `Playback blocked · ${name} · check tab/site sound permission`;
      });
    }
    return true;
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
    button.title = 'Preparing local XTTS reply audio';
    note.textContent = ' Preparing · Local XTTS';
    if (!background && status) status.textContent = 'Preparing · Local XTTS…';

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
          'Accept': 'audio/mpeg,application/json'
        },
        body: JSON.stringify({ text: clean }),
        signal: controller.signal
      });

      if (response.status === 403) {
        location.reload();
        return false;
      }
      const contentType = String(response.headers.get('content-type') || '').toLowerCase();
      const engine = String(response.headers.get('x-meso-voice') || '').toLowerCase();
      const format = String(response.headers.get('x-meso-voice-format') || '').toLowerCase();
      if (!response.ok || !contentType.includes('audio/mpeg') || engine !== 'xtts-v2' || format !== 'mp3') {
        throw new Error(`XTTS HTTP ${response.status}`);
      }
      const bytes = await response.arrayBuffer();
      if (bytes.byteLength < 1024 || bytes.byteLength > 8388608) throw new Error('Invalid XTTS MP3');
      const blob = new Blob([bytes], { type: 'audio/mpeg' });

      const url = URL.createObjectURL(blob);
      cachedUrls.add(url);
      button._mesoXttsUrl = url;
      button._mesoXttsAudio = createPreparedAudio(url);
      setButtonReady(button, note);
      if (!background && status) status.textContent = 'Local XTTS ready · tap Play';
      return true;
    } catch (error) {
      if (error?.name === 'AbortError' && !timedOut) return false;
      button.textContent = '▶ Play';
      button.disabled = false;
      button.setAttribute('aria-pressed', 'false');
      button.title = 'Retry local XTTS preparation';
      note.textContent = timedOut ? ' Local XTTS preparation timed out' : ' Local XTTS unavailable · browser fallback';
      if (!background && status) status.textContent = timedOut ? 'Local XTTS preparation timed out' : 'Local XTTS unavailable';
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
    // Deliberately do not call audio.play() here. The network wait can consume
    // browser user activation. The next explicit click plays the cached MP3.
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
    button.setAttribute('aria-label', 'Play assistant reply using local XTTS');
    button.setAttribute('aria-pressed', 'false');
    button.title = 'Prepare or play reply using local XTTS';
    Object.assign(button.style, {
      padding: '6px 10px', borderRadius: '9px', border: '1px solid #4c5263',
      background: '#171b27', color: '#f6f7fb', cursor: 'pointer', fontSize: '12px'
    });

    const note = document.createElement('span');
    note.textContent = autoPrepare ? ' Preparing · Local XTTS' : ' Local XTTS';
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

  markLocalXttsUi();
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
    }
    for (const url of cachedUrls) URL.revokeObjectURL(url);
    cachedUrls.clear();
  }
  window.addEventListener('pagehide', cleanup);
  window.addEventListener('beforeunload', cleanup);
})();
