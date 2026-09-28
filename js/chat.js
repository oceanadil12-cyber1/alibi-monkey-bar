/* Chat assistant: a gold "Ask us" button on every page that opens a chat panel.
 * Questions go to api/chat.php, which answers from the bar's own information
 * (see data/chatbot-knowledge.md). The conversation is kept for the browser tab
 * (sessionStorage), so it carries on when the guest follows a link to another page. */
(function () {
  const script = document.currentScript;
  const SITE = new URL('../', script.src);               // site root, wherever this page lives
  const ENDPOINT = new URL('api/chat.php', SITE).href;
  const LOGO = new URL('images/apple-touch-icon.png', SITE).href;
  const STORE = 'alibi-chat-v1';
  const MAX_KEEP = 30;                                   // messages remembered per tab
  const PHONE = '(954) 565-2526';
  const GREETING = "I'm the Alibi's virtual assistant. Ask me about tonight's shows, the menu, Happy Hour, reservations or private events.";
  const SUGGESTIONS = [
    { icon: '\u{1F3AD}', label: "Tonight's shows", text: "What's on tonight?" },
    { icon: '\u{1F379}', label: 'Happy Hour', text: 'What are the Happy Hour deals?' },
    { icon: '\u{1F4C5}', label: 'Book a table', text: 'How do I book a table?' },
    { icon: '\u{1F389}', label: 'Private parties', text: 'Tell me about private party packages.' },
  ];

  const ICONS = {
    chat: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.8 7L4 20.5l1.5-4.9A8 8 0 1 1 21 12z"/></svg>',
    close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    restart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>',
    send: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>',
  };

  // ---------- saved state ----------

  function load() {
    try { return JSON.parse(sessionStorage.getItem(STORE)) || { open: false, messages: [] }; } catch (e) { return { open: false, messages: [] }; }
  }
  function save() {
    state.messages = state.messages.slice(-MAX_KEEP);
    try { sessionStorage.setItem(STORE, JSON.stringify(state)); } catch (e) { /* storage unavailable */ }
  }
  // Anonymous id that groups one conversation in the chat history (api/chats.php).
  const newCid = () => 'c-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
  const state = load();
  if (!state.cid) state.cid = newCid();

  // ---------- markup ----------

  const launcher = document.createElement('button');
  launcher.type = 'button';
  launcher.className = 'chat-launcher';
  launcher.setAttribute('aria-controls', 'chat-panel');
  launcher.setAttribute('aria-expanded', 'false');
  launcher.setAttribute('aria-label', 'Chat with the Alibi assistant');
  launcher.innerHTML = ICONS.chat + '<span>Ask us</span>';

  const panel = document.createElement('section');
  panel.className = 'chat-panel';
  panel.id = 'chat-panel';
  panel.hidden = true;
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Chat with the Alibi assistant');
  panel.innerHTML =
    '<div class="chat-pride" aria-hidden="true"></div>' +
    '<div class="chat-head">' +
      '<span class="chat-avatar-wrap"><img class="chat-avatar" src="' + LOGO + '" alt="" width="42" height="42"><i class="chat-online" aria-hidden="true"></i></span>' +
      '<div class="chat-title"><strong>Ask the Alibi</strong><span>Online now &middot; virtual assistant</span></div>' +
      '<button type="button" class="chat-icon-btn" data-restart aria-label="Start a new chat" title="New chat">' + ICONS.restart + '</button>' +
      '<button type="button" class="chat-icon-btn" data-close aria-label="Close chat" title="Close">' + ICONS.close + '</button>' +
    '</div>' +
    '<div class="chat-body">' +
      '<div class="chat-welcome">' +
        '<img class="chat-welcome-logo" src="' + LOGO + '" alt="" width="64" height="64">' +
        '<h3>Hey there! \u{1F44B}</h3>' +
        '<p>' + GREETING + '</p>' +
        '<div class="chat-chips"></div>' +
      '</div>' +
      '<div class="chat-log" role="log" aria-live="polite"></div>' +
    '</div>' +
    '<form class="chat-form">' +
      '<div class="chat-compose">' +
        '<textarea rows="1" maxlength="600" placeholder="Ask about shows, food, bookings&hellip;" aria-label="Your message"></textarea>' +
        '<button type="submit" class="chat-send" aria-label="Send" disabled>' + ICONS.send + '</button>' +
      '</div>' +
    '</form>' +
    '<p class="chat-foot">To book or for anything urgent, call <a href="tel:+19545652526">' + PHONE + '</a>.' +
      '<br>Chats are saved for 120 days to improve our service.</p>';

  document.body.append(launcher, panel);
  const body = panel.querySelector('.chat-body');
  const log = panel.querySelector('.chat-log');
  const chips = panel.querySelector('.chat-chips');
  const form = panel.querySelector('.chat-form');
  const input = form.querySelector('textarea');
  const sendBtn = form.querySelector('.chat-send');
  let busy = false;

  SUGGESTIONS.forEach((s) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.innerHTML = '<span class="chat-chip-icon" aria-hidden="true">' + s.icon + '</span>';
    b.append(s.label);
    b.addEventListener('click', () => send(s.text));
    chips.append(b);
  });

  // ---------- rendering ----------

  // Turns site paths (/menu.html#drinks), phone numbers, @alibimonkeybar and web
  // addresses into links. Built from text nodes, never innerHTML, so replies can't inject markup.
  const LINKS = /(https?:\/\/[^\s)]+[^\s).,!?])|((?:^|(?<=[\s(]))\/[a-z0-9-]+\.html(?:#[\w-]+)?)|(\(?\b\d{3}\)?[\s.-]?\d{3}[\s.-]\d{4}\b)|(@alibimonkeybar\b)/gi;

  function linkify(el, text) {
    // Markdown bold/italics the AI sometimes adds despite being asked not to.
    text = text.replace(/\*\*(.+?)\*\*/g, '$1').replace(/(^|[\s(])\*([^*\n]+)\*(?=$|[\s).,!?:;])/gm, '$1$2');
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

  function scrollDown() { body.scrollTop = body.scrollHeight; }

  // One message: bot replies get the logo beside them. Returns the bubble.
  function row(role, animate) {
    const r = document.createElement('div');
    r.className = 'chat-row ' + (role === 'user' ? 'from-user' : 'from-bot') + (animate ? ' is-new' : '');
    if (role !== 'user') {
      const img = document.createElement('img');
      img.className = 'chat-row-avatar';
      img.src = LOGO;
      img.alt = '';
      r.append(img);
    }
    const msg = document.createElement('div');
    msg.className = 'chat-msg';
    r.append(msg);
    log.append(r);
    return msg;
  }

  function bubble(role, text, opts) {
    opts = opts || {};
    const msg = row(role, opts.animate !== false);
    if (opts.error) msg.classList.add('is-error');
    linkify(msg, text);
    scrollDown();
    return msg;
  }

  function render() {
    log.textContent = '';
    state.messages.forEach((m) => {
      bubble(m.role, m.content, { animate: false });
      if (m.cart) orderCard(m.cart, false);
    });
    chips.hidden = state.messages.length > 0;
  }

  // ---------- food orders ----------

  const CART_KEY = 'alibi-cart-v1'; // shared with js/order.js
  const money = (cents) => '$' + (cents / 100).toFixed(2);

  function currentCart() {
    try {
      return (JSON.parse(localStorage.getItem(CART_KEY)) || []).map((l) => ({ itemId: l.itemId, variationId: l.variationId, qty: l.qty, modifiers: l.modifiers }));
    } catch (e) { return []; }
  }

  // Puts dishes the assistant added into the site's normal cart. On the menu page
  // js/order.js takes them directly; elsewhere they wait in storage for it.
  function addToCart(lines) {
    if (window.alibiOrder) return window.alibiOrder.add(lines);
    try {
      const cart = JSON.parse(localStorage.getItem(CART_KEY)) || [];
      lines.forEach((l) => cart.push({
        key: crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2),
        itemId: l.itemId, variationId: l.variationId, qty: l.qty, modifiers: l.modifiers || [], note: l.note || '',
      }));
      localStorage.setItem(CART_KEY, JSON.stringify(cart));
    } catch (e) { /* storage unavailable: the guest can still order on the menu page */ }
  }

  function reviewOrder() {
    if (window.alibiOrder) { setOpen(false, false); window.alibiOrder.open(); }
    else location.href = new URL('menu.html#order', SITE).href;
  }

  // "Added to your order" card with a Review & pay button, under the reply.
  function orderCard(items, animate) {
    const card = document.createElement('div');
    card.className = 'chat-order' + (animate ? ' is-new' : '');
    const head = document.createElement('strong');
    head.textContent = '\u{1F6D2} Added to your order';
    const list = document.createElement('ul');
    items.forEach((i) => {
      const li = document.createElement('li');
      const price = document.createElement('span');
      price.textContent = money(i.total);
      li.append(i.label, price);
      list.append(li);
    });
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = 'Review & pay';
    btn.addEventListener('click', reviewOrder);
    card.append(head, list, btn);
    log.append(card);
    scrollDown();
  }

  function typing() {
    const msg = row('assistant', true);
    msg.classList.add('chat-typing');
    msg.innerHTML = '<span class="chat-dots" aria-hidden="true"><i></i><i></i><i></i></span>';
    scrollDown();
    // Local test models can be slow; let the guest know it's still working.
    const slow = setTimeout(() => { msg.append('Still thinking…'); scrollDown(); }, 12000);
    return () => { clearTimeout(slow); msg.parentNode.remove(); };
  }

  // ---------- talking to the server ----------

  async function send(text) {
    text = text.trim().slice(0, 600);
    if (!text || busy) return;
    busy = true;
    chips.hidden = true;
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
        body: JSON.stringify({
          messages: state.messages.map((m) => ({ role: m.role, content: m.content })),
          cart: currentCart(), // so the assistant knows what's already ordered
          cid: state.cid,
        }),
      });
      data = await res.json().catch(() => null);
    } catch (e) { /* network error: handled below */ }
    stopTyping();

    if (data && data.ok) {
      const msg = { role: 'assistant', content: data.reply };
      if (Array.isArray(data.cart) && data.cart.length) {
        addToCart(data.cart);
        msg.cart = data.cart.map((l) => ({ label: l.label, total: l.total })); // just what the card shows
      }
      state.messages.push(msg);
      save();
      bubble('assistant', data.reply);
      if (msg.cart) orderCard(msg.cart, true);
    } else {
      // Failed turns aren't kept, so the guest can simply ask again.
      state.messages.pop();
      save();
      const err = bubble('assistant', (data && data.error) || "Sorry, I can't connect right now. Please call us at " + PHONE + '.', { error: true });
      if (data && data.detail) {
        const s = document.createElement('small');
        s.textContent = 'For the developer: ' + data.detail;
        err.append(s);
        scrollDown();
      }
    }
    busy = false;
    grow();
    if (!panel.hidden) input.focus();
  }

  // ---------- open / close ----------

  function setOpen(open, focus) {
    panel.hidden = !open;
    launcher.setAttribute('aria-expanded', String(open));
    launcher.classList.remove('is-pulse');
    state.open = open;
    save();
    if (open) { scrollDown(); requestAnimationFrame(scrollDown); } // again once fonts/layout settle
    if (open && focus) input.focus();
    if (!open && focus) launcher.focus();
  }

  // Grow the box with the text (up to ~5 lines); the send button lights up once there's something to send.
  function grow() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    input.style.overflowY = input.scrollHeight > 120 ? 'auto' : 'hidden';
    sendBtn.disabled = busy || !input.value.trim();
  }

  launcher.addEventListener('click', () => setOpen(true, true));
  panel.querySelector('[data-close]').addEventListener('click', () => setOpen(false, true));
  panel.querySelector('[data-restart]').addEventListener('click', () => {
    if (busy) return;
    state.messages = [];
    state.cid = newCid(); // a new chat is a new conversation in the history
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
  window.addEventListener('load', scrollDown);
  if (state.open) setOpen(true, false); // stay open when moving between pages
  else if (!state.messages.length) launcher.classList.add('is-pulse'); // a gentle "hello" on first visit
})();
