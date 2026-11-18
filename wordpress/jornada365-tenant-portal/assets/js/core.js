/* Tenant Jornada365 — core: i18n, DOM helpers, theme/language, API, motion effects. No build step. */
(function () {
  'use strict';
  const CFG = window.J365TP || {};
  // The active language can change without a reload (signed-in portal), so these are mutable.
  let DICT = CFG.i18n || {};
  let INTL = CFG.intl || 'pt-BR';
  const RM = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)').matches : false;

  /* ------------------------------------------------------------ i18n (keys are the Portuguese text) */
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  const reEsc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  let PATTERNS = [];
  let MEMO = new Map(); // translations are looked up for every cell and search keystroke
  function compile() {
    PATTERNS = [];
    MEMO = new Map();
    for (const [k, v] of Object.entries(DICT)) {
      if (!k.includes('{')) continue;
      if ((k.replace(/\{[ns]\}/g, '').match(/\p{L}/gu) || []).length < 3) continue; // too generic
      const parts = k.split(/(\{[ns]\})/);
      const src = '^' + parts.map((p) => (p === '{n}' ? '([\\d.,\\s%+\\-−—]+|—)' : p === '{s}' ? '(.+?)' : reEsc(p))).join('') + '$';
      // Cheap pre-filter: the longest literal chunk must appear in the text before the regex runs.
      const need = parts.filter((p) => !/^\{[ns]\}$/.test(p)).sort((a, b) => b.length - a.length)[0] || '';
      try { PATTERNS.push([new RegExp(src, 's'), v, src.length, need]); } catch (e) { /* ignore */ }
    }
    PATTERNS.sort((a, b) => b[2] - a[2]);
  }
  compile();

  function lookup(s) {
    if (!s || typeof s !== 'string' || !DICT) return s;
    if (Object.prototype.hasOwnProperty.call(DICT, s)) return DICT[s];
    const tr = s.trim();
    if (tr !== s && Object.prototype.hasOwnProperty.call(DICT, tr)) return s.replace(tr, DICT[tr]);
    for (const [re, tpl, , need] of PATTERNS) {
      if (need && !s.includes(need)) continue;
      const m = s.match(re);
      if (m) { let i = 0; return tpl.replace(/\{[ns]\}/g, () => lookup(m[++i] ?? '')); }
    }
    // "Label: detail" (warnings, errors): label and detail are translated independently.
    const pos = s.indexOf(': ');
    if (pos > 0 && pos < 160) {
      const pre = s.slice(0, pos + 1), rest = s.slice(pos + 2);
      const p2 = Object.prototype.hasOwnProperty.call(DICT, pre) ? DICT[pre] : pre;
      const r2 = rest ? lookup(rest) : rest;
      if (p2 !== pre || r2 !== rest) return p2 + ' ' + r2;
    }
    return s;
  }
  /** t('Contas inativas há mais de {n} dias', { n: 90 }) */
  function t(s, vars) {
    let out;
    if (typeof s === 'string' && s.length < 400) {
      out = MEMO.get(s);
      if (out === undefined) { out = lookup(s); if (MEMO.size > 40000) MEMO.clear(); MEMO.set(s, out); }
    } else out = lookup(s);
    if (vars && typeof out === 'string') for (const [k, v] of Object.entries(vars)) out = out.split('{' + k + '}').join(String(v));
    return out;
  }

  /* ------------------------------------------------------------ DOM */
  const TR_ATTRS = new Set(['placeholder', 'title', 'aria-label', 'alt', 'data-tip']);
  /** Element builder. Text children and a few attributes are translated automatically. */
  const h = (tag, attrs, ...children) => {
    const el = document.createElement(tag);
    if (attrs) for (const [k, v] of Object.entries(attrs)) {
      if (k === 'class') el.className = v;
      else if (k === 'html') el.innerHTML = v;
      else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
      else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
      else if (v !== null && v !== undefined && v !== false) el.setAttribute(k, v === true ? '' : (TR_ATTRS.has(k) ? t(String(v)) : v));
    }
    for (const c of children.flat(Infinity)) {
      if (c === null || c === undefined || c === false) continue;
      el.appendChild(typeof c === 'string' ? document.createTextNode(t(c)) : (typeof c === 'number' ? document.createTextNode(String(c)) : c));
    }
    return el;
  };
  /** Text that must not be translated (names, codes). */
  const raw = (s) => document.createTextNode(String(s ?? ''));
  const svg = (markup, cls = '') => { const s = document.createElement('span'); s.className = 'svgw ' + cls; s.innerHTML = markup; return s; };

  /* ------------------------------------------------------------ formatting (active locale) */
  const fmtDate = (iso) => { if (!iso) return '—'; const d = new Date(iso); return isNaN(d) ? iso : d.toLocaleString(INTL, { dateStyle: 'short', timeStyle: 'short' }); };
  const fmtDay = (iso) => { if (!iso) return '—'; const d = new Date(iso); return isNaN(d) ? iso : d.toLocaleDateString(INTL); };
  const num = (n) => (n === null || n === undefined || n === '' ? '—' : Number(n).toLocaleString(INTL));
  function sleep(ms, signal) {
    if (signal?.aborted) return Promise.reject(signal.reason);
    return new Promise((resolve, reject) => {
      const cancel = () => { clearTimeout(timer); reject(signal.reason); };
      const timer = setTimeout(() => { signal?.removeEventListener('abort', cancel); resolve(); }, ms);
      signal?.addEventListener('abort', cancel, { once: true });
    });
  }

  /* ------------------------------------------------------------ cookies, theme, language */
  const cookie = {
    get: (k) => { const m = document.cookie.match(new RegExp('(?:^|; )' + reEsc(k) + '=([^;]*)')); return m ? decodeURIComponent(m[1]) : ''; },
    set: (k, v, days = 365) => { document.cookie = `${k}=${encodeURIComponent(v)}; Max-Age=${days * 86400}; Path=/; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`; },
  };
  // Light is the default; dark only when the visitor chose it (cookie j365_theme=dark).
  let memTheme = '';
  const theme = {
    pref: () => { const v = cookie.get('j365_theme') || memTheme; return v === 'dark' ? 'dark' : 'light'; },
    resolved() { return this.pref(); },
    apply() { document.documentElement.dataset.theme = this.resolved(); document.documentElement.dataset.themePref = this.pref(); const m = document.querySelector('meta[name="theme-color"]'); if (m) m.setAttribute('content', this.pref() === 'dark' ? '#030d1b' : '#f4f8fc'); },
    set(v) { memTheme = v; cookie.set('j365_theme', v); this.apply(); document.dispatchEvent(new CustomEvent('j365:theme')); },
    cycle() { this.set(this.pref() === 'dark' ? 'light' : 'dark'); },
  };
  theme.apply();

  /* Language switch. The signed-in portal swaps the dictionary in place (reports already generated stay
     in this tab); elsewhere — or if the dictionary can't be fetched — the page reloads. A plain
     location.replace() to the same URL with a #view would only scroll, never reload. */
  const LANG_META = { 'pt-BR': ['pt-BR', 'pt-BR'], es: ['es', 'es'], en: ['en-US', 'en'] };
  async function setLang(code) {
    if (!LANG_META[code] || code === CFG.lang) return;
    cookie.set('j365_lang', code);
    // A "?lang=" left in the address would win over the cookie on the next reload.
    const u = new URL(location.href);
    if (u.searchParams.has('lang')) { u.searchParams.delete('lang'); try { history.replaceState(null, '', u.toString()); } catch (e) { /* ignore */ } }
    if (typeof window.J365Relang === 'function') {
      try {
        let dict = {};
        if (code !== 'pt-BR') {
          const r = await fetch((CFG.langBase || '') + code + '.json?ver=' + encodeURIComponent(CFG.assetVersion || CFG.version || ''), { credentials: 'same-origin', cache: 'force-cache' });
          if (!r.ok) throw new Error('HTTP ' + r.status);
          dict = await r.json();
          if (!dict || typeof dict !== 'object' || Array.isArray(dict)) throw new Error('invalid dictionary');
        }
        DICT = dict; compile();
        CFG.lang = code; CFG.intl = LANG_META[code][0]; INTL = CFG.intl;
        // A later sign-in (expired session) must keep the language chosen here.
        if (CFG.loginUrl) { try { const lu = new URL(CFG.loginUrl, location.href); if (lu.searchParams.has('lang')) { lu.searchParams.set('lang', code); CFG.loginUrl = lu.toString(); } } catch (e) { /* ignore */ } }
        document.documentElement.lang = LANG_META[code][1];
        window.J365Relang(code);
        return;
      } catch (e) { /* fall through to a full reload */ }
    }
    location.reload();
  }

  const ICON_THEME = {
    light: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/></svg>',
    dark: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',
  };
  /** Two-state toggle: the icon shows the theme you switch to (moon → dark, sun → light). */
  function themeToggle() {
    const label = { light: 'Mudar para o tema escuro', dark: 'Mudar para o tema claro' };
    const b = h('button', { class: 'icon-btn theme-btn', type: 'button' });
    const paint = () => { const p = theme.pref(); b.innerHTML = ICON_THEME[p]; b.setAttribute('aria-label', t(label[p])); b.title = t(label[p]); b.setAttribute('aria-pressed', p === 'dark' ? 'true' : 'false'); };
    paint();
    b.addEventListener('click', () => { theme.cycle(); paint(); });
    const onTheme = () => { if (!b.isConnected) { document.removeEventListener('j365:theme', onTheme); return; } paint(); };
    document.addEventListener('j365:theme', onTheme);
    return b;
  }
  function langSwitch() {
    const langs = CFG.langs || [{ code: 'pt-BR', short: 'PT', label: 'Português' }, { code: 'es', short: 'ES', label: 'Español' }, { code: 'en', short: 'EN', label: 'English' }];
    const wrap = h('div', { class: 'lang-switch', role: 'group', 'aria-label': 'Idioma' });
    const pill = h('i', { class: 'lang-pill', 'aria-hidden': 'true' });
    wrap.append(pill);
    langs.forEach((l, i) => {
      const active = l.code === (CFG.lang || 'pt-BR');
      const b = h('button', { type: 'button', class: active ? 'on' : '', lang: l.code, title: l.label, 'aria-pressed': active ? 'true' : 'false', onclick: () => { if (!active) setLang(l.code); } }, raw(l.short));
      if (active) wrap.style.setProperty('--li', i);
      wrap.append(b);
    });
    return wrap;
  }

  /* ------------------------------------------------------------ brand */
  const MS_LOGO = '<svg viewBox="0 0 21 21" aria-hidden="true"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>';
  const CLOUD_PATH = 'M31 76h63c13.3 0 24-10.4 24-23.4 0-12.2-9.6-22.2-21.8-23.3C93.4 15.8 81.1 6 66.5 6 51.6 6 39.1 16.4 36 30.3c-.8-.1-1.6-.1-2.4-.1C19.5 30.2 8 41 8 53.6 8 66.3 18.5 76 31 76z';
  const cloudSvg = (id = 'jc' + Math.random().toString(36).slice(2, 7)) => `<svg viewBox="0 0 126 84" aria-hidden="true"><defs><linearGradient id="${id}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#50d8f8"/><stop offset=".55" stop-color="#00b0ff"/><stop offset="1" stop-color="#0068b8"/></linearGradient></defs><path d="${CLOUD_PATH}" fill="none" stroke="url(#${id})" stroke-width="6" stroke-linejoin="round"/></svg>`;
  /** Jornada 365 logo with light/dark variants (no plate). */
  function logo(cls = '') {
    const L = CFG.logo || {};
    if (!L.light) return h('span', { class: 'logo logo-fallback ' + cls }, svg(cloudSvg()), h('b', null, raw('JORNADA')), h('em', null, raw('365')));
    return h('span', { class: 'logo ' + cls },
      h('img', { class: 'logo-light', src: L.light, alt: 'Jornada 365', decoding: 'async' }),
      h('img', { class: 'logo-dark', src: L.dark || L.light, alt: '', 'aria-hidden': 'true', decoding: 'async' }));
  }

  /* ------------------------------------------------------------ API */
  /** Observe a shared promise without discarding other callers when one stops waiting. */
  function waitFor(promise, signal) {
    if (!signal) return promise;
    return new Promise((resolve, reject) => {
      const cancel = () => reject(signal.reason);
      if (signal.aborted) cancel();
      else signal.addEventListener('abort', cancel, { once: true });
      promise.then(
        (value) => { signal.removeEventListener('abort', cancel); resolve(value); },
        (error) => { signal.removeEventListener('abort', cancel); reject(error); }
      );
    });
  }

  function withDeadline(ms, signal, work, error) {
    if (!ms) return work(signal);
    const controller = new AbortController();
    const cancel = () => controller.abort(signal.reason);
    if (signal?.aborted) cancel();
    else signal?.addEventListener('abort', cancel, { once: true });
    const timer = setTimeout(() => controller.abort(error), ms);
    const promise = Promise.resolve().then(() => {
      controller.signal.throwIfAborted();
      return work(controller.signal);
    });
    return waitFor(promise, controller.signal).finally(() => {
      clearTimeout(timer);
      signal?.removeEventListener('abort', cancel);
    });
  }

  function api(path, opts = {}) {
    if (!opts.timeout) return requestApi(path, opts, opts.signal);
    const error = Object.assign(new Error(t('O servidor demorou para responder. Tente novamente.')), { status: 408 });
    return withDeadline(opts.timeout, opts.signal, (signal) => requestApi(path, opts, signal), error);
  }

  async function requestApi(path, opts, signal, renewed = false) {
    signal?.throwIfAborted();
    const headers = { 'Content-Type': 'application/json', 'X-J365-Nonce': CFG.nonce || '', 'X-Requested-With': 'XMLHttpRequest', 'X-J365-Lang': CFG.lang || 'pt-BR' };
    // Per-tenant model: the admin's delegated tokens (this tab only) go with every call; never stored by the server.
    const A = window.J365Auth;
    const tokens = !opts.anon && A && A.enabled() && CFG.authenticated;
    if (tokens) {
      try { Object.assign(headers, await A.headers(!!opts.exo)); }
      catch (e) {
        signal?.throwIfAborted();
        if (e.reauth) { A.resume(); throw new Error('reauth'); }
        if (opts.exo) throw e;
      }
    }
    signal?.throwIfAborted();
    const res = await fetch(CFG.restUrl + path, {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      headers,
      signal,
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    let data = null;
    try { data = await res.json(); } catch (e) { /* non-JSON */ }
    signal?.throwIfAborted();
    // Expired or revoked delegated token: renew it once and repeat the call; otherwise renew the sign-in.
    if (res.status === 401 && data && data.code === 'j365_token' && tokens) {
      if (!renewed) {
        try { await A.token('graph', true); return requestApi(path, opts, signal, true); }
        catch (e) { if (!e.reauth) throw e; }
      }
      signal?.throwIfAborted();
      A.resume();
      throw new Error('reauth');
    }
    if (res.status === 401 && !opts.wp) { document.dispatchEvent(new CustomEvent('j365:unauth')); throw new Error('unauthenticated'); }
    if (!res.ok || (data && data.ok === false)) {
      const err = new Error(t((data && data.message) || `Erro HTTP ${res.status}`));
      err.data = data; err.status = res.status;
      const retry = res.headers.get('Retry-After');
      if (retry) {
        const seconds = /^\d+$/.test(retry) ? Number(retry) : Math.ceil((Date.parse(retry) - Date.now()) / 1000);
        if (Number.isFinite(seconds) && seconds > 0) err.retryAfter = seconds;
      }
      throw err;
    }
    return data;
  }
  /** WordPress-authenticated calls (portal operator only). */
  const wpApi = (path, body) => fetch(CFG.restUrl + path, {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': (CFG.setup && CFG.setup.nonce) || '' },
    body: JSON.stringify(body || {}),
  }).then(async (res) => {
    let data = null; try { data = await res.json(); } catch (e) { /* */ }
    if (!res.ok || !data || data.ok === false) { const err = new Error(t((data && data.message) || `Erro HTTP ${res.status}`)); err.data = data; err.status = res.status; throw err; }
    return data;
  });

  async function copyText(text, btn) {
    try { await navigator.clipboard.writeText(text); } catch (e) {
      const ta = h('textarea', { style: 'position:fixed;opacity:0' }); ta.value = text; document.body.append(ta); ta.select(); document.execCommand('copy'); ta.remove();
    }
    if (btn) { const old = btn.textContent; btn.textContent = t('Copiado ✓'); btn.classList.add('copied'); setTimeout(() => { btn.textContent = old; btn.classList.remove('copied'); }, 1800); }
  }

  /* ------------------------------------------------------------ motion */
  let io = null;
  function reveal(scope = document) {
    const els = scope.querySelectorAll('[data-reveal]:not(.in)');
    if (RM || !('IntersectionObserver' in window)) { els.forEach((e) => e.classList.add('in')); return; }
    if (!io) io = new IntersectionObserver((entries) => entries.forEach((en) => { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } }), { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    els.forEach((e) => io.observe(e));
  }
  /** Cursor-following light on cards (CSS vars --mx/--my). */
  function spotlight(scope) {
    scope.addEventListener('pointermove', (e) => {
      const el = e.target.closest('.spot');
      if (!el) return;
      const r = el.getBoundingClientRect();
      el.style.setProperty('--mx', `${e.clientX - r.left}px`);
      el.style.setProperty('--my', `${e.clientY - r.top}px`);
    }, { passive: true });
  }
  function countUp(el, value, ms = 1100) {
    const target = Number(value);
    if (!isFinite(target) || RM) { el.textContent = num(value); return; }
    const t0 = performance.now();
    const ease = (x) => 1 - Math.pow(1 - x, 4);
    const step = (now) => { const p = Math.min(1, (now - t0) / ms); el.textContent = num(Math.round(target * ease(p))); if (p < 1) requestAnimationFrame(step); };
    requestAnimationFrame(step);
  }
  /** Magnetic hover for primary CTAs. */
  function magnet(el, strength = 10) {
    if (RM) return el;
    el.addEventListener('pointermove', (e) => { const r = el.getBoundingClientRect(); const x = (e.clientX - r.left) / r.width - 0.5; const y = (e.clientY - r.top) / r.height - 0.5; el.style.transform = `translate(${x * strength}px, ${y * strength * 0.6}px)`; });
    el.addEventListener('pointerleave', () => { el.style.transform = ''; });
    return el;
  }

  function toast(message, type = 'info', ms = 4200) {
    let host = document.querySelector('.toasts');
    if (!host) { host = h('div', { class: 'toasts', role: 'status', 'aria-live': 'polite' }); document.body.append(host); }
    const el = h('div', { class: 'toast ' + type }, message);
    host.append(el);
    setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 400); }, ms);
  }

  /** Accessible dialog with focus trap and Esc to close. */
  function dialog({ title, body, wide = false, onClose = null, dismissable = true, cls = '' }) {
    const prev = document.activeElement;
    const bg = h('div', { class: 'dlg-bg', role: 'presentation' });
    const close = () => { bg.classList.add('out'); setTimeout(() => bg.remove(), 220); document.removeEventListener('keydown', onKey); if (prev && prev.focus) prev.focus(); if (onClose) onClose(); };
    const head = h('div', { class: 'dlg-head' }, h('h2', { id: 'dlg-t' }, title), dismissable ? h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Fechar', onclick: close, html: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>' }) : null);
    const content = body && body.classList && body.classList.contains('dlg-body') ? body : h('div', { class: 'dlg-body' }, body);
    const box = h('div', { class: 'dlg ' + (wide ? 'wide ' : '') + cls, role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'dlg-t' }, head, content);
    bg.append(box);
    if (dismissable) bg.addEventListener('mousedown', (e) => { if (e.target === bg) close(); });
    const onKey = (e) => {
      if (e.key === 'Escape' && dismissable) close();
      if (e.key === 'Tab') {
        const f = [...box.querySelectorAll('button,a[href],input,select,textarea,[tabindex]:not([tabindex="-1"])')].filter((x) => !x.disabled && x.offsetParent !== null);
        if (!f.length) return;
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); } else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
      }
    };
    document.addEventListener('keydown', onKey);
    document.body.append(bg);
    requestAnimationFrame(() => { bg.classList.add('in'); const first = box.querySelector('input,button.primary,a.primary,button'); if (first) first.focus({ preventScroll: true }); });
    return { el: box, close, body };
  }

  /** Quote a semicolon-delimited CSV field and protect formula-like text on import. */
  function quoteCsvField(value) {
    const isFormulaLike = typeof value === 'string' && /^(?:[\t\r\n]|[\s\u200b]*[=+\-@＝＋－＠])/.test(value);
    const text = (isFormulaLike ? "'" : '') + String(value);
    return '"' + text.replace(/"/g, '""') + '"';
  }

  window.J365 = { quoteCsvField, CFG, RM, t, h, raw, svg, esc, fmtDate, fmtDay, num, sleep, cookie, theme, setLang, themeToggle, langSwitch, logo, cloudSvg, MS_LOGO, api, withDeadline, waitFor, wpApi, copyText, reveal, spotlight, countUp, magnet, toast, dialog };
})();
