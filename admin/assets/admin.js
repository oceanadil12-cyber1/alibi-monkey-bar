/* Alibi admin panel: small helpers, no libraries. Every page works without this
   file; it adds hover text on charts, "are you sure?" prompts, item filtering,
   print, and live badges for new orders / reservations / messages. */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  // ----- Hover text for chart columns and bars (their title="" becomes a tooltip; also on keyboard focus) -----
  const tip = $('#tip');
  if (tip) {
    const show = (el) => {
      tip.textContent = el.dataset.tip; // text only, never HTML
      tip.hidden = false;
      const r = el.getBoundingClientRect();
      const t = tip.getBoundingClientRect();
      tip.style.left = Math.max(8, Math.min(innerWidth - t.width - 8, r.left + r.width / 2 - t.width / 2)) + 'px';
      tip.style.top = (r.top - t.height - 8 < 8 ? r.bottom + 8 : r.top - t.height - 8) + 'px';
    };
    $$('.chart [title], .bars [title]').forEach((el) => {
      el.dataset.tip = el.getAttribute('title');
      el.removeAttribute('title');
      el.addEventListener('pointerenter', () => show(el));
      el.addEventListener('focus', () => show(el));
      ['pointerleave', 'blur'].forEach((ev) => el.addEventListener(ev, () => { tip.hidden = true; }));
    });
  }

  // ----- "Are you sure?" on buttons marked data-confirm -----
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-confirm]');
    if (el && !window.confirm(el.dataset.confirm)) { e.preventDefault(); e.stopPropagation(); }
  }, true);

  // ----- Print button -----
  document.addEventListener('click', (e) => { if (e.target.closest('[data-print]')) window.print(); });

  // ----- Filter a table as you type (the menu list) -----
  $$('input[data-filter]').forEach((input) => {
    const table = $(input.dataset.filter);
    if (!table) return;
    const counter = $('[data-filter-count="' + input.dataset.filter + '"]');
    const total = $$('tbody tr[data-name]', table).length;
    const original = counter ? counter.textContent : '';
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      let shown = 0;
      let cat = null;
      let catHas = false;
      const flush = () => { if (cat) cat.hidden = !catHas; };
      $$('tbody tr', table).forEach((tr) => {
        if (tr.classList.contains('cat')) { flush(); cat = tr; catHas = false; return; }
        const hit = !q || (tr.dataset.name || '').includes(q);
        tr.hidden = !hit;
        if (hit) { shown++; catHas = true; }
      });
      flush();
      if (counter) counter.textContent = q ? shown + ' of ' + total + ' items shown' : original;
    });
  });

  // ----- Character counter under a text box -----
  $$('textarea[data-counter]').forEach((ta) => {
    const out = $(ta.dataset.counter);
    if (!out) return;
    const limit = +ta.dataset.counterLimit || 0;
    const update = () => {
      const bytes = new Blob([ta.value]).size;
      out.textContent = ta.value.length.toLocaleString() + ' characters · about ' + Math.ceil(ta.value.length / 4).toLocaleString() + ' tokens'
        + (limit && bytes > limit ? ' · too long' : '');
      out.style.color = limit && bytes > limit ? 'var(--crit)' : '';
    };
    ta.addEventListener('input', update);
    update();
  });

  // ----- Phone menu: Escape closes it, and the burger works from the keyboard -----
  const toggle = $('#navtoggle');
  const burger = $('.burger');
  if (toggle && burger) {
    burger.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle.checked = !toggle.checked; }
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') toggle.checked = false; });
  }

  // ----- Toasts -----
  const toasts = $('#toasts');
  const toast = (message, href, linkText) => {
    if (!toasts) return;
    const box = document.createElement('div');
    box.className = 'toast';
    const text = document.createElement('span');
    text.textContent = message;
    box.append(text);
    if (href) {
      const a = document.createElement('a');
      a.href = href;
      a.textContent = linkText || 'Open';
      box.append(a);
    }
    toasts.append(box);
    setTimeout(() => box.remove(), 14000);
  };

  // ----- Live badges: ask every 30 seconds whether anything new came in -----
  const url = document.body.dataset.countsUrl;
  if (url) {
    const kinds = { orders: ['New order received', '?page=orders&s=new'], reservations: ['New reservation request', '?page=reservations&view=pending'], messages: ['New message', '?page=messages&view=new'], 'waiter-assistance': ['Waiter assistance requested', '?page=waiter-assistance'] };
    const baseTitle = document.body.dataset.title || document.title;
    const last = {};
    Object.keys(kinds).forEach((k) => { const b = $('[data-badge="' + k + '"]'); last[k] = b ? +b.textContent || 0 : 0; });
    let timer = null;
    const apply = (c) => {
      let total = 0;
      Object.keys(kinds).forEach((k) => {
        const n = +c[k] || 0;
        total += n;
        $$('[data-badge="' + k + '"]').forEach((b) => { b.textContent = n; b.hidden = n === 0; });
        if (n > last[k]) toast(kinds[k][0], kinds[k][1], 'View');
        last[k] = n;
      });
      document.title = (total ? '(' + total + ') ' : '') + baseTitle;
    };
    const tick = () => fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => (r.status === 401 ? Promise.reject('out') : r.json()))
      .then(apply)
      .catch((err) => {
        if (err === 'out') { toast('You were signed out. Reload the page to sign in again.'); clearInterval(timer); }
      });
    timer = setInterval(() => { if (!document.hidden) tick(); }, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
  }
})();
