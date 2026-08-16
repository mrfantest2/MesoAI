'use strict';

(() => {
  const history = [];
  const $ = (id) => document.getElementById(id);
  const messages = $('messages');
  const input = $('message');
  const send = $('send');
  const mic = $('mic');
  const status = $('status');
  const newChatButton = $('newChatBtn');

  if (!messages || !input || !send || !status) return;

  let recorder = null;
  let stream = null;
  let chunks = [];
  let autoStopTimer = null;
  let recording = false;
  let transcribing = false;

  function baseStatus() {
    return 'Private text + local STT preflight';
  }

  function showEmpty(title, detail) {
    messages.replaceChildren();
    const wrap = document.createElement('div');
    wrap.className = 'empty';
    const orb = document.createElement('div');
    orb.className = 'orb';
    orb.textContent = '✦';
    const strong = document.createElement('strong');
    strong.textContent = title;
    const body = document.createElement('div');
    body.style.marginTop = '7px';
    body.textContent = detail;
    wrap.append(orb, strong, body);
    messages.appendChild(wrap);
  }

  function addMessage(role, text, meta = '') {
    const empty = messages.querySelector('.empty');
    if (empty) messages.replaceChildren();

    const card = document.createElement('div');
    card.className = `msg ${role}`;
    const label = document.createElement('div');
    label.className = 'role';
    label.textContent = meta ? `${role} · ${meta}` : role;
    const body = document.createElement('div');
    body.textContent = String(text ?? '');
    card.append(label, body);
    messages.appendChild(card);
    messages.scrollTop = messages.scrollHeight;
  }

  function setBusy(value, label = '') {
    send.disabled = value;
    input.disabled = value;
    if (mic && !recording) mic.disabled = value;
    status.textContent = label || (value ? 'Thinking…' : baseStatus());
  }

  function stopTracks() {
    if (stream) {
      for (const track of stream.getTracks()) track.stop();
    }
    stream = null;
  }

  function resetRecorderUi() {
    recording = false;
    clearTimeout(autoStopTimer);
    autoStopTimer = null;
    if (mic) {
      mic.classList.remove('recording');
      mic.setAttribute('aria-pressed', 'false');
      mic.textContent = '🎙';
    }
    stopTracks();
  }

  function newChat() {
    if (recording || transcribing) return;
    history.length = 0;
    showEmpty('New conversation', 'No history was retained. Microphone transcription remains local.');
    input.focus();
  }

  async function sendText() {
    const text = input.value.trim();
    if (!text || send.disabled) return;

    const prior = history.slice(-12);
    input.value = '';
    addMessage('user', text);
    history.push({ role: 'user', content: text });
    setBusy(true, 'Thinking…');

    try {
      const response = await fetch('/meso/api/chat.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ message: text, history: prior })
      });

      let body = {};
      try {
        body = await response.json();
      } catch (_) {
        body = { ok: false, error: 'invalid_json' };
      }

      if (response.status === 403) {
        location.reload();
        return;
      }
      if (!response.ok || !body.ok) {
        throw new Error(body.message || body.error || `HTTP ${response.status}`);
      }

      const meta = `${body.provider || ''}${body.model ? ` · ${body.model}` : ''}`;
      addMessage('assistant', body.reply, meta);
      history.push({ role: 'assistant', content: String(body.reply ?? '') });
      if (history.length > 24) history.splice(0, history.length - 24);
    } catch (error) {
      addMessage('assistant', `Chat error: ${error.message}`, 'system');
    } finally {
      setBusy(false);
      input.focus();
    }
  }

  function bestRecorderMime() {
    if (!window.MediaRecorder || typeof MediaRecorder.isTypeSupported !== 'function') return '';
    const candidates = [
      'audio/webm;codecs=opus',
      'audio/ogg;codecs=opus',
      'audio/mp4',
      'audio/webm'
    ];
    return candidates.find((value) => MediaRecorder.isTypeSupported(value)) || '';
  }

  async function transcribeBlob(blob) {
    transcribing = true;
    setBusy(true, 'Transcribing locally…');
    try {
      const response = await fetch('/meso/api/transcribe.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Content-Type': blob.type || 'audio/webm',
          'Accept': 'application/json'
        },
        body: blob
      });
      let body = {};
      try {
        body = await response.json();
      } catch (_) {
        body = { ok: false, error: 'invalid_json' };
      }
      if (response.status === 403) {
        location.reload();
        return;
      }
      if (!response.ok || !body.ok || !String(body.transcript || '').trim()) {
        throw new Error(body.error || `HTTP ${response.status}`);
      }

      input.value = String(body.transcript).trim();
      status.textContent = `Transcribed locally${body.language ? ` · ${body.language}` : ''}`;
      transcribing = false;
      setBusy(false, status.textContent);
      await sendText();
    } catch (error) {
      addMessage('assistant', `Microphone transcription error: ${error.message}`, 'local STT');
    } finally {
      transcribing = false;
      if (!send.disabled) status.textContent = baseStatus();
      if (mic) mic.disabled = false;
    }
  }

  async function startRecording() {
    if (!mic || recording || transcribing || send.disabled) return;
    if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
      addMessage('assistant', 'This browser does not provide microphone recording support.', 'local STT');
      return;
    }

    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const mime = bestRecorderMime();
      recorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);
      chunks = [];
      recorder.addEventListener('dataavailable', (event) => {
        if (event.data && event.data.size > 0) chunks.push(event.data);
      });
      recorder.addEventListener('stop', async () => {
        const type = recorder?.mimeType || mime || 'audio/webm';
        const blob = new Blob(chunks, { type });
        chunks = [];
        recorder = null;
        resetRecorderUi();
        if (!blob.size) {
          addMessage('assistant', 'No microphone audio was captured.', 'local STT');
          setBusy(false);
          return;
        }
        await transcribeBlob(blob);
      }, { once: true });

      recorder.start(250);
      recording = true;
      mic.classList.add('recording');
      mic.setAttribute('aria-pressed', 'true');
      mic.textContent = '■';
      send.disabled = true;
      input.disabled = true;
      status.textContent = 'Recording locally… tap ■ to stop';
      autoStopTimer = setTimeout(() => stopRecording(), 60000);
    } catch (error) {
      resetRecorderUi();
      setBusy(false);
      addMessage('assistant', `Microphone unavailable: ${error.message}`, 'local STT');
    }
  }

  function stopRecording() {
    if (!recording || !recorder) return;
    try {
      if (recorder.state !== 'inactive') recorder.stop();
    } catch (error) {
      resetRecorderUi();
      setBusy(false);
      addMessage('assistant', `Could not stop microphone recording: ${error.message}`, 'local STT');
    }
  }

  send.addEventListener('click', sendText);
  if (newChatButton) newChatButton.addEventListener('click', newChat);
  if (mic) {
    mic.addEventListener('click', () => recording ? stopRecording() : startRecording());
  }
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
      event.preventDefault();
      sendText();
    }
  });
  window.addEventListener('pagehide', stopTracks);
  input.focus();
})();
