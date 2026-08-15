'use strict';

(() => {
  const history = [];
  const $ = (id) => document.getElementById(id);
  const messages = $('messages');
  const input = $('message');
  const send = $('send');
  const status = $('status');
  const newChatButton = $('newChatBtn');

  if (!messages || !input || !send || !status) return;

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

  function setBusy(value) {
    send.disabled = value;
    input.disabled = value;
    status.textContent = value ? 'Thinking…' : 'Private text preflight';
  }

  function newChat() {
    history.length = 0;
    showEmpty('New conversation', 'No history was retained.');
    input.focus();
  }

  async function sendText() {
    const text = input.value.trim();
    if (!text || send.disabled) return;

    const prior = history.slice(-12);
    input.value = '';
    addMessage('user', text);
    history.push({ role: 'user', content: text });
    setBusy(true);

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

  send.addEventListener('click', sendText);
  if (newChatButton) newChatButton.addEventListener('click', newChat);
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
      event.preventDefault();
      sendText();
    }
  });
  input.focus();
})();
