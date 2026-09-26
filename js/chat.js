/* Chat assistant: a gold "Ask us" button on every page that opens a chat panel.
 * Questions go to api/chat.php, which answers from the bar's own information
 * (see data/chatbot-knowledge.md). The conversation is kept for the browser tab
 * (sessionStorage), so it carries on when the guest follows a link to another page. */
(function () {
  const script = document.currentScript;
  const SITE = new URL('../', script.src);               // site root, wherever this page lives
  const ENDPOINT = new URL('api/chat.php', SITE).href;
  const STORE = 'alibi-chat-v1';
  const MAX_KEEP = 30;                                   // messages remembered per tab
  const PHONE = '(954) 565-2526';
  const GREETING = "Hi! I'm the Alibi's virtual assistant. Ask me about tonight's shows, the menu, Happy Hour, reservations or private events.";
  const SUGGESTIONS = ["What's on tonight?", 'Happy Hour deals', 'How do I book a table?', 'Private party packages'];

  const ICONS = {
    chat: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.8 7L4 20.5l1.5-4.9A8 8 0 1 1 21 12z"/></svg>',
    close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    restart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>',
    send: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.4 20.4l17.5-7.5a1 1 0 0 0 0-1.8L3.4 3.6a.9.9 0 0 0-1.3 1L4 11l9 1-9 1-1.9 6.4a.9.9 0 0 0 1.3 1z"/></svg>',
  };

  // ---------- saved state ----------

  function load() {
    try { return JSON.parse(sessionStorage.getItem(STORE)) || { open: false, messages: [] }; } catch (e) { return { open: false, messages: [] }; }
  }
  function save() {
    state.messages = state.messages.slice(-MAX_KEEP);
    try { sessionStorage.setItem(STORE, JSON.stringify(state)); } catch (e) { /* storage unavailable */ }
  }
  const state = load();

  // ---------- markup ----------

  const launcher = document.createElement('button');
  launcher.type = 'button';
  launcher.className = 'chat-launcher';
  launcher.setAttribute('aria-controls', 'chat-panel');
  launcher.setAttribute('aria-expanded', 'false');
  launcher.innerHTML = ICONS.chat + '<span>Ask us</span>';
  launcher.setAttribute('aria-label', 'Chat with the Alibi assistant');

  const panel = document.createElement('section');
  panel.className = 'chat-panel';
  panel.id = 'chat-panel';
  panel.hidden = true;
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Chat with the Alibi assistant');
  panel.innerHTML =
    '<div class="chat-head">' +
      '<span class="chat-avatar" aria-hidden="true">A</span>' +
      '<div class="chat-title"><strong>Ask the Alibi</strong><span>Virtual assistant &middot; replies in seconds</span></div>' +
      '<button type="button" class="chat-icon-btn" data-restart aria-label="Start a new chat" title="New chat">' + ICONS.restart + '</button>' +
      '<button type="button" class="chat-icon-btn" data-close aria-label="Close chat" title="Close">' + ICONS.close + '</button>' +
    '</div>' +
    '<div class="chat-log" role="log" aria-live="polite"></div>' +
    '<div class="chat-suggest"></div>' +
    '<form class="chat-form">' +
      '<textarea rows="1" maxlength="600" placeholder="Type your question&hellip;" aria-label="Your message"></textarea>' +
      '<button type="submit" class="chat-send" aria-label="Send">' + ICONS.send + '</button>' +
    '</form>' +
    '<p class="chat-foot">AI assistant &middot; it can make mistakes. To book or for anything urgent, call <a href="tel:+19545652526">' + PHONE + '</a>.</p>';

  document.body.append(launcher, panel);
  const log = panel.querySelector('.chat-log');
  const suggest = panel.querySelector('.chat-suggest');
  const form = panel.querySelector('.chat-form');
  const input = form.querySelector('textarea');
  const sendBtn = form.querySelector('.chat-send');
  let busy = false;

  SUGGESTIONS.forEach((text) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = text;
    b.addEventListener('click', () => send(text));
    suggest.append(b);
  });

  // ---------- rendering ----------

  // Turns site paths (/menu.html#drinks), phone numbers, @alibimonkeybar and web
  // addresses into links. Built from text nodes, never innerHTML, so replies can't inject markup.
  const LINKS = /(https?:\/\/[^\s)]+[^\s).,!?])|((?:^|(?<=[\s(]))\/[a-z0-9-]+\.html(?:#[\w-]+)?)|(\(?\b\d{3}\)?[\s.-]?\d{3}[\s.-]\d{4}\b)|(@alibimonkeybar\b)/gi;

  function linkify(el, text) {
    text = text.replace(/\*\*(.+?)\*\*/g, '$1'); // the odd markdown bold slipping through
    let last = 0;
    text.replace(LINKS, (match, url, path, phone, insta, offset) => {
      el.append(text.slice(last, offset));
      const a = document.createElement('a');
      a.textContent = match;
      if (url) { a.href = url; a.target = '_blank'; a.rel = 'noopener'; }
      else if (path) a.href = new URL(path.slice(1), SITE).href;
      else if (phone) a.href = 'tel:+1' + match.replace(/\D/g, '').slice(-10);
      else { a.href = 'https://www.instagram.com/alibimonkeybar/'; a.target = '_blank'; a.rel = 'noopener'; }
      el.append(a);
      last = offset + match.length;
      return match;
    });
    el.append(text.slice(last));
  }

  function bubble(role, text, extraClass) {
    const div = document.createElement('div');
    div.className = 'chat-msg ' + (role === 'user' ? 'from-user' : 'from-bot') + (extraClass ? ' ' + extraClass : '');
    linkify(div, text);
    log.append(div);
    log.scrollTop = log.scrollHeight;
    return div;
  }

  function render() {
    log.textContent = '';
    bubble('assistant', GREETING);
    state.messages.forEach((m) => bubble(m.role, m.content));
    suggest.hidden = state.messages.length > 0;
  }

  function typing() {
    const div = document.createElement('div');
    div.className = 'chat-msg from-bot chat-typing';
    div.innerHTML = '<span class="chat-dots" aria-hidden="true"><i></i><i></i><i></i></span>';
    log.append(div);
    log.scrollTop = log.scrollHeight;
    // Local test models can be slow; let the guest know it's still working.
    const slow = setTimeout(() => div.append('Still thinking…'), 12000);
    return () => { clearTimeout(slow); div.remove(); };
  }

  // ---------- talking to the server ----------

  async function send(text) {
    text = text.trim().slice(0, 600);
    if (!text || busy) return;
    busy = true;
    sendBtn.disabled = true;
    suggest.hidden = true;
    state.messages.push({ role: 'user', content: text });
    save();
    bubble('user', text);
    input.value = '';
    grow();
    const stopTyping = typing();

    let data = null;
    try {
      const res = await fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ messages: state.messages }),
      });
      data = await res.json().catch(() => null);
    } catch (e) { /* network error: handled below */ }
    stopTyping();

    if (data && data.ok) {
      state.messages.push({ role: 'assistant', content: data.reply });
      save();
      bubble('assistant', data.reply);
    } else {
      // Failed turns aren't kept, so the guest can simply ask again.
      state.messages.pop();
      save();
      const err = bubble('assistant', (data && data.error) || "Sorry, I can't connect right now. Please call us at " + PHONE + '.', 'is-error');
      if (data && data.detail) { const s = document.createElement('small'); s.textContent = 'For the developer: ' + data.detail; err.append(s); }
    }
    busy = false;
    sendBtn.disabled = false;
    if (!panel.hidden) input.focus();
  }

  // ---------- open / close ----------

  function setOpen(open, focus) {
    panel.hidden = !open;
    launcher.setAttribute('aria-expanded', String(open));
    state.open = open;
    save();
    if (open && focus) input.focus();
    if (!open && focus) launcher.focus();
  }

  function grow() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  }

  launcher.addEventListener('click', () => setOpen(true, true));
  panel.querySelector('[data-close]').addEventListener('click', () => setOpen(false, true));
  panel.querySelector('[data-restart]').addEventListener('click', () => {
    if (busy) return;
    state.messages = [];
    save();
    render();
    input.focus();
  });
  panel.addEventListener('keydown', (e) => { if (e.key === 'Escape') setOpen(false, true); });
  form.addEventListener('submit', (e) => { e.preventDefault(); send(input.value); });
  input.addEventListener('input', grow);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(input.value); }
  });

  render();
  if (state.open) setOpen(true, false); // stay open when moving between pages
})();
