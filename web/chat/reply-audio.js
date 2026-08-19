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

  function setIdle(button = activeButton, note = activeNote) {
    if (button) {
      button.textContent = '▶ Play';
      button.setAttribute('aria-pressed', 'false');
      button.title = 'Play reply using local XTTS';
    }
    if (note && !button?._mesoXttsUrl) note.textContent = ' Local XTTS · browser fallback';
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

  function playXttsUrl(url, button, note) {
    if (activeAudio) {
      try { activeAudio.pause(); } catch (_) {}
    }
    if (synth && (synth.speaking || synth.pending)) synth.cancel();

    const audio = new Audio(url);
    activeAudio = audio;
    activeButton = button;
    activeNote = note;
    button.textContent = '■ Stop';
    button.setAttribute('aria-pressed', 'true');
    button.title = 'Stop local XTTS reply audio';
    note.textContent = ' Local XTTS';
    if (status) status.textContent = 'Speaking · Local XTTS';

    audio.addEventListener('ended', () => setIdle(button, note), { once: true });
    audio.addEventListener('error', () => {
      setIdle(button, note);
      note.textContent = ' Local XTTS audio error';
    }, { once: true });
    audio.play().catch((error) => {
      activeAudio = null;
      activeButton = null;
      activeNote = null;
      button.textContent = '▶ Play';
      button.setAttribute('aria-pressed', 'false');
      note.textContent = ' Local XTTS ready · tap Play';
      if (status) status.textContent = `Local XTTS ready${error?.name === 'NotAllowedError' ? ' · tap Play' : ''}`;
    });
  }

  async function xttsFirst(text, button, note) {
    const clean = String(text || '').trim();
    if (!clean) return;

    if (activeButton === button) {
      stopPlayback();
      return;
    }
    stopPlayback();

    if (button._mesoXttsUrl) {
      playXttsUrl(button._mesoXttsUrl, button, note);
      return;
    }

    const myOperation = ++operationId;
    const controller = new AbortController();
    activeAbort = controller;
    activeButton = button;
    activeNote = note;
    button.textContent = '…';
    button.setAttribute('aria-pressed', 'true');
    button.title = 'Generating local XTTS reply audio';
    note.textContent = ' Generating · Local XTTS';
    if (status) status.textContent = 'Generating · Local XTTS…';

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
          'Accept': 'audio/wav,application/json'
        },
        body: JSON.stringify({ text: clean }),
        signal: controller.signal
      });

      if (response.status === 403) {
        location.reload();
        return;
      }
      const contentType = String(response.headers.get('content-type') || '').toLowerCase();
      const engine = String(response.headers.get('x-meso-voice') || '').toLowerCase();
      if (!response.ok || !contentType.includes('audio/wav') || engine !== 'xtts-v2') {
        throw new Error(`XTTS HTTP ${response.status}`);
      }
      const blob = await response.blob();
      if (blob.size < 44 || blob.size > 33554432) throw new Error('Invalid XTTS WAV');
      if (myOperation !== operationId) return;

      const url = URL.createObjectURL(blob);
      cachedUrls.add(url);
      button._mesoXttsUrl = url;
      activeAbort = null;
      playXttsUrl(url, button, note);
    } catch (error) {
      if (myOperation !== operationId) return;
      activeAbort = null;
      if (error?.name === 'AbortError' && !timedOut) {
        setIdle(button, note);
        return;
      }
      browserFallback(clean, button, note, timedOut ? 'timeout' : 'unavailable');
    } finally {
      clearTimeout(timer);
    }
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
    button.setAttribute('aria-label', 'Play assistant reply using local XTTS');
    button.setAttribute('aria-pressed', 'false');
    button.title = 'Play reply using local XTTS';
    Object.assign(button.style, {
      padding: '6px 10px', borderRadius: '9px', border: '1px solid #4c5263',
      background: '#171b27', color: '#f6f7fb', cursor: 'pointer', fontSize: '12px'
    });

    const note = document.createElement('span');
    note.textContent = ' Local XTTS · browser fallback';
    Object.assign(note.style, { marginLeft: '7px', color: '#9299aa', fontSize: '10px' });
    button.addEventListener('click', () => xttsFirst(text, button, note));

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

  function cleanup() {
    stopPlayback();
    for (const url of cachedUrls) URL.revokeObjectURL(url);
    cachedUrls.clear();
  }
  window.addEventListener('pagehide', cleanup);
  window.addEventListener('beforeunload', cleanup);
})();
