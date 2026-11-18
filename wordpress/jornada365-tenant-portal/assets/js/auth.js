/* Tenant Jornada365 — per-tenant sign-in in the browser.
 *
 * Each customer tenant has its own "Tenant Jornada365" app (single-page application, no secret).
 * Sign-in: authorization code + PKCE against the customer's tenant; the code is redeemed here
 * (CORS) and the tokens stay in this tab (sessionStorage) — the server only receives the access
 * token with each API call and never stores it. Refresh tokens of SPAs live 24 hours (Microsoft).
 * No library, no CDN.
 */
(function () {
  'use strict';
  const J = window.J365;
  if (!J) return;
  const { CFG, t } = J;
  const SPA = CFG.spa || null;
  const KEY = 'j365.auth.v1';      // tokens of this tab
  const PEND = 'j365.auth.pending'; // one sign-in in flight
  const MEM = 'j365.tenants.v1';   // tenant → client ID and last e-mail (this browser, convenience only)
  const SKEW = 5 * 60 * 1000;      // renew access tokens 5 minutes before they expire

  /* ------------------------------------------------------------ storage (never throws) */
  const store = (area) => ({
    get(k) { try { return JSON.parse(window[area].getItem(k) || 'null'); } catch (e) { return null; } },
    set(k, v) { try { window[area].setItem(k, JSON.stringify(v)); } catch (e) { /* private mode */ } },
    del(k) { try { window[area].removeItem(k); } catch (e) { /* */ } },
  });
  const ss = store('sessionStorage');
  const ls = store('localStorage');

  /* ------------------------------------------------------------ crypto helpers */
  const b64url = (bytes) => { let s = ''; for (const b of bytes) s += String.fromCharCode(b); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); };
  const rand = (n = 32) => b64url(crypto.getRandomValues(new Uint8Array(n)));
  async function pkce(verifier) {
    const d = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
    return b64url(new Uint8Array(d));
  }
  function claims(jwt) {
    try {
      let p = String(jwt).split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
      while (p.length % 4) p += '=';
      const bin = atob(p);
      const bytes = Uint8Array.from(bin, (c) => c.charCodeAt(0));
      return JSON.parse(new TextDecoder().decode(bytes));
    } catch (e) { return {}; }
  }
  const scp = (at) => String(claims(at).scp || '').split(' ').filter(Boolean);

  /* ------------------------------------------------------------ state */
  const state = () => ss.get(KEY);
  const save = (st) => ss.set(KEY, st);
  const enabled = () => !!(SPA && CFG.tenancy === 'per_tenant');
  /** True when this tab holds tokens for the signed-in tenant. */
  const active = () => { const st = state(); return enabled() && !!(st && st.rt && (!CFG.account || st.tid === CFG.account.tenantId)); };

  function remember(tid, clientId, email) {
    const m = ls.get(MEM) || { tenants: {} };
    m.tenants = m.tenants || {};
    m.tenants[tid] = { clientId, at: Date.now() };
    if (email) m.email = email;
    ls.set(MEM, m);
  }
  const memory = () => ls.get(MEM) || { tenants: {} };

  const msLocale = () => ({ 'pt-BR': 'pt-BR', pt: 'pt-BR', en: 'en-US', es: 'es-ES' }[CFG.lang] || CFG.lang || 'pt-BR');

  function scopesFor(purpose) {
    if (purpose === 'exo') return ['openid', 'profile', 'offline_access', ...(SPA.exoScopes || [])];
    return SPA.scopes;
  }

  /** Public auth endpoints of the portal: JSON in, JSON out (problems come back as { ok:false, url }). */
  async function post(path, body, extra) {
    const res = await fetch(CFG.restUrl + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: Object.assign({ 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-J365-Lang': CFG.lang || 'pt-BR' }, extra || {}),
      body: JSON.stringify(body || {}),
    });
    let data = null;
    try { data = await res.json(); } catch (e) { /* */ }
    if (!data) throw new Error(t('Não foi possível falar com o portal. Tente novamente.'));
    return data;
  }

  /* ------------------------------------------------------------ sign-in (redirect) */
  /**
   * Full-page redirect to Microsoft with the tenant's own app. purpose: login | resume | exo.
   * prompt: '' | 'none' (silent) | 'select_account' | 'consent'.
   */
  async function signIn({ tenantId, clientId, loginHint = '', prompt = '', purpose = 'login', returnTo = '' }) {
    if (!enabled()) throw new Error('per-tenant sign-in disabled');
    const begin = await post('auth/begin');
    if (!begin.ok || !begin.nonce) throw new Error(t(begin.message || 'Não foi possível iniciar a entrada. Tente novamente.'));
    const verifier = rand(48);
    const st = rand(24);
    ss.set(PEND, { st, verifier, tid: tenantId, clientId, nonce: begin.nonce, purpose, returnTo: returnTo || location.href, loginHint, prompt, at: Date.now() });
    const q = new URLSearchParams({
      client_id: clientId,
      response_type: 'code',
      redirect_uri: SPA.redirectUri,
      response_mode: 'fragment',
      scope: scopesFor(purpose).join(' '),
      state: st,
      nonce: begin.nonce,
      code_challenge: await pkce(verifier),
      code_challenge_method: 'S256',
      ui_locales: msLocale(),
    });
    if (loginHint) q.set('login_hint', loginHint);
    if (prompt) q.set('prompt', prompt);
    location.assign(`${SPA.authority}/${encodeURIComponent(tenantId)}/oauth2/v2.0/authorize?${q}`);
    return new Promise(() => {}); // the page is leaving
  }

  async function tokenRequest(tid, params) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 25000);
    let res;
    try {
      res = await fetch(`${SPA.authority}/${encodeURIComponent(tid)}/oauth2/v2.0/token`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(params),
        credentials: 'omit',
        cache: 'no-store',
        signal: controller.signal,
      });
      return await res.json();
    } catch (e) {
      if (!res || controller.signal.aborted) return { error: 'network', error_description: e.message };
      return { error: 'invalid_response' };
    } finally {
      clearTimeout(timer);
    }
  }

  function keep(st, res, tok) {
    st.tokens = st.tokens || {};
    st.tokens[res] = { at: tok.access_token, exp: Date.now() + 1000 * (+tok.expires_in || 3600), scp: scp(tok.access_token) };
    if (tok.refresh_token) st.rt = tok.refresh_token;
  }

  /* ------------------------------------------------------------ redirect page */
  async function completeRedirect() {
    const msg = document.getElementById('j365-spa-msg');
    const say = (s) => { if (msg) msg.textContent = t(s); };
    const raw = location.hash.length > 1 ? location.hash.slice(1) : location.search.slice(1);
    const p = new URLSearchParams(raw);
    try { history.replaceState(null, '', location.pathname); } catch (e) { /* */ } // the code leaves the address bar
    const pend = ss.get(PEND);
    ss.del(PEND);
    const fail = async (error, description) => {
      try {
        const r = await post('auth/fail', { error, error_description: description || '', tenant: (pend && pend.tid) || '' });
        location.replace(r.url || CFG.loginUrl);
      } catch (e) { location.replace(CFG.loginUrl); }
    };

    if (p.get('error')) {
      const err = p.get('error');
      // A silent renewal that needs the user (MFA, account picker, new consent): go interactive.
      if (pend && pend.purpose === 'resume' && pend.prompt === 'none' && /^(login_required|interaction_required|consent_required|account_selection_required)$/.test(err)) {
        say('Confirme a sua conta na Microsoft…');
        await signIn({ tenantId: pend.tid, clientId: pend.clientId, loginHint: pend.loginHint, purpose: 'resume', returnTo: pend.returnTo });
        return;
      }
      await fail(err, p.get('error_description'));
      return;
    }
    if (!pend || !p.get('code') || p.get('state') !== pend.st || Date.now() - pend.at > 15 * 60 * 1000) {
      await fail('state_expired', '');
      return;
    }

    say('Validando a entrada…');
    const tok = await tokenRequest(pend.tid, {
      client_id: pend.clientId,
      grant_type: 'authorization_code',
      code: p.get('code'),
      redirect_uri: SPA.redirectUri,
      code_verifier: pend.verifier,
      scope: scopesFor(pend.purpose).join(' '),
    });
    if (!tok.access_token || !tok.id_token) { await fail(tok.error || 'invalid_token', tok.error_description); return; }

    const idc = claims(tok.id_token);
    const st = { tid: String(idc.tid || pend.tid).toLowerCase(), clientId: pend.clientId, rt: '', tokens: {}, account: { upn: idc.preferred_username || '', name: idc.name || '', oid: idc.oid || '' }, rtExp: Date.now() + 23.5 * 3600 * 1000 };
    keep(st, pend.purpose === 'exo' ? 'exo' : 'graph', tok);
    if (!st.tokens.graph) {
      const g = await tokenRequest(st.tid, { client_id: st.clientId, grant_type: 'refresh_token', refresh_token: st.rt, scope: SPA.scopes.join(' ') });
      if (!g.access_token) { await fail(g.error || 'invalid_token', g.error_description); return; }
      keep(st, 'graph', g);
    }
    save(st);
    remember(st.tid, st.clientId, st.account.upn);

    say('Abrindo o portal…');
    let r = null;
    try { r = await post('auth/session', { id_token: tok.id_token, client_id: st.clientId }, { 'X-J365-Graph': st.tokens.graph.at }); } catch (e) { r = null; }
    if (!r) { await fail('temporarily_unavailable', ''); return; }
    if (!r.ok) { ss.del(KEY); location.replace(r.url || CFG.loginUrl); return; }
    location.replace(pend.purpose === 'login' ? (r.redirect || CFG.loginUrl) : (pend.returnTo || r.redirect));
  }

  /* ------------------------------------------------------------ tokens for API calls */
  const inflight = {};
  function reauthError(code, message) {
    const e = new Error(message || t('Sua autorização da Microsoft expirou. Entre novamente.'));
    e.reauth = true; e.code = code || 'reauth';
    return e;
  }

  async function refresh(res) {
    const st = state();
    if (!st || !st.rt) throw reauthError('no_token');
    if (st.rtExp && Date.now() > st.rtExp) throw reauthError('rt_expired');
    const scope = scopesFor(res === 'exo' ? 'exo' : 'login');
    const tok = await tokenRequest(st.tid, { client_id: st.clientId, grant_type: 'refresh_token', refresh_token: st.rt, scope: scope.join(' ') });
    if (!tok.access_token) {
      const desc = String(tok.error_description || '');
      const consent = /AADSTS65001|AADSTS90094|AADSTS65004/.test(desc) || tok.error === 'consent_required';
      const e = consent ? Object.assign(new Error(desc), { code: 'consent_needed' }) : reauthError(tok.error);
      e.ms = (desc.match(/AADSTS\d+/) || [''])[0];
      throw e;
    }
    keep(st, res, tok);
    save(st);
    return st.tokens[res].at;
  }

  /** A valid access token for 'graph' or 'exo' (renewed with the refresh token when needed). */
  async function token(res = 'graph', force = false) {
    const st = state();
    if (!st) throw reauthError('no_token');
    const cur = st.tokens && st.tokens[res];
    if (!force && cur && cur.exp - Date.now() > SKEW) return cur.at;
    if (!inflight[res]) inflight[res] = refresh(res).finally(() => { delete inflight[res]; });
    return inflight[res];
  }

  /** Headers for the portal API: Graph always, Exchange Online when this tab already holds it. */
  async function headers(needExo = false) {
    const out = { 'X-J365-Graph': await token('graph') };
    const st = state();
    if (needExo || (st && st.tokens && st.tokens.exo)) {
      try { out['X-J365-Exo'] = await token('exo'); } catch (e) { if (needExo) throw e; }
    }
    return out;
  }

  /** Silent renewal of this tab's tokens (new tab, 24-hour limit, revoked session). */
  function resume(returnTo) {
    const a = CFG.account || {};
    const m = memory();
    const tid = a.tenantId || '';
    const clientId = a.clientId || (m.tenants[tid] || {}).clientId || '';
    if (!tid || !clientId) { location.assign(CFG.loginUrl); return new Promise(() => {}); }
    return signIn({ tenantId: tid, clientId, loginHint: a.upn || '', prompt: 'none', purpose: 'resume', returnTo: returnTo || location.href });
  }

  /** The Graph scope of an action capability is in the tab's token; Exchange needs its own token. */
  const CAP_SCOPE = { intune: 'DeviceManagementManagedDevices.PrivilegedOperations.All', reports: 'ReportSettings.ReadWrite.All', exchange: 'Exchange.Manage' };
  async function ensureCap(cap) {
    const res = cap === 'exchange' ? 'exo' : 'graph';
    let at;
    try { at = await token(res, res === 'exo' && !(state() && state().tokens && state().tokens.exo)); }
    catch (e) {
      if (e.code === 'consent_needed') throw { code: 'consent_needed', cap, ms: e.ms, message: t('O aplicativo do seu tenant ainda não tem esta permissão. Um administrador precisa atualizar o aplicativo (Conectar o tenant).'), approve: connectUrl() };
      if (e.reauth) { resume(); }
      throw { code: 'generic', cap, message: e.message };
    }
    if (!scp(at).includes(CAP_SCOPE[cap])) {
      throw { code: 'consent_needed', cap, message: t('O aplicativo do seu tenant ainda não tem esta permissão. Um administrador precisa atualizar o aplicativo (Conectar o tenant).'), approve: connectUrl() };
    }
    return { ok: true, cap };
  }

  function connectUrl() {
    const base = (CFG.loginUrl || '').split('?')[0] || location.origin + '/';
    const tid = (CFG.account && CFG.account.tenantId) || (state() && state().tid) || '';
    return base + '?connect=1' + (tid ? '&t=' + encodeURIComponent(tid) : '');
  }

  function clear() { ss.del(KEY); ss.del(PEND); }

  window.J365Auth = { enabled, active, signIn, completeRedirect, token, headers, resume, ensureCap, clear, memory, remember, claims, connectUrl, post };

  if (document.body && document.body.dataset.j365Page === 'spa') completeRedirect();
  else document.addEventListener('DOMContentLoaded', () => { if (document.body && document.body.dataset.j365Page === 'spa') completeRedirect(); });
})();
