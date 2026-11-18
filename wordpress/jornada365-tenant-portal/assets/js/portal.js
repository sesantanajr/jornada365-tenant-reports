/* Tenant Jornada365 — authenticated dashboard (vanilla JS, no build step). Requires core.js + landing.js. */
(function () {
  'use strict';
  const J = window.J365;
  const root = document.getElementById('j365tp-app');
  if (!J || !root) return;
  const { CFG, t, h, raw, svg, fmtDate, fmtDay, num, sleep, api, logo, themeToggle, langSwitch, countUp, toast, reveal, quoteCsvField, withDeadline, waitFor } = J;
  const APP = CFG.appName || 'Tenant Jornada365';

  const yesNo = (v) => (v === null || v === undefined ? '—' : v ? t('Sim') : t('Não'));
  const badge = (text, cls) => h('span', { class: 'badge ' + (cls || '') }, text);
  const meter = (pct, cls = '') => h('span', { class: 'meter ' + cls, title: pct + '%' }, h('i', { style: `width:${Math.max(0, Math.min(100, +pct || 0))}%` }), h('b', null, raw(num(pct) + '%')));
  /* "Inativo 120d · Convidado" → translated parts. */
  const reason = (txt) => String(txt || '').split(' · ').map((x) => { const m = x.match(/^Inativo (\d+)d$/); return m ? t('Inativo {n}d', { n: m[1] }) : t(x); }).join(' · ');
  const bool = (v, okTrue = true) => v === null || v === undefined ? badge('—') : v ? badge('Sim', okTrue ? 'ok' : 'danger') : badge('Não', okTrue ? 'danger' : 'ok');
  const list = (arr, max = 3) => { arr = arr || []; if (!arr.length) return '—'; const s = arr.slice(0, max).map((x) => t(String(x))).join(', '); return arr.length > max ? s + ` (+${arr.length - max})` : s; };
  const alert = (msg) => toast(t(String(msg)), 'danger', 6000);
  /** 1536 → "1,5 KB" (binary units, like the Microsoft admin centers). */
  const bytes = (n) => {
    n = +n || 0;
    if (n <= 0) return '0 B';
    const u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    const i = Math.min(u.length - 1, Math.floor(Math.log(n) / Math.log(1024)));
    return new Intl.NumberFormat(CFG.intl || 'pt-BR', { maximumFractionDigits: i >= 3 ? 1 : 0 }).format(n / 1024 ** i) + ' ' + u[i];
  };

  /* Friendly names for authentication methods: registration report (userRegistrationDetails) and
     Authentication Methods Policy ids. Unknown ids are shown as they come. */
  const METHOD = {
    email: 'E-mail (código)', mobilePhone: 'Celular (SMS/voz)', officePhone: 'Telefone comercial', alternateMobilePhone: 'Celular alternativo', securityQuestion: 'Perguntas de segurança',
    appNotification: 'Notificação do Authenticator', appCode: 'Código do Authenticator', appPassword: 'Senha de aplicativo',
    microsoftAuthenticatorPush: 'Microsoft Authenticator (notificação)', microsoftAuthenticatorPasswordless: 'Microsoft Authenticator (sem senha)',
    softwareOneTimePasscode: 'Código OATH de software', softwareOneTimePassCode: 'Código OATH de software', hardwareOneTimePasscode: 'Token OATH de hardware',
    fido2: 'Chave de segurança FIDO2', passKeyDeviceBound: 'Chave de acesso (passkey)', passKeyDeviceBoundAuthenticator: 'Passkey no Microsoft Authenticator', passKeyDeviceBoundWindowsHello: 'Passkey no Windows Hello', passKeySynced: 'Passkey sincronizada',
    windowsHelloForBusiness: 'Windows Hello para Empresas', macOsSecureEnclaveKey: 'Platform SSO (macOS)', temporaryAccessPass: 'Temporary Access Pass',
    sms: 'SMS', voiceMobile: 'Chamada para o celular', voiceAlternateMobile: 'Chamada para celular alternativo', voiceOffice: 'Chamada para telefone comercial',
    push: 'Notificação do Authenticator', oath: 'Código OATH', none: 'Nenhum', externalAuthenticationMethod: 'Método externo (EAM)',
    x509Certificate: 'Certificado (CBA)', x509CertificateSingleFactor: 'Certificado (CBA, fator único)', x509CertificateMultiFactor: 'Certificado (CBA, multifator)',
    Fido2: 'Chaves de acesso (FIDO2)', MicrosoftAuthenticator: 'Microsoft Authenticator', Sms: 'SMS', TemporaryAccessPass: 'Temporary Access Pass', SoftwareOath: 'Tokens OATH de software de terceiros',
    HardwareOath: 'Tokens OATH de hardware', Voice: 'Chamada de voz', Email: 'Código por e-mail (OTP)', X509Certificate: 'Autenticação baseada em certificado', QRCodePin: 'Código QR + PIN',
  };
  const methodName = (id) => (id && Object.prototype.hasOwnProperty.call(METHOD, id) ? METHOD[id] : id);

  /* Job runner: renew the same job after network interruptions. Its result is consumed once. */
  const REPORT_WAIT_MS = 15 * 60 * 1000;
  const REPORT_REQUEST_MS = 45 * 1000;
  const transient = (e) => e && !['unauthenticated', 'reauth'].includes(e.message) && (!e.status || [408, 425, 429, 500, 502, 503, 504, 520, 521, 522, 523, 524].includes(e.status));

  async function retryJobRequest(send, onRetry, signal, retries) {
    for (let attempt = 0; ; attempt++) {
      try { return await send(); } catch (e) {
        if (signal?.aborted || !transient(e) || attempt >= retries) throw e;
        const delay = Math.max(Math.min(15000, 1500 * 2 ** attempt), (e.retryAfter || 0) * 1000);
        if (delay > REPORT_WAIT_MS) throw e;
        onRetry(Math.ceil(delay / 1000));
        await sleep(delay, signal);
      }
    }
  }

  function runReport(type, params, onProgress, signal) {
    return withDeadline(REPORT_WAIT_MS, signal, async (deadline) => {
      const opts = { signal: deadline, timeout: REPORT_REQUEST_MS };
      const requestId = window.crypto?.randomUUID?.();
      const body = requestId ? { params, request_id: requestId } : { params };
      const start = await retryJobRequest(
        () => api('reports/' + type, { ...opts, method: 'POST', body }),
        (seconds) => onProgress({ message: t('Nova tentativa em {n} s…', { n: seconds }) }),
        deadline, requestId ? 3 : 0
      );
      return followReadJob(start?.job, onProgress, opts);
    }, new Error(t('O relatório excedeu o tempo de espera. Gere novamente para tentar.')));
  }

  async function followJob(job, onProgress = () => {}, opts = {}) {
    let idle = 0;
    const id = job?.job_id;
    for (;;) {
      if (!id || job?.job_id !== id || !['pending', 'running', 'done', 'error'].includes(job.status)) {
        throw new Error(t('O servidor devolveu um estado inválido para o relatório.'));
      }
      onProgress(job);
      if (job.status === 'error') throw new Error(t(job.message || 'Falha ao gerar relatório.'));
      if (job.status === 'done') break;
      if (job.retryAfter > 0) {
        idle = 0;
        await sleep(Math.min(REPORT_WAIT_MS, job.retryAfter * 1000), opts.signal);
      } else if (idle) {
        await sleep(Math.min(5000, 1000 * idle), opts.signal);
      }
      const next = await retryJobRequest(
        () => api('jobs/' + id + '/run', { ...opts, method: 'POST' }),
        (seconds) => onProgress({ ...job, message: t('Nova tentativa em {n} s…', { n: seconds }) }),
        opts.signal, 6
      );
      idle = next?.status === job.status && next.progress === job.progress ? idle + 1 : 0;
      job = next;
    }
    const result = await api('jobs/' + id + '/result', opts);
    if (!result || typeof result !== 'object') throw new Error(t('O servidor devolveu um resultado inválido para o relatório.'));
    return result;
  }

  // A report view and the activity page can wait for the same one-time result in this tab.
  // Actions use followJob directly and never enter this registry.
  const readJobs = new Map();
  function followReadJob(job, onProgress, opts) {
    const id = job?.job_id;
    if (!id || job.type?.startsWith('act_')) throw new Error(t('Relatório indisponível nesta sessão.'));
    let task = readJobs.get(id);
    if (!task) {
      task = { controller: new AbortController(), listeners: new Set(), last: job };
      readJobs.set(id, task);
      task.promise = followJob(job, (update) => {
        task.last = update;
        for (const listener of task.listeners) listener(update);
      }, { ...opts, reportOnly: true, signal: task.controller.signal }).finally(() => {
        if (readJobs.get(id) === task) readJobs.delete(id);
      });
    }
    task.listeners.add(onProgress);
    onProgress(task.last);
    return waitFor(task.promise, opts.signal).finally(() => {
      task.listeners.delete(onProgress);
      if (!task.listeners.size && opts.signal.aborted && readJobs.get(id) === task) {
        readJobs.delete(id);
        task.controller.abort(opts.signal.reason);
      }
    });
  }

  /* ----------------------------------------------------------- icons */
  const ICONS = {
    home: '<path d="M3.5 10.5 12 3.5l8.5 7V20a1 1 0 0 1-1 1h-5v-6h-5v6h-5a1 1 0 0 1-1-1z"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 20c1.6-3.8 4.6-5.8 8-5.8s6.4 2 8 5.8"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    key: '<circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 8.7-8.7M16.5 6.5l2.5 2.5M14 9l2 2"/>',
    guest: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c1.3-3.3 3.8-5 6.5-5s5.2 1.7 6.5 5"/><path d="M17 8h5M19.5 5.5v5"/>',
    crown: '<path d="M4 18h16l1-10-5 4-4-7-4 7-5-4z"/>',
    pulse: '<path d="M3 12h4l2.5-6 4.5 12 2.5-6H21"/>',
    device: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
    box: '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
    shield: '<path d="M12 3 4.5 6v6c0 4.4 3.2 8.2 7.5 9 4.3-.8 7.5-4.6 7.5-9V6z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
    lock: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
    plug: '<path d="M9 3v5M15 3v5M7 8h10v3a5 5 0 0 1-10 0z"/><path d="M12 16v5"/>',
    gauge: '<path d="M4 18a8 8 0 1 1 16 0"/><path d="m12 18 4-6"/>',
    card: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
    chart: '<path d="M4 20V11M10 20V5M16 20v-7M21 20H3"/>',
    users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c1.3-3.3 3.8-5 6.5-5s5.2 1.7 6.5 5"/><circle cx="17" cy="9" r="2.8"/><path d="M16 14.6c2.5.2 4.4 1.9 5.5 4.9"/>',
    globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.7 5.6 3.7 9s-1.2 6.4-3.7 9c-2.5-2.6-3.7-5.6-3.7-9S9.5 5.6 12 3z"/>',
    sliders: '<path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',
    list: '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
    menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
    mail: '<rect x="3.5" y="5.5" width="17" height="13" rx="2.5"/><path d="m4.5 7 7.5 6 7.5-6"/>',
    cloud: '<path d="M7 18h10a4 4 0 0 0 .7-7.9A6 6 0 0 0 6.2 9.2 4.5 4.5 0 0 0 7 18z"/>',
    sync: '<path d="M20 11a8 8 0 0 0-14.3-4.9L4 8"/><path d="M4 4v4h4"/><path d="M4 13a8 8 0 0 0 14.3 4.9L20 16"/><path d="M20 20v-4h-4"/>',
    bolt: '<path d="M13 2.5 4.5 13.5H11l-1 8 8.5-11H12z"/>',
    lock2: '<rect x="6" y="11" width="12" height="9" rx="2"/><path d="M9 11V8.5a3 3 0 0 1 6 0V11"/>',
    archive: '<rect x="3.5" y="4.5" width="17" height="4" rx="1.2"/><path d="M5 8.5V18a1.5 1.5 0 0 0 1.5 1.5h11A1.5 1.5 0 0 0 19 18V8.5"/><path d="M10 12.5h4"/>',
    script: '<path d="M8 4.5h10.5V17a2.5 2.5 0 0 1-2.5 2.5H6.5"/><path d="M8 4.5A2.5 2.5 0 0 0 5.5 7v10.5a2 2 0 0 0 4 0V16h9"/><path d="M11 9h5M11 12h4"/>',
    out: '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M10 16 6 12l4-4M6 12h11"/>',
    refresh: '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/>',
    download: '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    phone: '<rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18.5h2"/>',
    laptop: '<rect x="4.5" y="5" width="15" height="10.5" rx="1.5"/><path d="M2.5 19h19"/>',
    server: '<rect x="4" y="3.5" width="16" height="7" rx="1.5"/><rect x="4" y="13.5" width="16" height="7" rx="1.5"/><path d="M8 7h.01M8 17h.01"/>',
    sheet: '<rect x="4" y="3.5" width="16" height="17" rx="2.2"/><path d="M4 9h16M4 14.5h16M10 9v11.5"/>',
    eye: '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/>',
    eyeOff: '<path d="M3 3l18 18"/><path d="M10.6 5.6A9.7 9.7 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a17 17 0 0 1-3.2 3.9M6.4 7.1A16.6 16.6 0 0 0 2.5 12S6 18.5 12 18.5a9.4 9.4 0 0 0 4.2-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
    restart: '<path d="M4.5 12a7.5 7.5 0 1 0 2.2-5.3"/><path d="M4.5 4.5v4h4"/><path d="M12 8v4l2.5 1.5"/>',
    scan: '<path d="M4 8V5.5A1.5 1.5 0 0 1 5.5 4H8M16 4h2.5A1.5 1.5 0 0 1 20 5.5V8M20 16v2.5a1.5 1.5 0 0 1-1.5 1.5H16M8 20H5.5A1.5 1.5 0 0 1 4 18.5V16"/><path d="M12 7.5l3.5 1.4v2.6c0 2.3-1.5 3.9-3.5 4.6-2-.7-3.5-2.3-3.5-4.6V8.9z"/>',
    shieldUp: '<path d="M12 3 5 6v5.5c0 4.3 3 7.9 7 9.5 4-1.6 7-5.2 7-9.5V6z"/><path d="M12 15.5v-7M9.2 11.2 12 8.5l2.8 2.7"/>',
    trash: '<path d="M4.5 7h15M9.5 7V4.8h5V7M6.5 7l.8 12.2a1.5 1.5 0 0 0 1.5 1.3h6.4a1.5 1.5 0 0 0 1.5-1.3L17.5 7"/>',
    play: '<path d="M8 5.5v13l10.5-6.5z"/>',
    layers: '<path d="m12 3.5 9 4.8-9 4.8-9-4.8z"/><path d="m3 12.4 9 4.8 9-4.8"/><path d="m3 16.5 9 4.8 9-4.8"/>',
  };
  const icon = (name, cls = 'ico') => svg(`<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name] || ''}</svg>`);

  /* ----------------------------------------------------------- export */
  // Registries filled while views render: the Excel export reads what the screen shows (KPIs, distributions,
  // highlights, tables) instead of re-implementing each report. WeakMaps: nothing outlives the DOM.
  const KPI_DATA = new WeakMap(), BAR_DATA = new WeakMap(), TABLE_DATA = new WeakMap(), PANEL_META = new WeakMap();
  let xlsxEngine = null;
  function loadXlsx() {
    if (window.J365Xlsx) return Promise.resolve(window.J365Xlsx);
    if (!xlsxEngine) {
      xlsxEngine = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = (CFG.assetsUrl || '') + 'js/xlsx.js?ver=' + encodeURIComponent(CFG.assetVersion || CFG.version || '');
        s.onload = () => (window.J365Xlsx ? resolve(window.J365Xlsx) : reject(new Error(t('Não foi possível carregar o gerador de Excel.'))));
        s.onerror = () => reject(new Error(t('Não foi possível carregar o gerador de Excel.')));
        document.head.appendChild(s);
      }).catch((error) => { xlsxEngine = null; throw error; });
    }
    return xlsxEngine;
  }
  const textOf = (v) => (v instanceof Node ? v.textContent.trim() : t(String(v ?? '')));
  // Directory names and identifiers belong to the tenant, even when they match a UI translation.
  const verbatimColumn = (key) => /^(?:id|upn|mail|name|displayName|company|department|jobTitle|domain|owner|ownerUpn|user|primaryUser|publisher|manufacturer|model|serial|url|ip|notes|policy|setting|instance|description|app_name|.*Id)$/.test(key || '');
  /** Value as it goes to a cell: numbers stay numbers, ISO dates become Excel dates, the rest translated text. */
  function cellOf(v, key) {
    if (v === null || v === undefined || v === '' || v === '—') return { v: null, k: '' };
    if (typeof v === 'number') return Number.isFinite(v) ? { v, k: 'n' } : { v: null, k: '' };
    if (typeof v === 'boolean') return { v: t(v ? 'Sim' : 'Não'), k: 's' };
    if (Array.isArray(v)) return { v: v.map((x) => verbatimColumn(key) ? String(x) : t(String(x))).join('; '), k: 's' };
    if (v instanceof Node) return { v: v.textContent.trim() || null, k: 's' };
    const str = String(v);
    if (verbatimColumn(key)) return { v: str, k: 's' };
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(str)) { const d = new Date(str); if (!isNaN(d)) return { v: d, k: 'dt' }; }
    if (/^\d{4}-\d{2}-\d{2}$/.test(str)) { const d = new Date(str + 'T00:00:00'); if (!isNaN(d)) return { v: d, k: 'd' }; }
    return { v: t(str), k: 's' };
  }
  const XL = {
    deep: '06213F', navy: '0A3A6B', blue: '0068B8', accent: '0072C6', sky: '00B0FF', cyan: '50D8F8', ink: '0B2440', text2: '3B5573', muted: '6A7F97', line: 'DDE7F1', soft: 'F2F7FC', soft2: 'F8FBFE',
    tone: { ok: ['0E8A52', 'E3F5EC'], warn: ['A86A00', 'FDF1DC'], danger: ['C93434', 'FBE4E4'], info: ['0068B8', 'E2F0FB'], muted: ['6A7F97', 'EEF3F8'] },
    palette: ['0068B8', '3CC8EE', '2BB3A3', '7C5CDB', 'E58A1F', '5A7FA8', '0E9A5B', 'C2477A', '00A2E8', 'B07D2B', '9FB3C8', 'D13B3B'],
    status: { ok: '12A15E', warn: 'D98A0B', danger: 'D93F3F', info: '0072C6', muted: '9FB3C8' },
  };
  const toneOf = (cls) => (/\bdanger\b/.test(cls || '') ? 'danger' : /\bwarn\b/.test(cls || '') ? 'warn' : /\bok\b/.test(cls || '') ? 'ok' : /\binfo\b/.test(cls || '') ? 'info' : '');
  /** Brand logo (the operator's Jornada 365 wordmark) as bytes for the workbook; null when unavailable. */
  let logoBytes = null;
  /** Excel only embeds PNG/JPEG: other raster formats (WebP/GIF kept as-is on hosts without GD) are redrawn as PNG. */
  async function asPng(bytes) {
    try {
      if (typeof createImageBitmap !== 'function') return null;
      const bmp = await createImageBitmap(new Blob([bytes]));
      const scale = Math.min(1, 1200 / Math.max(1, bmp.width));
      const c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(bmp.width * scale)); c.height = Math.max(1, Math.round(bmp.height * scale));
      c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
      if (bmp.close) bmp.close();
      const blob = await new Promise((res) => c.toBlob(res, 'image/png'));
      return blob ? new Uint8Array(await blob.arrayBuffer()) : null;
    } catch (e) { return null; }
  }
  async function brandLogo() {
    if (logoBytes) return logoBytes;
    for (const u of [CFG.logo && CFG.logo.light, CFG.iconUrl].filter((x) => typeof x === 'string' && x)) {
      try {
        let b = await withDeadline(4000, null, async (signal) => {
          const r = await fetch(u, { credentials: 'same-origin', cache: 'force-cache', signal });
          return r.ok ? new Uint8Array(await r.arrayBuffer()) : null;
        }, new Error('Logo timeout'));
        if (!b) continue;
        if (b.length <= 24 || b.length >= 3e6) continue;
        if (window.J365Xlsx && !window.J365Xlsx.imageInfo(b)) b = await asPng(b);
        if (b) { logoBytes = b; return b; }
      } catch (e) { /* next candidate */ }
    }
    return null;
  }
  /** Sheet names of each area in the executive workbook (≤ 31 characters). */
  const SHEET_NAMES = { overview: 'Visão geral', identity: 'Usuários', inactive: 'Contas inativas', mfa: 'MFA', guests: 'Convidados', privileged: 'Acessos privilegiados', risk: 'Risco', devices: 'Dispositivos', apps: 'Aplicativos', ca: 'Acesso Condicional', authpolicies: 'Autenticação', appsec: 'Apps e consentimentos', securescore: 'Secure Score', identityscore: 'Identity Score', licensing: 'Licenças', usage: 'Uso do M365', mailboxes: 'Caixas de correio', storage: 'OneDrive e SharePoint', groups: 'Grupos', domains: 'Domínios' };
  const SEVERITY = { danger: 0, warn: 1, info: 2, ok: 3 };
  const TONE_LABEL = { danger: 'Crítico', warn: 'Atenção', info: 'Informativo', ok: 'OK' };
  /**
   * Distributions the screen does not draw, read from the table itself: status/boolean/categorical columns
   * (2–30 values; arrays only when the column opts in with xlGroup) → top 8 + "Outros".
   */
  function autoDistributions(rows, columns, skip, max) {
    const out = [];
    if (!rows.length || max <= 0) return out;
    const ID_KEYS = /^(id|upn|mail|displayName|name|url|serial|deviceId|intuneId|skuId|appId|clientId|principalId|roleId|owner|ownerUpn|primaryUser|rule|description|remediation|message)$/;
    const cand = [];
    for (const c of columns) {
      if (!c.label || c.exportOnly || c.xlGroup === false || (c.key && ID_KEYS.test(c.key) && c.xlGroup !== true)) continue;
      const title = t(c.xlLabel || c.label);
      if (skip.has(title.toLowerCase()) || (c.key && skip.has('key:' + c.key))) continue;
      const counts = new Map();
      let arrays = false, bad = false, seen = 0;
      for (const r of rows) {
        let v0;
        try { v0 = c.export ? c.export(r) : (c.value ? c.value(r) : r[c.key]); } catch (e) { bad = true; break; }
        if (Array.isArray(v0)) { arrays = true; if (c.xlGroup !== true) { bad = true; break; } }
        if (typeof v0 === 'number' || (typeof v0 === 'string' && /^\d{4}-\d{2}-\d{2}/.test(v0))) { bad = true; break; }
        const vals = Array.isArray(v0) ? v0 : [v0];
        for (const x of vals) {
          if (x === null || x === undefined || x === '' || x === '—') continue;
          const k = typeof x === 'boolean' ? t(x ? 'Sim' : 'Não') : (x instanceof Node ? x.textContent.trim() : t(String(x)));
          if (!k) continue;
          counts.set(k, (counts.get(k) || 0) + 1);
          seen++;
        }
        if (counts.size > 30) { bad = true; break; }
      }
      if (bad || counts.size < 2 || !seen) continue;
      if (c.xlGroup !== true && !arrays && counts.size > Math.max(8, rows.length * 0.5)) continue;
      const sorted = [...counts.entries()].sort((a, b) => b[1] - a[1]);
      const items = sorted.slice(0, 8).map(([k, n]) => [k, n]);
      const rest = sorted.slice(8).reduce((a, [, n]) => a + n, 0);
      if (rest) items.push([t('Outros'), rest]);
      const statusLike = c.render && !arrays ? 0 : 1;
      cand.push({ title, items, total: rows.length, multi: arrays, score: statusLike * 100 + counts.size, auto: true });
    }
    cand.sort((a, b) => a.score - b.score);
    return cand.slice(0, max);
  }
  const dateFmt = (withTime) => ((CFG.lang || 'pt') === 'en' ? 'yyyy-mm-dd' : 'dd/mm/yyyy') + (withTime ? ' hh:mm' : '');

  /** Columns → typed matrix, formats, widths and the badge colours the screen uses for each value. */
  function tableModel(rows, columns) {
    const cols = columns.filter((c) => c.label);
    const heads = cols.map((c) => t(c.xlLabel || c.label));
    const kinds = cols.map(() => ({ n: 0, dt: 0, d: 0, s: 0, int: true, len: [] }));
    const data = rows.map((r) => cols.map((c, i) => {
      const raw0 = c.export ? c.export(r) : (c.value ? c.value(r) : r[c.key]);
      const cv = cellOf(raw0, c.key);
      const k = kinds[i];
      if (cv.k) k[cv.k]++;
      if (cv.k === 'n' && !Number.isInteger(cv.v)) k.int = false;
      if (k.len.length < 400 && cv.v !== null) k.len.push(cv.k === 's' ? String(cv.v).length : 12);
      // Report payloads express percentages on a 0–100 scale. Excel percentages use 0–1.
      return cv.k === 'n' && heads[i].includes('%') ? cv.v / 100 : cv.v;
    }));
    // Badge colour per exported value, read from the same render function the table uses on screen.
    const tones = cols.map((c, i) => {
      if (!c.render || c.exportOnly) return null;
      const map = new Map();
      for (const r of rows.slice(0, 400)) {
        let el = null;
        try { el = c.render(r); } catch (e) { el = null; }
        const b = el instanceof Element ? (el.classList.contains('badge') ? el : el.querySelector('.badge')) : null;
        if (!b) continue;
        const tone = toneOf(b.className);
        const val = cellOf(c.export ? c.export(r) : (c.value ? c.value(r) : r[c.key]), c.key).v;
        if (tone && typeof val === 'string' && val.length <= 255 && !map.has(val)) map.set(val, tone);
        if (map.size > 12) break;
      }
      return map.size ? map : null;
    });
    const fmts = cols.map((c, i) => {
      const k = kinds[i], label = heads[i];
      if (k.dt && !k.s && !k.n) return { fmt: dateFmt(true), w: 16 };
      if (k.d && !k.s && !k.n) return { fmt: dateFmt(false), w: 12 };
      if (k.n && !k.s) return /%/.test(label) ? { fmt: '0.##%', w: 10, pct: true } : { fmt: k.int ? '#,##0' : '#,##0.00', w: 12 };
      return { fmt: '', w: 0 };
    });
    const widths = cols.map((c, i) => {
      if (fmts[i].w) return Math.max(fmts[i].w, Math.min(40, heads[i].length + 3));
      const l = kinds[i].len.slice().sort((a, b) => a - b);
      const p90 = l.length ? l[Math.floor(l.length * 0.9)] : 8;
      return Math.max(10, Math.min(c.wrap ? 60 : 48, Math.max(heads[i].length + 3, p90 + 2)));
    });
    return { heads, data, fmts, widths, tones, wraps: cols.map((c) => !!c.wrap) };
  }

  /**
   * Builds the workbook. sections: [{ title, short, subtitle, ring, kpis, bars, insights, alerts, tables: [{ rows, columns, name, note, sheet }] }]
   * One report → "Resumo" (logo, main indicator, KPI cards, highlights, distributions with native charts) + "Dados" + "Sobre".
   * Executive (several areas) → "Capa" (indicator per area, attention points, contents) + "Resumo executivo" + one sheet per table + "Sobre".
   */
  async function buildWorkbook(sections, { title, file, scope, exec = false }) {
    const X = await loadXlsx();
    const me = App.me || {}, ten = me.tenant || {}, user = me.user || {};
    const wb = new X.Workbook({ title, subject: APP, creator: APP, company: ten.name || '', lang: CFG.intl || 'pt-BR' });
    const logo = wb.image(await brandLogo());
    const amp = (x) => String(x).replace(/&/g, '&&');
    // Excel rejects a header/footer longer than 255 characters (codes included): long tenant/report names are shortened.
    const footer = (txt) => {
      const right = '&R' + amp(t('Página')) + ' &P / &N';
      const cps = Array.from(String(txt || '')); // code points: never split a surrogate pair
      let cut = false;
      const build = () => '&L' + amp(cps.join('')) + (cut ? '…' : '') + right;
      while (cps.length && build().length > 255) { cps.pop(); cut = true; }
      return build();
    };
    const st = {
      brand: wb.xf({ font: { b: true, sz: 16, color: XL.blue }, v: 'center' }),
      app: wb.xf({ font: { sz: 9, color: XL.muted }, h: 'right', v: 'center' }),
      title: wb.xf({ font: { b: true, sz: 20, color: XL.deep }, v: 'center' }),
      titleD: wb.xf({ font: { b: true, sz: 15, color: XL.deep }, v: 'center' }),
      sub: wb.xf({ font: { sz: 10, color: XL.text2 }, v: 'center' }),
      accent: wb.xf({ fill: { grad: [XL.deep, XL.blue, XL.cyan], deg: 0 } }),
      h2: wb.xf({ font: { b: true, sz: 13, color: XL.deep }, border: { bottom: { style: 'medium', color: XL.cyan } }, v: 'bottom' }),
      h2r: wb.xf({ font: { sz: 9, color: XL.accent, u: true }, border: { bottom: { style: 'medium', color: XL.cyan } }, h: 'right', v: 'bottom' }),
      h3: wb.xf({ font: { b: true, sz: 11, color: XL.ink }, v: 'bottom' }),
      th: wb.xf({ font: { b: true, sz: 10, color: 'FFFFFF' }, fill: XL.navy, v: 'center', indent: 1 }),
      thR: wb.xf({ font: { b: true, sz: 10, color: 'FFFFFF' }, fill: XL.navy, h: 'right', v: 'center' }),
      td: wb.xf({ font: { sz: 10 }, border: { bottom: { color: XL.line } }, v: 'center', indent: 1 }),
      tdB: wb.xf({ font: { b: true, sz: 10, color: XL.ink }, border: { bottom: { color: XL.line } }, v: 'center', indent: 1, wrap: true }),
      tdC: wb.xf({ font: { sz: 10 }, border: { bottom: { color: XL.line } }, v: 'center', indent: 1, wrap: true }),
      tdW: wb.xf({ font: { sz: 10, color: XL.ink }, border: { bottom: { color: XL.line } }, v: 'center', wrap: true }),
      tdN: wb.xf({ font: { sz: 10 }, border: { bottom: { color: XL.line } }, fmt: '#,##0', v: 'center' }),
      tdGB: wb.xf({ font: { sz: 10 }, border: { bottom: { color: XL.line } }, fmt: '#,##0.00 "GB"', v: 'center' }),
      tdP: wb.xf({ font: { sz: 10, color: XL.muted }, border: { bottom: { color: XL.line } }, fmt: '0.0%', v: 'center' }),
      li: wb.xf({ font: { sz: 10.5, color: XL.ink }, border: { bottom: { color: XL.line } }, wrap: true, v: 'center', indent: 1 }),
      note: wb.xf({ font: { i: true, sz: 9, color: XL.muted }, wrap: true, v: 'top' }),
      key: wb.xf({ font: { b: true, sz: 10, color: XL.text2 }, border: { bottom: { color: XL.line } }, v: 'top', indent: 1 }),
      val: wb.xf({ font: { sz: 10 }, border: { bottom: { color: XL.line } }, wrap: true, v: 'top' }),
      link: wb.xf({ font: { sz: 10, color: XL.accent, u: true }, border: { bottom: { color: XL.line } }, v: 'center', indent: 1 }),
    };
    const tag = {}, card = {}, band = {}, tile = {};
    for (const tone of ['', 'ok', 'warn', 'danger', 'info', 'muted']) {
      const [fg, bg] = XL.tone[tone] || [XL.ink, XL.soft];
      const ink = tone ? fg : XL.ink;
      tag[tone] = wb.xf({ font: { b: true, sz: 9, color: fg }, fill: bg, h: 'center', v: 'center', border: { bottom: { color: 'FFFFFF', style: 'medium' } } });
      const sep = { right: { style: 'thick', color: 'FFFFFF' } };
      card[tone] = {
        label: wb.xf({ font: { b: true, sz: 9, color: XL.text2 }, fill: XL.soft, v: 'bottom', indent: 1, wrap: true, border: sep }),
        value: wb.xf({ font: { b: true, sz: 22, color: ink }, fill: XL.soft, h: 'left', v: 'center', indent: 1, border: sep }),
        valueN: wb.xf({ font: { b: true, sz: 22, color: ink }, fill: XL.soft, h: 'left', v: 'center', indent: 1, fmt: '#,##0', border: sep }),
        valueT: wb.xf({ font: { b: true, sz: 14, color: ink }, fill: XL.soft, h: 'left', v: 'center', indent: 1, border: sep }),
        share: wb.xf({ font: { sz: 9, color: XL.muted }, fill: XL.soft, v: 'top', indent: 1, border: sep }),
        line: wb.xf({ fill: XL.soft, border: { bottom: { style: 'thick', color: tone ? fg : XL.sky }, right: { style: 'thick', color: 'FFFFFF' } } }),
      };
      band[tone] = {
        big: wb.xf({ font: { b: true, sz: 26, color: ink }, fill: bg, h: 'center', v: 'center', fmt: '0%' }),
        lbl: wb.xf({ font: { b: true, sz: 12, color: XL.ink }, fill: bg, v: 'bottom', indent: 1, wrap: true }),
        cap: wb.xf({ font: { sz: 10, color: XL.text2 }, fill: bg, v: 'top', indent: 1 }),
        pad: wb.xf({ fill: bg }),
      };
      tile[tone] = {
        area: wb.xf({ font: { b: true, sz: 10.5, color: XL.ink }, fill: XL.soft, v: 'bottom', indent: 1, border: { right: { style: 'thick', color: 'FFFFFF' } } }),
        pct: wb.xf({ font: { b: true, sz: 24, color: ink }, fill: XL.soft, h: 'left', v: 'center', indent: 1, fmt: '0%', border: { right: { style: 'thick', color: 'FFFFFF' } } }),
        num: wb.xf({ font: { b: true, sz: 24, color: ink }, fill: XL.soft, h: 'left', v: 'center', indent: 1, fmt: '#,##0', border: { right: { style: 'thick', color: 'FFFFFF' } } }),
        txt: wb.xf({ font: { b: true, sz: 14, color: ink }, fill: XL.soft, v: 'center', indent: 1, border: { right: { style: 'thick', color: 'FFFFFF' } } }),
        lbl: wb.xf({ font: { sz: 9, color: XL.text2 }, fill: XL.soft, v: 'top', indent: 1, wrap: true, border: { right: { style: 'thick', color: 'FFFFFF' } } }),
        line: wb.xf({ fill: XL.soft, border: { bottom: { style: 'thick', color: tone ? fg : XL.sky }, right: { style: 'thick', color: 'FFFFFF' } } }),
      };
    }
    const dxf = { ok: wb.dxf({ color: XL.tone.ok[0], fill: XL.tone.ok[1] }), warn: wb.dxf({ color: XL.tone.warn[0], fill: XL.tone.warn[1] }), danger: wb.dxf({ color: XL.tone.danger[0], fill: XL.tone.danger[1], b: true }), info: wb.dxf({ color: XL.tone.info[0], fill: XL.tone.info[1] }) };
    const when = new Date();
    const org = [ten.name, ten.domain].filter(Boolean).join(' · ');
    const subtitle = org + ' · ' + t('Gerado em {s}', { s: fmtDate(when.toISOString()) }) + ' · ' + t('por {s}', { s: user.upn || '' });
    const multi = exec || sections.length > 1;
    const COLS = 12; // grid B..M

    /** Brand band: logo, title, subtitle and the gradient rule (rows 0–4). */
    function header(ws, c0, c1, ttl, sub, big = true) {
      ws.height(0, big ? 52 : 44).height(1, big ? 32 : 26).height(2, 18).height(3, 4).height(4, 10);
      if (logo) ws.picture(logo, { col: c0, row: 0, dx: 4, dy: big ? 7 : 6, height: big ? 38 : 32, name: 'Jornada 365', descr: APP });
      else ws.set(0, c0, CFG.brand || APP, st.brand);
      const a0 = c1 - c0 >= 6 ? c1 - 3 : c1;
      ws.set(0, a0, APP + ' · ' + fmtDay(when.toISOString()), st.app).merge(0, a0, 0, c1);
      ws.set(1, c0, ttl, big ? st.title : st.titleD).merge(1, c0, 1, c1);
      ws.set(2, c0, sub, st.sub).merge(2, c0, 2, c1);
      for (let c = c0; c <= c1; c++) ws.set(3, c, null, st.accent);
      return 5;
    }
    function gridSheet(name, opts) {
      const ws = wb.sheet(name, Object.assign({ grid: false, zoom: 90 }, opts));
      ws.width(0, 2);
      for (let c = 1; c <= COLS; c++) ws.width(c, 11.5);
      ws.width(COLS + 1, 2);
      return ws;
    }
    const kpiValue = (k) => {
      const x = k.value;
      if (x === null || x === undefined || x === '' || (typeof x === 'number' && !isFinite(x))) return { numeric: false, v: '—' };
      const numeric = typeof x === 'number' || (typeof x !== 'string' && !isNaN(Number(x)));
      return { numeric, v: numeric ? Number(x) : textOf(x) };
    };
    /** KPI cards, 4 per row (3 columns each): label, value, share of the total, accent line. */
    function cards(ws, r, list) {
      for (let i = 0; i < list.length; i += 4) {
        ws.height(r, 24).height(r + 1, 34).height(r + 2, 16).height(r + 3, 5).height(r + 4, 8);
        list.slice(i, i + 4).forEach((k, j) => {
          const c1 = 1 + j * 3, c2 = c1 + 2, S = card[toneOf(k.cls)] || card[''];
          const { numeric, v } = kpiValue(k);
          const share = numeric && +k.of > 0 ? t(k.ofText || '{p} do total', { p: fmtPct(pctOf(v, +k.of)) }) : null;
          ws.set(r, c1, textOf(k.label), S.label).merge(r, c1, r, c2);
          ws.set(r + 1, c1, v, numeric ? S.valueN : (String(v).length > 12 ? S.valueT : S.value)).merge(r + 1, c1, r + 1, c2);
          ws.set(r + 2, c1, share, S.share).merge(r + 2, c1, r + 2, c2);
          ws.set(r + 3, c1, null, S.line).merge(r + 3, c1, r + 3, c2);
          for (let c = c1 + 1; c <= c2; c++) { ws.set(r, c, null, S.label); ws.set(r + 1, c, null, S.value); ws.set(r + 2, c, null, S.share); ws.set(r + 3, c, null, S.line); }
        });
        r += 5;
      }
      return r;
    }
    /** Main indicator of the area (the hero ring on screen). */
    function ringBand(ws, r, ring) {
      const S = band[ring.tone] || band.info;
      ws.height(r, 26).height(r + 1, 22).height(r + 2, 10);
      ws.set(r, 1, Math.max(0, Math.min(1, ring.pct / 100)), S.big).merge(r, 1, r + 1, 3);
      ws.set(r, 4, ring.label, S.lbl).merge(r, 4, r, COLS);
      ws.set(r + 1, 4, ring.caption || null, S.cap).merge(r + 1, 4, r + 1, COLS);
      for (let c = 1; c <= COLS; c++) { if (c > 3) continue; ws.set(r + 1, c, null, S.big); if (c > 1) ws.set(r, c, null, S.big); }
      for (let c = 5; c <= COLS; c++) { ws.set(r, c, null, S.lbl); ws.set(r + 1, c, null, S.cap); }
      return r + 3;
    }
    const noteObj = (n) => (typeof n === 'string' ? { text: n, tone: 'info' } : n);
    /** Highlights and recommendations, most severe first, with a severity tag. */
    function notesBlock(ws, r, notes, max, heading = 'Destaques e recomendações') {
      const list = notes.map(noteObj).filter((n) => n && n.text).sort((a, b) => (SEVERITY[a.tone] ?? 2) - (SEVERITY[b.tone] ?? 2)).slice(0, max);
      if (!list.length) return r;
      ws.height(r, 22).set(r, 1, t(heading), st.h3).merge(r, 1, r, COLS);
      r++;
      for (const n of list) {
        ws.height(r, Math.max(22, 15 * Math.min(6, Math.ceil(n.text.length / 125)) + 7));
        ws.set(r, 1, t(TONE_LABEL[n.tone] || 'Informativo'), tag[n.tone] || tag.info);
        ws.set(r, 2, n.text, st.li).merge(r, 2, r, COLS);
        for (let c = 3; c <= COLS; c++) ws.set(r, c, null, st.li);
        r++;
      }
      return r + 1;
    }
    const xlColor = (c) => (typeof c === 'string' ? (XL.status[c] || (/^#?[0-9a-f]{6}$/i.test(c) ? c.replace('#', '').toUpperCase() : null)) : null);
    /** Distribution: small table (category, quantity, share) + native chart. */
    function distBlock(ws, r, b) {
      const isBytes = b.opts && b.opts.fmt === bytes;
      const timeSeries = b.opts?.kind === 'scoreHistory';
      const items = (timeSeries ? b.items : b.items.filter(([, v]) => +v > 0)).slice(0, 12);
      if (!items.length) return r;
      const total = Number.isFinite(+b.total) && +b.total > 0 && items.every(([, value]) => +value <= +b.total) ? +b.total : 0;
      const val = (v) => timeSeries ? v : (isBytes ? Math.round(((+v || 0) / 1073741824) * 100) / 100 : +v || 0);
      ws.height(r, 22).set(r, 1, b.title || t('Detalhamento'), st.h3).merge(r, 1, r, 6);
      r++;
      const top = r;
      ws.set(r, 1, t(timeSeries ? 'Data da medição' : 'Categoria'), st.th).merge(r, 1, r, 3); ws.set(r, 2, null, st.th); ws.set(r, 3, null, st.th);
      ws.set(r, 4, t(timeSeries ? 'Pontuação (%)' : (isBytes ? 'Tamanho' : 'Quantidade')), st.thR);
      if (!timeSeries) ws.set(r, 5, '%', st.thR);
      r++;
      const first = r;
      for (const [label, v] of items) {
        const txt = textOf(label);
        if (txt.length > 34) ws.height(r, 15 * Math.min(3, Math.ceil(txt.length / 34)) + 3);
        ws.set(r, 1, txt, txt.length > 34 ? st.tdC : st.td).merge(r, 1, r, 3); ws.set(r, 2, null, st.td); ws.set(r, 3, null, st.td);
        ws.set(r, 4, val(v), isBytes ? st.tdGB : st.tdN);
        ws.set(r, 5, total && !isBytes ? (+v || 0) / total : null, st.tdP);
        r++;
      }
      const last = r - 1;
      ws.dataBar(first, 4, last, 4, '5AB8F5');
      const complete = total > 0 && items.reduce((sum, [, value]) => sum + Number(value), 0) === total;
      const dough = complete && ((b.opts && b.opts.kind === 'donut') || (b.auto && !b.multi && items.length <= 6 && !isBytes));
      const colors = items.map(([, , , tone], i) => xlColor(tone) || XL.palette[i % XL.palette.length]);
      const height = Math.max(last - top + 1, 12);
      ws.chart({ type: timeSeries ? 'col' : (dough ? 'doughnut' : 'bar'), percentScale: timeSeries, title: b.title || '', cats: [first, 1, last, 1], vals: [first, 4, last, 4], labels: items.map(([l]) => textOf(l)), values: items.map(([, v]) => val(v)), colors: dough ? colors : (colors.some((c, i) => c !== XL.palette[i % XL.palette.length]) ? colors : [XL.blue]), from: [7, top - 1], to: [COLS + 1, top - 1 + height] });
      return Math.max(r, top - 1 + height) + 2;
    }

    /* ---- sheets: cover (executive), summary, data, about */
    const cover = exec ? gridSheet(t('Capa'), { selected: true, tab: XL.deep, portrait: true, footer: footer(APP) }) : null;
    const sum = gridSheet(t(multi ? 'Resumo executivo' : 'Resumo'), { selected: !exec, tab: XL.navy, footer: footer(APP + ' — ' + title) });
    let r = header(sum, 1, COLS, multi ? t('Resumo executivo') : title, multi ? org : subtitle);
    sections.forEach((sec) => { (sec.tables || []).forEach((tb, i) => { tb.sheetName = multi ? (tb.sheet || sec.short || sec.title) : (i ? (tb.sheet || tb.title || t('Dados') + ' ' + (i + 1)) : t('Dados')); }); });

    for (const sec of sections) {
      sec.anchor = 'B' + (r + 1);
      if (multi || sec.error) {
        sum.height(r, 28);
        sum.set(r, 1, sec.title, st.h2).merge(r, 1, r, COLS - 2);
        for (let c = 2; c <= COLS - 2; c++) sum.set(r, c, null, st.h2);
        const tb0 = (sec.tables || [])[0];
        if (tb0 && !sec.error) { sum.set(r, COLS - 1, t('Ver dados'), st.h2r).merge(r, COLS - 1, r, COLS); sum.set(r, COLS, null, st.h2r); sec.linkCell = [r, COLS - 1, tb0]; }
        else { sum.set(r, COLS - 1, null, st.h2); sum.set(r, COLS, null, st.h2); }
        r += 2;
      }
      if (sec.error) { sum.set(r, 1, t('Não foi possível gerar esta seção: {s}', { s: sec.error }), st.note).merge(r, 1, r, COLS); r += 2; continue; }
      if (sec.ring) r = ringBand(sum, r, sec.ring);
      r = cards(sum, r, (sec.kpis || []).filter((k) => k && k.label).slice(0, multi ? 8 : 16));
      r = notesBlock(sum, r, [...(sec.insights || []), ...(sec.alerts || [])], multi ? 4 : 12);
      // Distributions: what the screen draws, then (single report) what the table reveals.
      const bars = (sec.bars || []).filter((b) => b.items && b.items.length);
      const tb = (sec.tables || [])[0];
      const skip = new Set([...bars.map((b) => String(b.title || '').toLowerCase()), ...bars.filter((b) => b.opts && b.opts.key).map((b) => 'key:' + b.opts.key)]);
      const dists = multi ? bars.slice(0, 2) : [...bars.slice(0, 6), ...(tb ? autoDistributions(tb.rows, tb.columns, skip, Math.max(0, 6 - bars.length)) : [])];
      for (const b of dists) r = distBlock(sum, r, b);
      if (sec.subtitle) { sum.height(r, 30); sum.set(r, 1, sec.subtitle, st.note).merge(r, 1, r, COLS); r += 2; }
      if (multi) r++;
    }
    sum.height(r, 28).set(r, 1, t('Arquivo gerado por {s} nesta aba. Os dados de uso da Microsoft podem refletir dias anteriores.', { s: APP }), st.note).merge(r, 1, r, COLS);

    // Keep the full set of observations even when the executive cover only shows a short list.
    const findings = [];
    if (exec) {
      for (const section of sections) {
        const seen = new Set();
        function record(kind, value) {
          const note = noteObj(value);
          if (!note?.text || seen.has(kind + '\n' + note.text)) return;
          seen.add(kind + '\n' + note.text);
          findings.push({ section, kind: t(kind), tone: note.tone || 'info', text: note.text });
        }
        for (const note of section.insights || []) record('Observação', note);
        for (const note of section.alerts || []) record('Aviso da consulta', note);
        const indicator = section.ring;
        if (indicator && ['danger', 'warn'].includes(indicator.tone) && Number.isFinite(indicator.pct)) {
          const caption = indicator.caption ? ' · ' + indicator.caption : '';
          record('Indicador da área', { tone: indicator.tone, text: fmtPct(indicator.pct) + ' ' + indicator.label + caption });
        }
        if (section.error) record('Falha na consulta', { tone: 'danger', text: section.error });
      }
      findings.sort((a, b) => (SEVERITY[a.tone] ?? 2) - (SEVERITY[b.tone] ?? 2));
    }
    let register = null;
    if (findings.length) {
      register = wb.sheet(t('Observações e avisos'), { tab: XL.status.warn, footer: footer(title) });
      header(register, 0, 4, t('Observações e avisos'), org, false);
      const heads = ['Área', 'Prioridade', 'Origem', 'Descrição', 'Dados consultados em'].map((s) => t(s));
      const records = findings.map((f) => [f.section.short || f.section.title, t(TONE_LABEL[f.tone] || 'Informativo'), f.kind, f.text, cellOf(f.section.generatedAt).v]);
      register.table(5, 0, heads, records, { name: 'ReportFindings', colStyles: [st.link, st.tdB, st.td, st.tdW, wb.xf({ fmt: dateFmt(true) })] });
      [26, 16, 24, 85, 23].forEach((w, i) => register.width(i, w));
      findings.forEach((f, i) => {
        const row = 6 + i;
        register.height(row, Math.max(27, Math.min(409, 16 * Math.ceil(f.text.length / 75) + 6)));
        register.link(row, 0, sum.name, records[i][0], f.section.anchor);
        register.set(row, 1, records[i][1], tag[f.tone] || tag.info);
      });
      register.height(4, 22).set(4, 0, cover.name, st.link).link(4, 0, cover.name, cover.name);
      register.freeze(6, 1).printTitles(5);
      sum.height(4, 22).set(4, 1, register.name, st.link).merge(4, 1, 4, 5).link(4, 1, register.name, register.name);
    }

    /* ---- data sheets (one per table) */
    for (const sec of sections) {
      if (sec.error) continue;
      for (const tb of sec.tables || []) {
        const m = tableModel(tb.rows, tb.columns);
        const ws = wb.sheet(tb.sheetName, { tab: XL.sky, zoom: 90, footer: footer(APP + ' — ' + sec.title) });
        tb.sheetName = ws.name;
        const lastC = Math.max(5, m.heads.length - 1);
        const info = [org, sec.generatedAt && t('Dados consultados em {s}', { s: fmtDate(sec.generatedAt) }), t('{n} linha(s)', { n: num(tb.rows.length) }), tb.note].filter(Boolean).join(' · ');
        header(ws, 0, lastC, sec.title + (tb.title && (sec.tables || []).length > 1 ? ' — ' + tb.title : ''), info, false);
        ws.height(4, 22).set(4, 0, sum.name, st.link).merge(4, 0, 4, 2).link(4, 0, sum.name, sum.name, sec.anchor);
        if (cover) ws.set(4, 3, cover.name, st.link).merge(4, 3, 4, 5).link(4, 3, cover.name, cover.name);
        const colStyles = m.fmts.map((f, i) => wb.xf({ fmt: f.fmt || undefined, wrap: m.wraps[i] || undefined, v: 'top', font: { sz: 10 } }));
        const box = ws.table(5, 0, m.heads, m.data, { name: tb.name || sec.title, colStyles });
        m.widths.forEach((w, i) => ws.width(i, w));
        ws.freeze(6, m.heads.length > 3 ? 1 : 0).printTitles(5);
        m.fmts.forEach((f, i) => { if (f.pct && tb.rows.length) ws.dataBar(box.r1 + 1, i, box.r2, i, 'F08A8A', 0, 1); });
        m.tones.forEach((map, i) => { if (!map || !tb.rows.length) return; for (const [val, tone] of map) ws.whenEquals(box.r1 + 1, i, box.r2, i, val, dxf[tone]); });
      }
    }
    for (const sec of sections) if (sec.linkCell) sum.link(sec.linkCell[0], sec.linkCell[1], sec.linkCell[2].sheetName, t('Ver dados'));

    /* ---- cover: indicator per area, attention points, contents */
    if (cover) {
      let c = header(cover, 1, COLS, title, subtitle);
      const ok = sections.filter((x) => !x.error);
      cover.height(4, 22).set(4, 1, t('{a} de {b} seções disponíveis', { a: num(ok.length), b: num(sections.length) }), st.key).merge(4, 1, 4, 6);
      if (register) cover.set(4, 7, register.name, st.link).merge(4, 7, 4, COLS).link(4, 7, register.name, register.name);
      cover.height(c, 24).set(c, 1, t('Indicadores por área'), st.h2).merge(c, 1, c, COLS);
      for (let k = 2; k <= COLS; k++) cover.set(c, k, null, st.h2);
      c += 2;
      for (let i = 0; i < ok.length; i += 3) {
        cover.height(c, 22).height(c + 1, 34).height(c + 2, 30).height(c + 3, 5).height(c + 4, 8);
        ok.slice(i, i + 3).forEach((sec, j) => {
          const c1 = 1 + j * 4, c2 = c1 + 3;
          const k0 = (sec.kpis || []).find((k) => k && k.label);
          const ring = sec.ring;
          // No indicator (e.g. inactivity without signInActivity): "—" plus the first sentence of the area's warning.
          const al = !ring && !k0 ? (sec.alerts || [])[0] : null;
          const tone = ring ? ring.tone : k0 ? toneOf(k0.cls) : al ? al.tone : '';
          const S = tile[tone] || tile[''];
          let v = null, sv = S.txt, lbl = '';
          if (ring) { v = Math.max(0, Math.min(1, ring.pct / 100)); sv = S.pct; lbl = ring.label + (ring.caption ? ' · ' + ring.caption : ''); }
          else if (k0) { const kv0 = kpiValue(k0); v = kv0.v; sv = kv0.numeric ? S.num : S.txt; lbl = textOf(k0.label); }
          else { v = '—'; lbl = al ? String(al.text).split(/[.:]\s/)[0].replace(/[.:]$/, '').slice(0, 120) : ''; }
          cover.set(c, c1, sec.short || sec.title, S.area).merge(c, c1, c, c2);
          cover.set(c + 1, c1, v, sv).merge(c + 1, c1, c + 1, c2);
          cover.set(c + 2, c1, lbl, S.lbl).merge(c + 2, c1, c + 2, c2);
          cover.set(c + 3, c1, null, S.line).merge(c + 3, c1, c + 3, c2);
          for (let k = c1 + 1; k <= c2; k++) { cover.set(c, k, null, S.area); cover.set(c + 1, k, null, sv); cover.set(c + 2, k, null, S.lbl); cover.set(c + 3, k, null, S.line); }
          cover.link(c, c1, sum.name, sec.short || sec.title, sec.anchor);
        });
        c += 5;
      }
      // Attention points from every area, most severe first.
      const pts = findings.filter((item) => item.tone === 'danger' || item.tone === 'warn');
      c++;
      cover.height(c, 24).set(c, 1, t('Pontos de atenção'), st.h2).merge(c, 1, c, COLS);
      for (let k = 2; k <= COLS; k++) cover.set(c, k, null, st.h2);
      c += 2;
      if (!pts.length) { cover.set(c, 1, t('Nenhum ponto crítico ou de atenção nas seções disponíveis.'), st.li).merge(c, 1, c, COLS); c += 2; }
      for (const n of pts.slice(0, 16)) {
        const sec = n.section;
        cover.height(c, Math.max(24, 15 * Math.min(5, Math.ceil(n.text.length / 95)) + 8));
        cover.set(c, 1, t(TONE_LABEL[n.tone]), tag[n.tone]);
        cover.set(c, 2, sec.short || sec.title, st.tdB).merge(c, 2, c, 3); cover.set(c, 3, null, st.tdB);
        cover.set(c, 4, n.text, st.tdW).merge(c, 4, c, COLS);
        for (let k = 5; k <= COLS; k++) cover.set(c, k, null, st.tdW);
        cover.link(c, 2, sum.name, sec.short || sec.title, sec.anchor);
        c++;
      }
      if (pts.length > 16 && register) { cover.set(c, 1, register.name, st.link).merge(c, 1, c, COLS).link(c, 1, register.name, register.name); c++; }
      // Contents.
      c += 2;
      cover.height(c, 24).set(c, 1, t('Conteúdo'), st.h2).merge(c, 1, c, COLS);
      for (let k = 2; k <= COLS; k++) cover.set(c, k, null, st.h2);
      c += 2;
      cover.set(c, 1, t('Área'), st.th).merge(c, 1, c, 4); for (let k = 2; k <= 4; k++) cover.set(c, k, null, st.th);
      cover.set(c, 5, t('Planilhas'), st.th).merge(c, 5, c, 10); for (let k = 6; k <= 10; k++) cover.set(c, k, null, st.th);
      cover.set(c, 11, t('Linhas'), st.thR).merge(c, 11, c, COLS); cover.set(c, COLS, null, st.thR);
      c++;
      for (const sec of sections) {
        const tbs = sec.error ? [] : (sec.tables || []);
        cover.set(c, 1, sec.title, st.tdB).merge(c, 1, c, 4); for (let k = 2; k <= 4; k++) cover.set(c, k, null, st.tdB);
        if (sec.error) { cover.set(c, 5, t('Não foi possível gerar esta seção: {s}', { s: sec.error }), st.tdW).merge(c, 5, c, COLS); for (let k = 6; k <= COLS; k++) cover.set(c, k, null, st.tdW); c++; continue; }
        if (!tbs.length) { cover.set(c, 5, t('Resumo executivo'), st.link).merge(c, 5, c, 10); cover.link(c, 5, sum.name, t('Resumo executivo'), sec.anchor); for (let k = 6; k <= 10; k++) cover.set(c, k, null, st.td); cover.set(c, 11, null, st.td).merge(c, 11, c, COLS); cover.set(c, COLS, null, st.td); c++; continue; }
        tbs.forEach((tb, i) => {
          if (i) { cover.set(c, 1, null, st.td).merge(c, 1, c, 4); for (let k = 2; k <= 4; k++) cover.set(c, k, null, st.td); }
          cover.set(c, 5, tb.sheetName, st.link).merge(c, 5, c, 10); for (let k = 6; k <= 10; k++) cover.set(c, k, null, st.td);
          cover.link(c, 5, tb.sheetName, tb.sheetName);
          cover.set(c, 11, tb.rows.length, st.tdN).merge(c, 11, c, COLS); cover.set(c, COLS, null, st.tdN);
          c++;
        });
      }
      c += 1;
      cover.height(c, 30).set(c, 1, t('Planilha montada nesta aba com os dados consultados pelo portal.'), st.note).merge(c, 1, c, COLS);
    }

    /* ---- about sheet */
    const ab = wb.sheet(t('Sobre'), { grid: false, tab: XL.muted, portrait: true, footer: footer(APP) });
    ab.width(0, 2).width(1, 26).width(2, 90);
    let ar = header(ab, 1, 2, t('Sobre este arquivo'), org, false);
    const facts = [
      ['Relatório', title], ['Organização', ten.name || '—'], ['Domínio', ten.domain || '—'], ['ID do tenant', ten.id || '—'],
      ['Gerado em', fmtDate(when.toISOString())], ['Gerado por', user.upn || '—'], ['Escopo', scope || '—'],
      ['Planilhas', wb.sheets.map((s) => s.name).join(' · ')],
      ['Origem dos dados', t('Microsoft Graph, com as permissões de leitura disponíveis ao gerar cada relatório.')],
      ['Filtros', t('Cada planilha de dados é uma tabela do Excel: use os botões de filtro e ordenação no cabeçalho.')],
      ['Versão', APP + ' v' + (CFG.version || '')],
    ];
    facts.forEach(([k, v], i) => { ab.set(ar + i, 1, t(k), st.key).set(ar + i, 2, v, st.val); });
    const blob = await wb.download(file);
    return blob;
  }

  /** What a report panel shows, from the registries (KPIs, distributions, highlights, tables). */
  function harvestPanel(panel) {
    const meta = PANEL_META.get(panel) || { title: (panel.querySelector('h2') || {}).textContent || '' };
    const mine = (el) => el.closest('.panel[data-report]') === panel && el.closest('.dlg') === panel.closest('.dlg');
    const kp = [...panel.querySelectorAll('.kpis')].filter(mine).flatMap((el) => KPI_DATA.get(el) || []);
    // Title of a distribution: the nearest heading before it (its card, or a sibling heading).
    const heading = (el) => {
      for (let n = el; n && n !== panel; n = n.parentElement) {
        for (let sib = n.previousElementSibling; sib; sib = sib.previousElementSibling) {
          const hh = sib.matches('h3, h4') ? sib : sib.querySelector && sib.querySelector(':scope > h3, :scope > .panel-head h3');
          if (hh && hh.textContent.trim()) return hh.textContent.trim();
        }
      }
      return '';
    };
    const bars = [...panel.querySelectorAll('ul.bars, .donut, .score-days')].filter(mine).map((el) => {
      const d = BAR_DATA.get(el);
      return d ? Object.assign({ title: heading(el) }, d) : null;
    }).filter(Boolean);
    // Readable text of a block: its parts separated by spaces, without buttons and links.
    const clean = (el) => {
      const c = el.cloneNode(true);
      c.querySelectorAll('button, a.btn, .ins-act, .ins-ic, details, .svgw').forEach((x) => x.remove());
      const parts = [];
      const walk = (n) => { for (const ch of n.childNodes) { if (ch.nodeType === 3) parts.push(ch.nodeValue); else if (ch.nodeType === 1) { if (/^(P|DIV|LI|STRONG|SMALL|BR|UL|OL)$/.test(ch.tagName)) parts.push(' '); walk(ch); } } };
      walk(c);
      return parts.join('').replace(/\s+/g, ' ').replace(/\s+([.,;:])/g, '$1').trim();
    };
    const toneIn = (el) => (['danger', 'warn', 'ok', 'info'].find((x) => el.classList.contains(x)) || 'info');
    const insights = [...panel.querySelectorAll('.insight, .insight-list > li')].filter(mine).map((el) => ({ text: clean(el.querySelector('div:not(.ins-ic):not(.ins-act)') || el), tone: toneIn(el) })).filter((x) => x.text);
    const alerts = [...panel.querySelectorAll('.alert.warn, .alert.danger')].filter((el) => mine(el) && !el.closest('.gate-foot')).flatMap((el) => {
      const items = [...el.querySelectorAll(':scope > ul.list > li')];
      return (items.length ? items : [el]).map((item) => ({ text: clean(item), tone: toneIn(el) }));
    }).filter((x) => x.text);
    const tables = [...panel.querySelectorAll('.dt')].filter(mine).map((el) => { const d = TABLE_DATA.get(el); return d ? Object.assign({ title: heading(el) }, d) : null; }).filter(Boolean);
    return Object.assign({}, meta, { kpis: kp, bars, insights, alerts, tables });
  }

  async function exportExcel(wrap, rows, columns, name, note) {
    const panel = wrap.closest('.panel[data-report]');
    const sec = panel ? harvestPanel(panel) : { title: t(name) };
    const own = TABLE_DATA.get(wrap) || {};
    sec.tables = [{ rows, columns, name, note, sheet: own.sheet || '', title: own.title || '' }];
    const title = sec.title || t(name);
    try {
      toast(t('Gerando o Excel…'), 'info', 2000);
      await buildWorkbook([sec], { title, file: `${name}-${stamp()}.xlsx`, scope: note || t('Visão atual da tabela') });
    } catch (e) {
      toast(e.message + ' ' + t('Exportando em CSV.'), 'warn');
      exportRows(rows, columns, name, 'csv');
    }
  }

  /** Views worth a section in the executive workbook (actions and administration are not reports). */
  const EXEC_VIEWS = ['overview', 'identity', 'inactive', 'mfa', 'guests', 'privileged', 'risk', 'devices', 'apps', 'managed_apps', 'intune_assignments', 'ca', 'authpolicies', 'appsec', 'securescore', 'identityscore', 'licensing', 'usage', 'mailboxes', 'storage', 'groups', 'domains'];
  const EXEC_DEFAULT = ['overview', 'identity', 'inactive', 'mfa', 'privileged', 'risk', 'devices', 'ca', 'securescore', 'licensing', 'mailboxes', 'storage'];
  /** Renders views off-screen (the same code that draws the screen), waits for their reports and collects what they show. */
  async function harvestViews(ids, onStep) {
    const holder = h('div', { class: 'harvest', 'aria-hidden': 'true' });
    document.body.append(holder);
    const out = [];
    try {
      for (const id of ids) {
        const label = (NAV.flatMap((g) => g.items).find(([k]) => k === id) || [id, id])[1];
        onStep(id, 'run');
        App.harvesting = [];
        let el = null;
        try { el = views[id](); holder.append(el); } catch (e) { App.harvesting = null; out.push({ title: t(label), error: e.message }); onStep(id, 'error'); continue; }
        const waits = App.harvesting || [];
        App.harvesting = null;
        await Promise.allSettled(waits);
        await sleep(60);
        const panels = el.matches('.panel[data-report]') ? [el] : [...el.querySelectorAll('.panel[data-report]')];
        if (!panels.length) out.push({ title: t(label), error: t('A seção não retornou um relatório.') });
        let ok = false;
        for (const panel of panels) {
          const sec = harvestPanel(panel);
          sec.report = sec.title;
          sec.title = t(label) + (panels.length > 1 && sec.title && sec.title !== t(label) ? ' — ' + sec.title : '');
          sec.short = t(SHEET_NAMES[id] || label);
          sec.tables = sec.tables.map((tb, i) => ({ rows: tb.rows, columns: tb.columns, name: tb.name, note: t('Todos os registros'), sheet: tb.sheet || (i ? sec.short + ' · ' + (tb.title || num(i + 1)) : sec.short) }));
          if (!sec.error) ok = true;
          out.push(sec);
        }
        onStep(id, ok ? 'done' : 'error');
        el.remove();
      }
    } finally { holder.remove(); }
    return out;
  }
  function executiveDialog() {
    const reports = App.me.reports || [];
    const items = NAV.flatMap((g) => g.items).filter(([id, , req]) => EXEC_VIEWS.includes(id) && (!req || [].concat(req).every((x) => reports.includes(x))));
    const blocked = new Set((App.me.permissions || {}).blocked || []);
    const boxes = items.map(([id, label, , ic]) => {
      const off = blocked.has(id);
      const cb = h('input', { type: 'checkbox', value: id, checked: !off && EXEC_DEFAULT.includes(id), disabled: off });
      const st = h('span', { class: 'xs-st' });
      return { id, cb, st, el: h('label', { class: 'xs-item' + (off ? ' off' : '') }, cb, icon(ic), h('span', null, label), st) };
    });
    const go = h('button', { class: 'btn primary', type: 'button' }, icon('sheet'), 'Gerar Excel executivo');
    const all = h('button', { class: 'btn sm', type: 'button', onclick: () => boxes.forEach((b) => { if (!b.cb.disabled) b.cb.checked = true; }) }, 'Selecionar todos');
    const none = h('button', { class: 'btn sm', type: 'button', onclick: () => boxes.forEach((b) => { b.cb.checked = false; }) }, 'Limpar');
    const msg = h('div');
    const body = h('div', { class: 'xs' },
      h('p', { class: 'lead-sm' }, 'Uma pasta de trabalho do Excel com o resumo executivo (indicadores, gráficos e destaques de cada área) e uma planilha filtrável por relatório.'),
      h('div', { class: 'xs-tools' }, all, none),
      h('div', { class: 'xs-grid' }, ...boxes.map((b) => b.el)),
      h('p', { class: 'muted small' }, 'Relatórios ainda não gerados nesta sessão são consultados agora no Microsoft Graph — em tenants grandes pode levar alguns minutos. Tudo é montado no seu navegador.'),
      msg, h('div', { class: 'act-foot' }, go));
    const d = J.dialog({ title: t('Relatório executivo (Excel)'), body: h('div', { class: 'dlg-body' }, body), wide: true });
    go.onclick = async () => {
      const ids = boxes.filter((b) => b.cb.checked).map((b) => b.id);
      if (!ids.length) { msg.className = 'alert warn'; msg.textContent = t('Selecione pelo menos um relatório.'); return; }
      go.disabled = all.disabled = none.disabled = true;
      boxes.forEach((b) => { b.cb.disabled = true; b.st.textContent = ids.includes(b.id) ? t('Na fila') : ''; b.st.className = 'xs-st'; });
      msg.className = ''; msg.textContent = '';
      try {
        const secs = await harvestViews(ids, (id, state) => {
          const b = boxes.find((x) => x.id === id);
          if (!b) return;
          b.st.className = 'xs-st ' + state;
          b.st.textContent = t({ run: 'Gerando…', done: 'Pronto', error: 'Falhou' }[state] || '');
        });
        if (!secs.length || secs.every((x) => x.error)) throw new Error(t('Nenhuma seção pôde ser gerada: {s}', { s: (secs.find((x) => x.error) || {}).error || t('sem dados') }));
        const ten = App.me.tenant || {};
        await buildWorkbook(secs, { title: t('Relatório executivo — {s}', { s: ten.name || ten.domain || '' }), file: `relatorio-executivo-${stamp()}.xlsx`, scope: t('{n} seção(ões)', { n: num(secs.length) }), exec: true });
        msg.className = 'alert ok'; msg.textContent = t('Excel gerado. Verifique os downloads do navegador.');
      } catch (e) {
        msg.className = 'alert danger'; msg.textContent = e.message;
      } finally { go.disabled = all.disabled = none.disabled = false; boxes.forEach((b) => { if (!b.el.classList.contains('off')) b.cb.disabled = false; }); }
    };
    return d;
  }

  function exportRows(rows, columns, name, fmt) {
    if (fmt === 'xlsx') { exportExcel(document.body, rows, columns, name, ''); return; }
    const cols = columns.filter((c) => c.label);
    const headers = cols.map((c) => t(c.xlLabel || c.label));
    const decimalComma = !String(CFG.intl || 'pt-BR').toLowerCase().startsWith('en');
    const separator = decimalComma ? ';' : ',';
    const parts = ['\ufeff', headers.map(quoteCsvField).join(separator)];
    let block = [];
    for (const row of rows) {
      const cells = cols.map((c) => {
        const value = c.export ? c.export(row) : (c.value ? c.value(row) : row[c.key]);
        if (typeof value === 'number' && Number.isFinite(value)) {
          const digits = String(value);
          return '"' + (decimalComma ? digits.replace('.', ',') : digits) + '"';
        }
        const label = (v) => verbatimColumn(c.key) ? String(v ?? '') : t(String(v ?? ''));
        const text = Array.isArray(value) ? value.map(label).join('; ') : typeof value === 'boolean' ? yesNo(value) : (value instanceof Node ? value.textContent : label(value));
        return quoteCsvField(text);
      });
      block.push('\r\n' + cells.join(separator));
      if (block.length === 1000) { parts.push(block.join('')); block = []; }
    }
    if (block.length) parts.push(block.join(''));
    const blob = new Blob(parts, { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = h('a', { href: url, download: `${name}-${stamp()}.csv` });
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 4000);
  }
  const stamp = () => new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-');

  /* ------------------------------------------------------------- table */
  /* select: { set: Set, id: (r) => key, can: (r) => bool, why: (r) => tooltip, onChange: () => void } */
  function DataTable({ rows, columns, filters = [], facets = [], segments = null, searchKeys, name, pageSize = 50, onRowClick, emptyText = 'Nenhum registro.', compact = false, select = null, onDraw = null, sheet = '' }) {
    filters = (filters || []).filter(Boolean); // views may pass conditional filters as null
    const state = { q: '', filter: filters.find((f) => f.default)?.id || null, seg: (segments && segments.value) || 'all', sort: null, dir: 1, page: 0, pageSize };
    const pageSizes = [...new Set([pageSize, 25, 50, 100, 250, 500])].sort((a, b) => a - b);
    const shown = columns.filter((c) => !c.exportOnly);
    const width = Math.min(860, shown.length * 145 + (select ? 46 : 0));
    const wrap = h('div', { class: 'dt' + (compact ? ' compact' : ''), style: '--table-min: ' + width + 'px' });
    const segBar = segments ? h('div', { class: 'segbar', role: 'group', 'aria-label': segments.label || 'Segmentos' }) : null;
    const toolbar = h('div', { class: 'toolbar' });
    const chips = h('div', { class: 'chips', role: 'group', 'aria-label': 'Filtros' });
    let qTimer = null; // large tenants: don't filter on every keystroke
    const search = h('input', { type: 'search', placeholder: 'Pesquisar…', 'aria-label': 'Pesquisar…', oninput: (e) => { const v = e.target.value.toLowerCase(); clearTimeout(qTimer); qTimer = setTimeout(() => { state.q = v; state.page = 0; draw(false); }, rows.length > 2000 ? 220 : 40); } });
    const count = h('span', { class: 'muted small count', role: 'status', 'aria-atomic': 'true' });
    const btnCsv = h('button', { class: 'btn sm', type: 'button', title: 'Exportar os resultados em CSV', onclick: () => exportRows(current(), columns, name, 'csv') }, raw('CSV'));
    const btnXlsx = h('button', { class: 'btn sm xl', type: 'button', title: 'Exportar os resultados em Excel', onclick: (e) => { const b = e.currentTarget; b.disabled = true; exportExcel(wrap, current(), columns, name, describe()).finally(() => { b.disabled = false; }); } }, raw('Excel'));
    const downloads = h('div', { class: 'tb-actions', role: 'group', 'aria-label': 'Exportar' }, icon('download'), btnCsv, btnXlsx);
    toolbar.append(h('label', { class: 'search' }, icon('search'), search), count);
    const tablewrap = h('div', { class: 'tablewrap', tabindex: '0', role: 'region', 'aria-label': 'Tabela de resultados' });
    const columnOptions = shown.flatMap((col, i) => col.label ? [h('option', { value: String(i + (select ? 1 : 0)) }, col.label)] : []);
    let columnObserver = null;
    if (columnOptions.length > 1) {
      const jump = h('select', { class: 'j365-input column-jump', 'aria-label': 'Ir para coluna', onchange: (event) => {
        const input = event.currentTarget;
        if (input.value === '') return;
        const cell = tablewrap.querySelector('thead tr')?.children[Number(input.value)];
        if (cell) {
          tablewrap.scrollLeft += cell.getBoundingClientRect().left - tablewrap.getBoundingClientRect().left - tablewrap.clientLeft;
          const target = cell.querySelector('button') || cell;
          if (target === cell) cell.tabIndex = -1;
          target.focus({ preventScroll: true });
        }
        input.value = '';
      } }, h('option', { value: '' }, 'Ir para coluna'), ...columnOptions);
      toolbar.append(jump);
      if (typeof ResizeObserver === 'function') {
        columnObserver = new ResizeObserver(() => {
          if (tablewrap.clientWidth) jump.hidden = tablewrap.scrollWidth <= tablewrap.clientWidth + 1;
        });
        columnObserver.observe(tablewrap);
      }
    }
    toolbar.append(downloads);
    const selectedFacets = new Map();
    const facetBar = facets.length ? h('div', { class: 'facet-bar', role: 'group', 'aria-label': 'Filtros' }) : null;
    const facetInputs = facets.map(({ key, label }) => {
      const input = h('select', { class: 'j365-input', 'aria-label': label, onchange: () => {
        if (input.value === '') selectedFacets.delete(key);
        else selectedFacets.set(key, input.value.slice(1));
        state.page = 0;
        draw(false);
      } });
      facetBar.append(h('label', null, h('span', null, label), input));
      return { key, label, input };
    });
    const emptyState = h('div', { class: 'dt-empty', hidden: true });
    const pager = h('div', { class: 'pager' });
    wrap.append(...(segBar ? [segBar] : []), toolbar, ...(facetBar ? [facetBar] : []), chips, tablewrap, emptyState, pager);

    const keys = searchKeys || columns.map((c) => c.key).filter(Boolean);
    const segItem = () => (segments ? segments.items.find((x) => x.id === state.seg) : null);
    function base() { const sg = segItem(); return sg && sg.fn ? rows.filter(sg.fn) : rows; }
    // Search matches the raw value and its translation ("Híbrido" and "Hybrid").
    const hay = (v) => (Array.isArray(v) ? v : [v]).filter((x) => x !== null && x !== undefined && typeof x !== 'object').map((x) => String(x) + ' ' + t(String(x))).join(' ').toLowerCase();
    function baseMatches() {
      let out = base();
      const f = filters.find((x) => x.id === state.filter);
      if (f && f.fn) out = out.filter(f.fn);
      if (state.match) { const { key, value } = state.match; out = out.filter((r) => (Array.isArray(r[key]) ? r[key].includes(value) : r[key] === value)); }
      if (state.q) out = out.filter((r) => keys.some((k) => { const v = r[k]; return v !== null && v !== undefined && v !== '' && hay(v).includes(state.q); }));
      return out;
    }
    const facetValue = (row, key) => String(row[key] ?? '');
    function matchesFacets(row, except) {
      for (const [key, value] of selectedFacets) if (key !== except && facetValue(row, key) !== value) return false;
      return true;
    }
    function current(pool = baseMatches()) {
      let out = selectedFacets.size ? pool.filter((row) => matchesFacets(row)) : pool;
      if (state.sort) {
        const col = columns.find((c) => c.key === state.sort);
        const val = (r) => (col.sortValue ? col.sortValue(r) : (col.value ? col.value(r) : r[col.key]));
        const empty = (value) => value === null || value === undefined || value === '';
        out = out.map((row) => ({ row, value: val(row) })).sort(({ value: x }, { value: y }) => {
          if (x === y || (empty(x) && empty(y))) return 0;
          if (empty(x)) return 1;
          if (empty(y)) return -1;
          return (x > y ? 1 : -1) * state.dir;
        }).map(({ row }) => row);
      }
      return out;
    }
    let lastFacetState = '';
    function drawFacets(pool) {
      if (!facetInputs.length) return;
      const signature = JSON.stringify([state.q, state.seg, state.filter, state.match?.key, state.match?.value, [...selectedFacets]]);
      if (signature === lastFacetState) return;
      lastFacetState = signature;
      for (const { key, input } of facetInputs) {
        const counts = new Map();
        for (const row of pool) {
          if (!matchesFacets(row, key)) continue;
          const value = facetValue(row, key);
          counts.set(value, (counts.get(value) || 0) + 1);
        }
        const active = selectedFacets.get(key);
        if (active !== undefined && !counts.has(active)) counts.set(active, 0);
        const values = [...counts.keys()].sort(new Intl.Collator(CFG.intl || 'pt-BR', { numeric: true }).compare);
        input.replaceChildren(h('option', { value: '' }, 'Todas'), ...values.map((v) =>
          h('option', { value: '=' + v }, raw((v || t('Não informado')) + ' (' + num(counts.get(v)) + ')'))));
        input.value = active === undefined ? '' : '=' + active;
      }
    }
    function drawSegs() {
      if (!segBar) return;
      segBar.innerHTML = '';
      if (segments.label) segBar.append(h('span', { class: 'seg-label' }, segments.label));
      for (const sg of segments.items) {
        const n = sg.fn ? rows.filter(sg.fn).length : rows.length;
        if (!n && !sg.always && sg.id !== 'all' && sg.id !== state.seg) continue;
        const on = state.seg === sg.id;
        segBar.append(h('button', { type: 'button', class: 'seg' + (on ? ' on' : '') + (n ? '' : ' zero'), 'aria-pressed': on ? 'true' : 'false', title: n ? null : t('Nenhum dispositivo desta plataforma neste tenant'), onclick: () => { state.seg = sg.id; state.page = 0; if (segments.onChange) segments.onChange(sg.id); draw(); } }, sg.icon ? sg.icon.cloneNode(true) : null, h('span', null, sg.label), h('span', { class: 'chip-n' }, raw(num(n)))));
      }
    }
    function drawChips() {
      chips.innerHTML = '';
      chips.hidden = !filters.length && !state.match;
      // Drill-down from a distribution bar: exact value, removable.
      if (state.match) chips.append(h('button', { type: 'button', class: 'chip active match', title: t('Remover filtro'), 'aria-label': t('Remover filtro') + ': ' + state.match.label, onclick: () => { state.match = null; state.page = 0; draw(); } }, raw(state.match.label), h('span', { class: 'chip-x', 'aria-hidden': 'true' }, raw('✕'))));
      const pool = base();
      for (const [i, f] of filters.entries()) {
        const n = f.fn ? pool.filter(f.fn).length : pool.length;
        chips.append(h('button', { class: 'chip' + (state.filter === f.id ? ' active' : ''), type: 'button', 'data-filter-index': i, 'aria-pressed': state.filter === f.id ? 'true' : 'false', onclick: () => {
          state.filter = state.filter === f.id ? null : f.id;
          state.page = 0;
          draw();
          chips.querySelector('[data-filter-index="' + i + '"]')?.focus({ preventScroll: true });
        } }, t(f.label), h('span', { class: 'chip-n' }, raw(num(n)))));
      }
    }
    function draw(updateOptions = true) {
      if (updateOptions) {
        drawSegs();
        drawChips();
      }
      const pool = baseMatches();
      drawFacets(pool);
      const data = current(pool);
      count.textContent = t('{a} de {b}', { a: num(data.length), b: num(rows.length) });
      const pages = Math.max(1, Math.ceil(data.length / state.pageSize));
      state.page = Math.min(state.page, pages - 1);
      const slice = data.slice(state.page * state.pageSize, (state.page + 1) * state.pageSize);
      let selTh = null;
      if (select) {
        const pool = data.filter((r) => select.can(r));
        const on = pool.filter((r) => select.set.has(select.id(r))).length;
        const all = h('input', { type: 'checkbox', 'aria-label': 'Selecionar todos os resultados', title: t('Selecionar todos os resultados ({n})', { n: num(pool.length) }), disabled: !pool.length, checked: pool.length > 0 && on === pool.length, onclick: (e) => { e.stopPropagation(); const add = on !== pool.length; for (const r of pool) { if (add) select.set.add(select.id(r)); else select.set.delete(select.id(r)); } draw(); if (select.onChange) select.onChange(); } });
        all.indeterminate = on > 0 && on < pool.length;
        selTh = h('th', { class: 'sel' }, all);
      }
      const headings = shown.map((c, index) => {
        if (!c.key || !c.label) return h('th', { scope: 'col' }, c.label);
        const sorted = state.sort === c.key;
        const sort = () => {
          state.dir = sorted ? -state.dir : 1;
          state.sort = c.key;
          state.page = 0;
          draw(false);
          tablewrap.querySelector('[data-sort-column="' + index + '"]')?.focus({ preventScroll: true });
        };
        return h('th', { scope: 'col', class: sorted ? 'sorted' : '', 'aria-sort': sorted ? (state.dir > 0 ? 'ascending' : 'descending') : null },
          h('button', { class: 'dt-sort', type: 'button', 'data-sort-column': index, onclick: sort }, c.label, h('span', { class: 'arrow', 'aria-hidden': 'true' }, raw(sorted ? (state.dir > 0 ? '▲' : '▼') : '↕'))));
      });
      const thead = h('thead', null, h('tr', null, selTh, ...headings));
      const tbody = h('tbody');
      emptyState.replaceChildren();
      emptyState.hidden = slice.length > 0;
      if (!slice.length) {
        const filtered = rows.length > 0 && (state.q || state.filter || state.match || state.seg !== 'all' || selectedFacets.size);
        const reset = () => {
          clearTimeout(qTimer);
          state.q = ''; search.value = ''; state.match = null; state.filter = null; state.page = 0;
          selectedFacets.clear();
          if (segments && state.seg !== 'all' && segments.items.some((s) => s.id === 'all')) {
            state.seg = 'all';
            segments.onChange?.('all');
          }
          draw();
          search.focus();
        };
        emptyState.append(h('p', null, filtered ? 'Nenhum resultado para os filtros atuais.' : emptyText));
        if (filtered) emptyState.append(h('button', { class: 'btn sm', type: 'button', onclick: reset }, 'Limpar filtros'));
      }
      for (const r of slice) {
        const tr = h('tr', onRowClick ? { class: 'clickable', tabindex: '0', onclick: () => onRowClick(r), onkeydown: (e) => { if (e.key === 'Enter') onRowClick(r); } } : null);
        if (select) {
          const ok = select.can(r), key = select.id(r);
          if (ok && select.set.has(key)) tr.classList.add('selected');
          tr.append(h('td', { class: 'sel', onclick: (e) => e.stopPropagation() }, h('input', { type: 'checkbox', 'aria-label': 'Selecionar', disabled: !ok, title: ok ? null : (select.why ? select.why(r) : null), checked: ok && select.set.has(key), onchange: (e) => { if (e.target.checked) select.set.add(key); else select.set.delete(key); tr.classList.toggle('selected', e.target.checked); draw(); if (select.onChange) select.onChange(); } })));
        }
        for (const c of shown) {
          const td = h('td', { class: c.wrap ? 'wrap' : '' });
          const v = c.render ? c.render(r) : (c.value ? c.value(r) : r[c.key]);
          if (v instanceof Node) td.append(v); else { td.textContent = v === null || v === undefined || v === '' ? '—' : (Array.isArray(v) ? v.map((x) => t(String(x))).join(', ') : t(String(v))); td.title = td.textContent; }
          tr.append(td);
        }
        tbody.append(tr);
      }
      const horizontalOffset = tablewrap.scrollLeft;
      if (columnObserver && tablewrap.firstChild) columnObserver.unobserve(tablewrap.firstChild);
      const table = h('table', null, thead, tbody);
      tablewrap.replaceChildren(table);
      columnObserver?.observe(table);
      tablewrap.scrollLeft = horizontalOffset;
      tablewrap.scrollTop = 0;
      pager.innerHTML = '';
      pager.append(
        h('span', null, t('Página {a} de {b}', { a: state.page + 1, b: pages })),
        h('div', { class: 'pager-actions' },
          h('select', { class: 'j365-input', 'aria-label': 'Linhas por página', onchange: (e) => { state.pageSize = +e.target.value; state.page = 0; draw(); pager.querySelector('select')?.focus({ preventScroll: true }); } }, ...pageSizes.map((n) => h('option', { value: n, selected: n === state.pageSize }, t('{n}/página', { n })))),
          h('button', { class: 'btn sm', disabled: state.page === 0, onclick: () => { state.page--; draw(false); } }, '‹ Anterior'),
          h('button', { class: 'btn sm', disabled: state.page >= pages - 1, onclick: () => { state.page++; draw(false); } }, 'Próxima ›')
        )
      );
      if (onDraw) { try { onDraw(data); } catch (e) { /* the caller's widget is gone */ } }
    }
    /** Human description of what the table shows right now (goes into the Excel file). */
    function describe() {
      const parts = [];
      const sg = segItem();
      if (sg && sg.id !== 'all') parts.push(t(segments.label || 'Segmento') + ': ' + t(sg.label));
      const f = filters.find((x) => x.id === state.filter);
      if (f) parts.push(t('Filtro') + ': ' + t(f.label));
      if (state.match) parts.push(state.match.label);
      for (const { key, label } of facetInputs) {
        if (selectedFacets.has(key)) parts.push(t(label) + ': ' + (selectedFacets.get(key) || t('Não informado')));
      }
      if (state.q) parts.push(t('Pesquisa') + ': "' + state.q + '"');
      return parts.length ? parts.join(' · ') : t('Todos os registros');
    }
    draw();
    TABLE_DATA.set(wrap, { rows, columns, name, sheet: sheet ? t(sheet) : '', current: () => current() });
    wrap.setFilter = (id) => { state.filter = id; state.page = 0; draw(); wrap.scrollIntoView({ behavior: 'smooth', block: 'start' }); };
    wrap.currentRows = () => current();
    wrap.redraw = () => draw();
    wrap.setSegment = (id) => { state.seg = id; state.page = 0; if (segments && segments.onChange) segments.onChange(id); draw(); wrap.scrollIntoView({ behavior: 'smooth', block: 'start' }); };
    wrap.setMatch = (key, value, label) => {
      state.match = { key, value, label: String(label ?? value) };
      clearTimeout(qTimer);
      state.filter = null; state.q = ''; search.value = ''; state.page = 0;
      if (segments && state.seg !== 'all' && segments.items.some((x) => x.id === 'all')) { state.seg = 'all'; if (segments.onChange) segments.onChange('all'); }
      draw(); wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    wrap.setSearch = (q) => { clearTimeout(qTimer); state.q = String(q || '').toLowerCase(); search.value = q || ''; state.page = 0; draw(); wrap.scrollIntoView({ behavior: 'smooth', block: 'start' }); };
    return wrap;
  }

  function kpis(items) {
    const list = items.filter(Boolean);
    const box = h('div', { class: 'kpis' });
    KPI_DATA.set(box, list);
    list.forEach((k, i) => {
      const v = h('span', { class: 'v' }, raw('—'));
      // NaN/Infinity (e.g. a sum over fields a partial payload does not carry) count as missing, never as "NaN".
      const missing = k.value === null || k.value === undefined || k.value === '' || (typeof k.value === 'number' && !isFinite(k.value));
      const numeric = !missing && !isNaN(Number(k.value));
      // Share of a total ("12% do total") when the view says what the total is.
      const share = numeric && +k.of > 0 ? h('span', { class: 'kpi-of' }, raw(t(k.ofText || '{p} do total', { p: fmtPct(pctOf(Number(k.value), +k.of)) }))) : null;
      // Labels may break after "/" ("Enterprise/Education") instead of in the middle of a word.
      const el = h(k.onclick ? 'button' : 'div', { class: 'kpi ' + (k.cls || '') + (k.onclick ? ' link' : ''), type: k.onclick ? 'button' : null, onclick: k.onclick || null, title: k.title || null, style: `--i:${i}` },
        h('span', { class: 'l' }, h('i', { class: 'kdot', 'aria-hidden': 'true' }), h('span', null, raw(t(k.label).replace(/\//g, '/\u200b')))), v, share);
      if (!numeric) { v.textContent = missing ? '—' : t(String(k.value)); if (v.textContent.length > 6) v.classList.add('txt'); }
      else countUp(v, Number(k.value), 900);
      box.append(el);
    });
    return box;
  }

  /* Compact horizontal bars for distributions: [[label, count, onclick?], …]. Labels are translated. */
  function barList(entries, total, opts = {}) {
    const rows = entries.filter(([, v]) => Number.isFinite(+v) && +v > 0).slice(0, opts.limit || 40);
    if (!rows.length) return h('p', { class: 'muted small' }, 'Nenhum registro.');
    const max = Math.max(...rows.map(([, v]) => +v));
    const denominator = Number.isFinite(+total) && +total >= max ? +total : 0;
    const ul = h('ul', { class: 'bars' });
    BAR_DATA.set(ul, { items: rows, total: denominator, opts });
    for (const [label, v, onclick] of rows) {
      const width = (100 * v) / (denominator || max);
      const li = h(onclick ? 'button' : 'li', { class: 'bar-row' + (onclick ? ' link' : ''), type: onclick ? 'button' : null, onclick: onclick || null },
        h('span', { class: 'bl' }, label), h('span', { class: 'bt', 'aria-hidden': 'true' }, h('i', { style: `width:${width}%` })),
        h('span', { class: 'bv' }, raw((opts.fmt ? opts.fmt(v) : num(v)) + (denominator ? ' · ' + fmtPct(width) : ''))));
      ul.append(onclick ? h('li', null, li) : li);
    }
    return ul;
  }

  function modal(title, body) {
    const d = J.dialog({ title: typeof title === 'string' ? raw(title) : title, body: h('div', { class: 'dlg-body' }, body), wide: true });
    return { remove: d.close };
  }

  function kv(pairs) {
    const dl = h('dl', { class: 'kv' });
    for (const [k, v] of pairs) dl.append(h('dt', null, k), h('dd', null, v instanceof Node ? v : (Array.isArray(v) ? (v.length ? v.map((x) => t(String(x))).join(', ') : '—') : (v === null || v === undefined || v === '' ? '—' : t(String(v))))));
    return dl;
  }

  /* ------------------------------------------------------- executive visuals */
  // Categorical palette (brand blues first); status colours come from the tones.
  const VIZ = ['#0068b8', '#3cc8ee', '#2bb3a3', '#7c5cdb', '#e58a1f', '#5a7fa8', '#0e9a5b', '#c2477a', '#00a2e8', '#b07d2b'];
  const TONE_HEX = { ok: '#12a15e', warn: '#d98a0b', danger: '#d93f3f', info: '#0072c6', muted: '#9fb3c8' };
  const pctOf = (a, b) => (a !== null && a !== undefined && a !== '' && Number.isFinite(+a) && Number.isFinite(+b) && +b > 0 ? 100 * a / b : null);
  const fmtPct = (p) => {
    if (p === null || p === undefined || !Number.isFinite(+p)) return '—';
    if (p > 0 && p < 1) return '<1%';
    if (p > 99 && p < 100) return '>99%';
    return num(Math.round(p)) + '%';
  };
  /** Tone of a share: higher is better (≥ ok → ok, ≥ warn → warn, else danger), or lower is better with invert. */
  const toneFor = (p, ok = 90, warn = 75, invert = false) => (p === null || p === undefined || !isFinite(p) ? 'info' : invert ? (p <= ok ? 'ok' : p <= warn ? 'warn' : 'danger') : (p >= ok ? 'ok' : p >= warn ? 'warn' : 'danger'));
  let svgSeq = 0;
  /** Health ring { pct, tone }: the arc and the number animate once when the report arrives. */
  function ring(spec) {
    const pct = Number(spec.pct);
    const C = 2 * Math.PI * 52, id = 'jg' + (++svgSeq), target = (C * (1 - Math.min(100, Math.max(0, pct)) / 100)).toFixed(2);
    const el = h('div', { class: 'ring ' + (spec.tone || 'info'), role: 'img', 'aria-label': fmtPct(pct) + ' — ' + t(spec.label || '') });
    el.innerHTML = `<svg viewBox="0 0 120 120" aria-hidden="true"><defs><linearGradient id="${id}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" class="s1"/><stop offset="1" class="s2"/></linearGradient></defs><circle class="ring-track" cx="60" cy="60" r="52"/><circle class="ring-val" cx="60" cy="60" r="52" stroke="url(#${id})" stroke-dasharray="${C.toFixed(2)}" stroke-dashoffset="${J.RM ? target : C.toFixed(2)}" transform="rotate(-90 60 60)"/></svg>`;
    const b = h('b', null, raw(J.RM ? fmtPct(pct) : '0%'));
    el.append(h('div', { class: 'ring-txt' }, b));
    if (!J.RM) {
      const arc = el.querySelector('.ring-val');
      requestAnimationFrame(() => requestAnimationFrame(() => { arc.style.strokeDashoffset = target; }));
      const t0 = performance.now(), dur = 1300;
      const step = (now) => { const k = Math.min(1, (now - t0) / dur), e = 1 - Math.pow(1 - k, 3); b.textContent = k < 1 ? num(Math.round(pct * e)) + '%' : fmtPct(pct); if (k < 1) requestAnimationFrame(step); };
      requestAnimationFrame(step);
    }
    return el;
  }
  /** Donut + legend for a distribution [[label, value, onclick?, tone?]] — read by the Excel export like bar lists. */
  function donut(entries, { total = null, center = null, centerLabel = '', fmt = null, key = '' } = {}) {
    const items = entries.filter(([, v]) => Number.isFinite(+v) && +v > 0);
    const sum = items.reduce((a, [, v]) => a + (+v || 0), 0);
    const knownTotal = total !== null && Number.isFinite(+total) && +total >= 0;
    // Overlapping counts cannot partition a circle. A partial partition includes its unclassified remainder.
    if (knownTotal && sum > +total) return barList(items, total, { fmt, key });
    const base = knownTotal ? +total : sum;
    if (base > sum) items.push([t('Sem classificação'), base - sum, null, 'muted']);
    const wrap = h('div', { class: 'donut' });
    BAR_DATA.set(wrap, { items, total: base, opts: { kind: 'donut', fmt, key } });
    if (!items.length) { wrap.append(h('p', { class: 'muted small' }, 'Nenhum registro.')); return wrap; }
    let off = 0, k = 0;
    const segs = items.map(([, value, , tone]) => {
      const len = 100 * value / base;
      const segment = { off, len, color: tone ? (TONE_HEX[tone] || tone) : VIZ[k++ % VIZ.length] };
      off += len;
      return segment;
    });
    const chart = h('div', { class: 'd-chart' });
    chart.innerHTML = `<svg viewBox="0 0 42 42" aria-hidden="true"><circle class="d-track" cx="21" cy="21" r="15.915"/>${segs.map((sg, i) => `<circle class="d-seg" cx="21" cy="21" r="15.915" pathLength="100" stroke="${sg.color}" stroke-dasharray="${J.RM ? `${sg.len.toFixed(2)} ${(100 - sg.len).toFixed(2)}` : '0 100'}" stroke-dashoffset="${(25 - sg.off).toFixed(2)}" style="--i:${i}"/>`).join('')}</svg>`;
    chart.append(h('div', { class: 'd-center' }, h('b', null, raw(center !== null && center !== undefined ? String(center) : (fmt ? fmt(base) : num(base)))), centerLabel ? h('span', null, centerLabel) : null));
    const legend = h('ul', { class: 'd-legend' });
    items.forEach(([label, v, onclick], i) => {
      const pct = fmtPct(100 * v / base);
      legend.append(h('li', null, h(onclick ? 'button' : 'div', { class: 'd-row' + (onclick ? ' link' : ''), type: onclick ? 'button' : null, onclick: onclick || null },
        h('i', { class: 'd-sw', style: `background:${segs[i].color}` }), h('span', { class: 'dl' }, label), h('span', { class: 'dv' }, raw((fmt ? fmt(v) : num(v)) + ' · ' + pct)))));
    });
    wrap.append(chart, legend);
    if (!J.RM) {
      requestAnimationFrame(() => requestAnimationFrame(() => chart.querySelectorAll('.d-seg').forEach((c, i) => { c.style.strokeDasharray = `${segs[i].len.toFixed(2)} ${(100 - segs[i].len).toFixed(2)}`; })));
    }
    return wrap;
  }
  /** Highlights / recommended actions [{ tone, text, act: [label, fn] }] — exported to Excel with their tone. */
  function insightList(items, title = 'Destaques') {
    const list = items.filter(Boolean);
    if (!list.length) return document.createDocumentFragment(); // nothing to show (append(null) would print "null")
    return h('div', { class: 'panel insights' }, h('h3', null, title), h('ul', { class: 'insight-list' }, ...list.map((x) => h('li', { class: 'ins ' + (x.tone || 'info') },
      h('span', { class: 'dot', 'aria-hidden': 'true' }), h('div', { class: 'ins-txt' }, typeof x.text === 'string' ? raw(x.text) : x.text),
      x.act ? h('button', { class: 'btn sm link-btn', type: 'button', onclick: x.act[1] }, x.act[0]) : null))));
  }
  const vizCard = (title, content, note) => h('div', { class: 'viz-card' }, h('h3', null, title), h('div', { class: 'viz-body' }, content, note ? h('p', { class: 'muted small viz-note' }, note) : null));
  const vizRow = (...cards) => h('div', { class: 'viz-row' }, ...cards.filter(Boolean));
  /** Counts of a field (arrays count each element) → [[label, n]] sorted, top `limit` + "Outros". */
  function countBy(rows, fn, { limit = 8, empty = 'Não informado', label = null } = {}) {
    const m = new Map();
    for (const r of rows) { const v = fn(r); for (const x of (Array.isArray(v) ? (v.length ? v : ['']) : [v])) { const k = x === null || x === undefined || x === '' ? '\u0000' : String(x); m.set(k, (m.get(k) || 0) + 1); } }
    const all = [...m.entries()].sort((a, b) => b[1] - a[1]);
    const top = all.slice(0, limit), rest = all.slice(limit).reduce((a, [, n]) => a + n, 0);
    const out = top.map(([k, n]) => [k === '\u0000' ? t(empty) : (label ? label(k) : raw(k)), n, k]);
    if (rest) out.push([t('Outros'), rest, null]);
    return out;
  }

  /* ------------------------------------------------------------- shell */
  const App = { me: null, view: 'overview', cache: {}, params: {}, inflight: {} };
  /* One job per report+params: a re-render (language switch, coming back to a view) while a report is
     still running joins the job in progress instead of starting a second one. */
  function fetchReport(type, params, force, onProgress, signal) {
    signal.throwIfAborted();
    const key = type + JSON.stringify(params);
    if (!force && App.cache[key]) return Promise.resolve(App.cache[key]);
    let f = App.inflight[key];
    if (!f) {
      f = App.inflight[key] = { listeners: new Set(), last: null, promise: null, controller: new AbortController() };
      const job = f;
      job.promise = runReport(type, params, (j) => { job.last = j; for (const fn of job.listeners) { try { fn(j); } catch (e) { /* panel gone */ } } }, job.controller.signal)
        .then((payload) => { App.cache[key] = payload; return payload; })
        .finally(() => { if (App.inflight[key] === job) delete App.inflight[key]; });
    }
    f.listeners.add(onProgress);
    if (f.last) onProgress(f.last);
    return waitFor(f.promise, signal).finally(() => {
      f.listeners.delete(onProgress);
      if (signal.aborted && !f.listeners.size && App.inflight[key] === f) {
        delete App.inflight[key];
        f.controller.abort(signal.reason);
      }
    });
  }
  const NAV = [
    { label: 'Geral', items: [['overview', 'Visão geral', 'overview', 'home'], ['activity', 'Meus relatórios', null, 'clock']] },
    { label: 'Identidade', items: [['identity', 'Usuários', 'identity', 'users'], ['inactive', 'Contas inativas', 'identity', 'clock'], ['mfa', 'MFA', 'mfa', 'key'], ['guests', 'Convidados', 'guests', 'guest'], ['privileged', 'Acessos privilegiados', 'privileged', 'crown'], ['risk', 'Risco', 'risk', 'pulse']] },
    { label: 'Dispositivos & apps', items: [['devices', 'Dispositivos', 'devices', 'device'], ['apps', 'Apps descobertos', 'apps', 'box'], ['managed_apps', 'Apps gerenciados', 'managed_apps', 'box'], ['intune_assignments', 'Atribuições do catálogo', 'intune_assignments', 'users']] },
    { label: 'Segurança', items: [['ca', 'Acesso Condicional', 'ca', 'shield'], ['authpolicies', 'Políticas de autenticação', 'authpolicies', 'lock'], ['appsec', 'Apps & consentimentos', 'appsec', 'plug'], ['securescore', 'Secure Score', 'securescore', 'gauge'], ['identityscore', 'Identity Score', 'identityscore', 'gauge']] },
    { label: 'Licenças & uso', items: [['licensing', 'Licenciamento', 'licensing', 'card'], ['usage', 'Uso do M365', 'usage', 'chart']] },
    { label: 'Exchange & arquivos', items: [['mailboxes', 'Caixas de correio', 'mailboxes', 'mail'], ['storage', 'OneDrive & SharePoint', 'storage', 'cloud']] },
    { label: 'Ações', items: [['sync', 'Ações em dispositivos', ['act_sync', 'devices'], 'bolt']] },
    { label: 'Diretório', items: [['groups', 'Grupos', 'groups', 'users'], ['domains', 'Domínios', 'domains', 'globe']] },
    { label: 'Administração', items: [['settings', 'Configurações', null, 'sliders'], ['audit', 'Auditoria', null, 'list']] },
  ];
  const initials = (s) => String(s || '?').split(/[\s.@_-]+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('') || '?';

  /* ---------------------------------------------------------- actions
     Actions run with a delegated token of the admin who clicks (Microsoft enforces their Intune /
     Exchange RBAC and audits under their name). The token is obtained in a popup — opened in the
     click itself so browsers don't block it — and stays on the server, sealed with the session key. */
  const Actions = {
    until: {},
    // Per-tenant model: a token carrying the capability's scope is renewed by this tab for up to 24 h.
    init(me) { const a = (me && me.actions) || {}; const per = CFG.tenancy === 'per_tenant'; for (const [cap, v] of Object.entries(a)) Actions.until[cap] = Date.now() + 1000 * (per && +v.authorized > 0 ? 23 * 3600 : (+v.authorized || 0)); },
    info(cap) { return ((App.me && App.me.actions) || {})[cap] || { enabled: false, allowed: false, roles: [] }; },
    /** True when the server-side authorization still covers `need` seconds (the whole job). */
    ready(cap, need = 120) { return (Actions.until[cap] || 0) - Date.now() > Math.max(120, need) * 1000; },
    pending: {},
    can(cap) { const i = Actions.info(cap); return !!(i.enabled && i.allowed); },
    async reload() { try { const r = await api('actions'); App.me.actions = r.actions; Actions.init(App.me); } catch (e) { /* keep the current state */ } },
    /** Why the signed-in admin can or cannot run an action here (null when nothing needs saying). */
    gate(cap, verb) {
      const i = Actions.info(cap);
      const rbac = h('ul', { class: 'rbac-list' }, ...(i.rbac || []).map((x) => h('li', null, String(x))));
      if (!i.enabled) return h('div', { class: 'alert warn' }, h('strong', null, 'Ações desativadas neste portal. '), 'O operador do portal pode ativá-las em wp-admin → Tenant Jornada365 → Configurações → Módulos.');
      if (i.mode === 'off') return h('div', { class: 'alert warn' }, h('strong', null, 'Ações desativadas neste tenant. '), 'Um Global Administrator ou Privileged Role Administrator desativou as ações em Configurações → Ações.');
      if (!i.allowed) {
        return h('div', { class: 'alert warn' }, h('strong', null, 'Somente consulta para a sua conta. '), t('{a} requer {b} (atribuição ativa).', { a: t(verb), b: (i.roles || []).join(t(' ou ')) }), ' ',
          'Se a sua permissão vem do RBAC do Intune ou do Exchange (por exemplo, Help Desk Operator), um Global Administrator pode liberar o uso em Configurações → Ações → "Qualquer usuário autorizado".',
          h('details', { class: 'rbac' }, h('summary', null, 'Quem pode executar, segundo a Microsoft'), rbac));
      }
      if (!i.byRole) return h('div', { class: 'alert info' }, h('strong', null, 'Executa com o seu RBAC. '), 'A Microsoft valida a sua permissão em cada item (e os escopos, como scope tags do Intune). Itens fora do seu escopo aparecem como "Sem permissão" no resultado.', h('details', { class: 'rbac' }, h('summary', null, 'Quem pode executar, segundo a Microsoft'), rbac));
      return null;
    },
    /** Must be called synchronously from a click handler. */
    authorize(cap) {
      if (Actions.pending[cap]) return Actions.pending[cap]; // one Microsoft window per capability
      if (CFG.tenancy === 'per_tenant' && window.J365Auth) {
        // No popup: the tenant's own app already carries the scope; Exchange gets its token silently.
        const pr = window.J365Auth.ensureCap(cap).then((r) => { Actions.until[cap] = Date.now() + 23 * 3600 * 1000; return r; });
        Actions.pending[cap] = pr;
        const done = () => { if (Actions.pending[cap] === pr) delete Actions.pending[cap]; };
        pr.then(done, done);
        return pr;
      }
      const url = (CFG.actionUrl || '') + (String(CFG.actionUrl || '').includes('?') ? '&' : '?') + 'cap=' + encodeURIComponent(cap) + '&k=' + encodeURIComponent(CFG.actionKey || '');
      const w = 520, hh = 700;
      const left = Math.max(0, (window.screenX || 0) + ((window.outerWidth || 1200) - w) / 2), top = Math.max(0, (window.screenY || 0) + ((window.outerHeight || 900) - hh) / 2);
      const pop = window.open(url, 'j365-action-' + cap, `popup=yes,width=${w},height=${hh},left=${Math.round(left)},top=${Math.round(top)}`);
      const pr = new Promise((resolve, reject) => {
        if (!pop) { reject({ code: 'popup_blocked', message: t('O navegador bloqueou a janela de autorização. Permita pop-ups para este site e tente de novo.') }); return; }
        let done = false, bc = null, timer = null;
        const finish = (p) => {
          if (done) return; done = true;
          clearInterval(timer); window.removeEventListener('message', onMsg); try { if (bc) bc.close(); } catch (e) { /* */ }
          if (p.ok) { Actions.until[cap] = Date.now() + 55 * 60000; resolve(p); } else reject(p);
        };
        const onMsg = (e) => { const p = e.data; if (!p || p.j365 !== 'action-auth' || p.cap !== cap || (e.origin && e.origin !== location.origin)) return; finish(p); };
        window.addEventListener('message', onMsg);
        try { bc = new BroadcastChannel('j365tp-action'); bc.onmessage = (e) => onMsg({ data: e.data, origin: location.origin }); } catch (e) { /* older browsers */ }
        timer = setInterval(async () => {
          if (!pop.closed || done) return;
          clearInterval(timer);
          await sleep(400); // a message may still be on its way
          if (done) return;
          // No message (e.g. the opener link was cut): accept only a token minted by this window,
          // never an older one — closing the window must cancel.
          try { const st = await api('actions'); const a = (st.actions || {})[cap]; if (a && a.authorized > 3300) finish({ ok: true, cap }); else finish({ ok: false, cap, code: 'cancelled', message: t('Autorização cancelada. Nada foi executado.') }); }
          catch (e) { finish({ ok: false, cap, code: 'generic', message: e.message }); }
        }, 600);
      });
      Actions.pending[cap] = pr;
      const clear = () => { if (Actions.pending[cap] === pr) delete Actions.pending[cap]; };
      pr.then(clear, clear);
      return pr;
    },
    async start(cap, body) {
      try { return (await api('actions/' + cap, { method: 'POST', body, exo: cap === 'exchange' })).job; }
      catch (e) { if (e.data && e.data.code === 'j365_action_auth') { Actions.until[cap] = 0; const err = new Error(t('Autorize de novo: a autorização atual não cobre a duração desta execução.')); err.needAuth = true; throw err; } throw e; }
    },
    /** Explains a failed authorization (admin approval needed, popup blocked…) in a dialog. */
    explain(cap, p) {
      const roles = (p.roles && p.roles.length ? p.roles : Actions.info(cap).roles) || [];
      const body = h('div', null, h('p', { class: 'lead-sm' }, p.message || t('Não foi possível autorizar a ação.')));
      if (p.code === 'consent_needed') {
        const per = CFG.tenancy === 'per_tenant';
        body.append(h('div', { class: 'alert info' }, h('strong', null, 'Aprovação única necessária. '),
          per ? 'Um Global Administrator, Cloud Application Administrator ou Application Administrator atualiza o aplicativo do seu tenant (Conectar o tenant). As ações usam permissões delegadas: só funcionam com a conta e o RBAC de quem executa.'
            : 'Um Global Administrator ou Privileged Role Administrator precisa aprovar as permissões de ações do aplicativo (delegadas: elas só funcionam com a conta e o RBAC de quem executa).'),
          p.approve ? h('div', { class: 'copy-row', style: 'margin-top:10px' }, h('input', { class: 'j365-input', type: 'text', readonly: true, value: p.approve, 'aria-label': 'Link de aprovação para o administrador' }), h('button', { class: 'btn', type: 'button', onclick: (e) => { J.copyText(p.approve, e.currentTarget); } }, 'Copiar link')) : null,
          per && window.J365Landing && window.J365Landing.openConnect ? h('div', { class: 'dlg-actions' }, h('button', { class: 'btn primary', type: 'button', onclick: () => window.J365Landing.openConnect({ tenantId: (CFG.account && CFG.account.tenantId) || '', update: true }) }, 'Atualizar o aplicativo')) : null);
      } else if (p.code === 'forbidden') {
        body.append(h('p', { class: 'muted small' }, t('Funções que podem executar: {s}.', { s: roles.join(', ') })));
      } else if (p.code === 'popup_blocked') {
        body.append(h('p', { class: 'muted small' }, 'Na barra de endereço, clique no ícone de pop-up bloqueado e permita este site.'));
      }
      if (p.ms) body.append(h('p', { class: 'muted small mono' }, raw(p.ms)));
      modal(t('Autorização não concluída'), body);
    },
  };

  async function renderShell(reuse = false) {
    App.detachMenu?.();
    if (!(reuse && App.me)) {
      root.innerHTML = '';
      root.className = 'j365tp app-root';
      root.append(h('div', { class: 'j365tp-boot' }, h('span', { class: 'spinner' }), 'Carregando o seu tenant…'));
      try {
        App.me = await api('me', { timeout: REPORT_REQUEST_MS });
        if (!App.me?.tenant || !App.me?.user) throw new Error(t('O servidor devolveu uma resposta inválida.'));
      } catch (e) {
        if (e.message !== 'unauthenticated') {
          root.replaceChildren(h('div', { class: 'j365tp-boot boot-error' },
            h('div', { class: 'alert danger', role: 'alert' }, h('strong', null, 'Não foi possível abrir o portal. '), raw(e.message === 'reauth' ? t('Sua sessão expirou. Entre novamente.') : e.message)),
            h('button', { class: 'btn primary', type: 'button', onclick: () => renderShell() }, 'Tentar novamente')));
        }
        return;
      }
    }
    root.innerHTML = '';
    root.className = 'j365tp app-root';
    const ten = App.me.tenant, u = App.me.user;
    const reports = App.me.reports || [];
    Actions.init(App.me);
    const perm = App.me.permissions || {};
    const blocked = new Set(perm.blocked || []);
    const groups = NAV.map((g) => ({ label: g.label, items: g.items.filter(([id, , req]) => id === 'identityscore' || !req || [].concat(req).every((x) => reports.includes(x))) })).filter((g) => g.items.length);
    const avail = groups.flatMap((g) => g.items.map(([id]) => id));

    const closeMenu = h('button', { class: 'icon-btn drawer-close', type: 'button', 'aria-label': 'Fechar menu', title: 'Fechar menu', onclick: () => setMenu(false, true) }, raw('×'));
    const side = h('aside', { class: 'sidebar', id: 'j365-report-sidebar', 'aria-label': 'Relatórios' }, h('div', { class: 'side-brand' }, logo('side-logo'), h('span', { class: 'brand-chip' }, raw('Tenant')), closeMenu));
    const navEl = h('nav', { class: 'side-nav', 'aria-label': 'Menu' });
    for (const g of groups) {
      navEl.append(h('div', { class: 'side-group' }, g.label));
      for (const [id, label, req, ic] of g.items) {
        const moduleOff = id === 'identityscore' && !reports.includes(id);
        const locked = moduleOff || blocked.has(id) || [].concat(req || []).some((x) => blocked.has(x));
        const lockTitle = moduleOff ? 'Módulo desativado no WordPress' : 'Permissão pendente de aprovação neste tenant';
        navEl.append(h('button', { class: 'side-link' + (locked ? ' locked' : ''), 'data-v': id, type: 'button', title: locked ? t(lockTitle) : null, onclick: () => { go(id); if (narrow.matches) setMenu(false, true); } }, icon(ic), h('span', null, label), locked ? icon('lock2', 'ico lockmark') : null));
      }
    }
    const creator = window.J365Landing && window.J365Landing.creatorChip ? window.J365Landing.creatorChip('side-creator') : null;
    side.append(navEl, h('div', { class: 'side-foot' },
      (() => { const ls = langSwitch(); ls.classList.add('side-lang'); return ls; })(),
      creator,
      h('span', { class: 'side-ver' }, raw('v' + CFG.version))));

    const roleNames = (u.roles || []).map((r) => r.name).filter(Boolean);
    const menuButton = h('button', { class: 'icon-btn menu-toggle', type: 'button', 'aria-controls': side.id, onclick: () => setMenu(side.hidden, true) }, icon('menu'), h('span', null, 'Menu'));
    const header = h('header', { class: 'app-header' },
      h('div', { class: 'tenant-block' },
        menuButton,
        h('div', { class: 'tenant-avatar', 'aria-hidden': 'true' }, raw(initials(ten.name || ten.domain))),
        h('div', { class: 'tenant-txt' }, h('div', { class: 'tenant-name', title: ten.name || ten.domain || ten.id }, raw(ten.name || ten.domain || ten.id)),
          h('div', { class: 'tenant-meta', title: ten.id }, raw(ten.domain || ten.id)))),
      h('div', { class: 'head-tools' },
        h('button', { class: 'btn sm hdr-xl', type: 'button', onclick: executiveDialog, title: t('Excel pronto para a diretoria: indicadores, gráficos, destaques e planilhas com filtros de cada área — montado no seu navegador.') }, icon('sheet'), h('span', null, 'Excel executivo')),
        langSwitch(), themeToggle(),
        h('div', { class: 'user-chip' },
          h('div', { class: 'user-meta' }, h('strong', null, raw(u.name || u.upn)), h('span', null, roleNames.length ? raw(list(roleNames, 1)) : raw(u.upn))),
          h('div', { class: 'avatar', title: u.upn }, raw(initials(u.name || u.upn)))),
        h('a', { class: 'icon-btn', href: CFG.logoutUrl, title: 'Sair', 'aria-label': 'Sair', onclick: () => { if (window.J365Auth) window.J365Auth.clear(); } }, icon('out'))));
    const content = h('div', { class: 'app-content' });
    let banner = null;
    // Approval incomplete (e.g. a module enabled after the tenant consented): say what is missing.
    let permBanner = null;
    if ((perm.missing || []).length && ['partial', 'blocked'].includes(perm.state) && perm.mode === 'per_tenant') {
      // Per-tenant model: the tenant's own app lacks scopes of this version → "Atualizar o aplicativo".
      permBanner = h('div', { class: 'perm-banner', role: 'status' },
        h('div', { class: 'pb-ic' }, icon('lock2')),
        h('div', { class: 'pb-txt' },
          h('strong', null, t('O aplicativo do seu tenant está sem {n} permissão(ões) desta versão do portal.', { n: perm.missing.length })),
          h('span', null, raw(perm.missing.join(' · '))),
          h('small', null, 'Os relatórios marcados com cadeado no menu dependem delas. Um Global Administrator, Cloud Application Administrator ou Application Administrator atualiza o aplicativo em 1 minuto.')),
        h('div', { class: 'pb-act' },
          window.J365Landing && window.J365Landing.openConnect ? h('button', { class: 'btn primary sm', type: 'button', onclick: () => window.J365Landing.openConnect({ tenantId: ten.id, update: true }) }, 'Atualizar o aplicativo') : null,
          h('button', { class: 'btn sm', type: 'button', onclick: (e) => { J.copyText(perm.approveUrl, e.currentTarget); } }, 'Copiar link para o administrador')));
    } else if ((perm.missing || []).length && ['partial', 'blocked'].includes(perm.state)) {
      const canApprove = u.can && u.can.manageAccess;
      permBanner = h('div', { class: 'perm-banner', role: 'status' },
        h('div', { class: 'pb-ic' }, icon('lock2')),
        h('div', { class: 'pb-txt' },
          h('strong', null, t('Aprovação incompleta: {n} permissão(ões) ainda não aprovada(s) neste tenant.', { n: perm.missing.length })),
          h('span', null, raw(perm.missing.join(' · '))),
          h('small', null, 'Os relatórios marcados com cadeado no menu dependem delas. Um Global Administrator ou Privileged Role Administrator aprova em um clique.')),
        h('div', { class: 'pb-act' },
          canApprove ? h('a', { class: 'btn primary sm', href: perm.approveUrl }, 'Aprovar agora') : null,
          h('button', { class: 'btn sm', type: 'button', onclick: (e) => { J.copyText(perm.approveUrl, e.currentTarget); } }, 'Copiar link de aprovação')));
    }
    if (CFG.welcome && !App.welcomed) {
      App.welcomed = true;
      banner = h('div', { class: 'welcome', role: 'status' },
        h('div', { class: 'w-ic' }, icon('shield')),
        h('div', null, h('strong', null, t('{s} instalado no seu tenant.', { s: APP })),
          h('p', null, 'Recomendado: em Entra ID → Aplicativos empresariais → ', h('strong', null, raw(APP)), ' ative Atribuição obrigatória, atribua só o grupo de administradores e crie uma política de Acesso Condicional exigindo MFA resistente a phishing para o app.')),
        h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Fechar', onclick: () => banner.remove(), html: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>' }));
      try { history.replaceState(null, '', location.pathname + location.hash); } catch (e) { /* ignore */ }
    }
    const C = CFG.creator || {};
    const foot = h('footer', { class: 'app-foot' },
      h('span', null, t('Sessão expira em {s}', { s: fmtDate(App.me.session.expiresAt) })),
      h('span', null, h('a', { href: C.url || '#', target: '_blank', rel: 'noopener' }, 'Projeto criado por', ' ', raw(C.name || '')), raw(` · ${APP} v${CFG.version}`)));
    const main = h('main', { class: 'app-main' }, header, banner, permBanner, content, foot);
    root.append(h('div', { class: 'app-bg', 'aria-hidden': 'true' }, h('i', { class: 'blob b1' }), h('i', { class: 'blob b2' })),
      h('div', { class: 'app-shell' }, side, main, h('div', { class: 'scrim', 'aria-hidden': 'true', onclick: () => setMenu(false, true) })));
    const narrow = window.matchMedia('(max-width: 900px)');
    const menuKey = () => 'j365.menu.' + (narrow.matches ? 'mobile' : 'desktop');
    function setMenu(open, userAction = false) {
      const drawer = narrow.matches && open;
      side.hidden = !open;
      main.inert = drawer;
      root.classList.toggle('nav-open', drawer);
      root.classList.toggle('nav-collapsed', !open && !narrow.matches);
      if (drawer) {
        side.setAttribute('role', 'dialog');
        side.setAttribute('aria-modal', 'true');
      } else {
        side.removeAttribute('role');
        side.removeAttribute('aria-modal');
      }
      const label = t(open ? 'Fechar menu' : 'Abrir menu');
      menuButton.setAttribute('aria-label', label);
      menuButton.setAttribute('aria-expanded', String(open));
      menuButton.title = label;
      if (userAction) {
        try { localStorage.setItem(menuKey(), open ? 'open' : 'closed'); } catch (_) { /* storage disabled */ }
      }
      if (drawer) closeMenu.focus({ preventScroll: true });
      else if (userAction || (!open && side.contains(document.activeElement))) menuButton.focus({ preventScroll: true });
    }
    const restoreMenu = () => {
      let saved;
      try { saved = localStorage.getItem(menuKey()); } catch (_) { /* use the viewport default */ }
      setMenu(saved === 'open');
    };
    const menuKeys = (event) => {
      if (!narrow.matches || side.hidden || document.querySelector('.dlg-bg')) return;
      if (event.key === 'Escape') { event.preventDefault(); setMenu(false, true); }
      if (event.key !== 'Tab') return;
      const visible = [...side.querySelectorAll('button:not(:disabled), a[href]')].filter((el) => el.getClientRects().length);
      const last = visible[visible.length - 1];
      if (event.shiftKey && document.activeElement === closeMenu) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); closeMenu.focus(); }
    };
    narrow.addEventListener('change', restoreMenu);
    document.addEventListener('keydown', menuKeys);
    App.detachMenu = () => {
      narrow.removeEventListener('change', restoreMenu);
      document.removeEventListener('keydown', menuKeys);
      App.detachMenu = null;
    };
    restoreMenu();

    const go = (v, keepScroll = false, replace = false) => {
      if (!avail.includes(v)) v = 'overview';
      App.view = v;
      for (const b of side.querySelectorAll('.side-link')) { const on = b.dataset.v === v; b.classList.toggle('active', on); if (on) b.setAttribute('aria-current', 'page'); else b.removeAttribute('aria-current'); }
      content.innerHTML = '';
      const view = views[v]();
      if (!keepScroll) view.classList.add('view-in');
      content.append(view);
      if (!keepScroll) window.scrollTo(0, 0);
      if (location.hash !== '#' + v) {
        try {
          if (replace) history.replaceState(null, '', '#' + v);
          else history.pushState(null, '', '#' + v);
        } catch (e) { /* The selected view remains available when History is restricted. */ }
      }
    };
    App.go = go;
    if (!App.hashBound) {
      App.hashBound = true;
      window.addEventListener('hashchange', () => { const v = location.hash.slice(1) || 'overview'; if (v !== App.view && v !== 'setup' && App.go) App.go(v, false, true); });
    }
    if (reuse) {
      // Language switch: same view, same reports (from this tab's memory), same scroll position.
      const y = window.scrollY;
      go(App.view, true, true);
      requestAnimationFrame(() => window.scrollTo(0, y));
    } else {
      let destination = location.hash.slice(1);
      try {
        if (!destination && Date.now() < Number(sessionStorage.getItem('j365.identityScoreReturn'))) destination = 'identityscore';
        sessionStorage.removeItem('j365.identityScoreReturn');
      } catch (_) { /* Direct navigation still works when tab storage is disabled. */ }
      go((destination !== 'setup' && destination) || App.view, false, true);
    }
    if (!reuse && CFG.setup && CFG.setup.can && CFG.setup.autoOpen && window.J365Landing) setTimeout(window.J365Landing.openSetup, 500);
    // "Atualizar o aplicativo" link sent to an administrator who is already signed in.
    if (!reuse && new URLSearchParams(location.search).get('connect') === '1' && window.J365Landing && window.J365Landing.openConnect) {
      setTimeout(() => window.J365Landing.openConnect({ tenantId: ten.id, update: true }), 500);
    }
  }

  function renderLanding(message) {
    if (location.hash === '#identityscore') rememberIdentityScore();
    App.detachMenu?.();
    App.go = null;
    if (window.J365Landing) window.J365Landing.render(root, message);
  }
  document.addEventListener('j365:unauth', () => renderLanding(t('Sua sessão expirou. Entre novamente.')));


  function skeleton(rows = 6) {
    return h('div', { class: 'skel', 'aria-hidden': 'true' }, h('div', { class: 'skel-kpis' }, ...Array.from({ length: 4 }, () => h('i'))), ...Array.from({ length: rows }, (_, i) => h('i', { class: 'skel-row', style: `--w:${70 + ((i * 17) % 30)}%` })));
  }

  /* Report panel: executive hero (title, source, health ring, tools) + progress + body. */
  const navIcon = (type) => { for (const g of NAV) for (const [id, , req, ic] of g.items) if (id === type || [].concat(req || []).includes(type)) return ic; return 'chart'; };
  function reportPanel(type, params, title, build, opts = {}) {
    const panel = h('div', { class: 'panel rp', 'data-report': type, 'data-graph-source': opts.graphSource });
    PANEL_META.set(panel, { type, title: t(title), subtitle: opts.subtitle ? t(opts.subtitle) : '' });
    const h2 = h('h2', null, title);
    const status = h('div', { class: 'rp-status', role: 'status' });
    const refresh = h('button', { class: 'btn sm glass', type: 'button', onclick: () => load(true), title: 'Consultar o Microsoft Graph novamente' }, icon('refresh'), 'Gerar novamente');
    let request = null;
    const cancel = h('button', { class: 'btn sm glass rp-cancel', type: 'button', hidden: true, onclick: () => request?.abort() }, 'Cancelar espera');
    const ringBox = h('div', { class: 'rp-ring', hidden: true });
    const tools = h('div', { class: 'rp-tools' }, opts.controls || null, refresh, cancel, opts.tools || null);
    const hero = h('header', { class: 'rp-hero' },
      h('div', { class: 'rp-main' },
        h('div', { class: 'rp-ic', 'aria-hidden': 'true' }, icon(opts.icon || navIcon(type))),
        h('div', { class: 'rp-txt' }, h2, opts.subtitle ? h('p', { class: 'rp-sub' }, opts.subtitle) : null, status)),
      ringBox, tools);
    const body = h('div', { class: 'rp-body' });
    const feedback = h('div', { class: 'rp-feedback', hidden: true });
    const bar = h('div', { class: 'progress indeterminate', 'aria-hidden': 'true' }, h('i'));
    let lastGenerated = '';
    panel.append(hero, bar, feedback, body);
    const ctx = {
      panel,
      /** Title shown in the hero and used by the Excel export. */
      setTitle(txt) { h2.textContent = txt; PANEL_META.set(panel, Object.assign({}, PANEL_META.get(panel), { title: txt })); },
      /** Health ring { pct, label, caption, tone } (null hides it). */
      setRing(spec) {
        ringBox.innerHTML = '';
        const ok = spec && spec.pct !== null && spec.pct !== undefined && isFinite(spec.pct);
        ringBox.hidden = !ok;
        PANEL_META.set(panel, Object.assign({}, PANEL_META.get(panel), { ring: ok ? { pct: +spec.pct, label: t(spec.label || ''), caption: spec.caption ? t(spec.caption) : '', tone: spec.tone || 'info' } : null }));
        if (!ok) return;
        ringBox.append(ring(spec), h('div', { class: 'rp-ring-txt' }, h('strong', null, spec.label || ''), spec.caption ? h('span', null, spec.caption) : null));
      },
    };

    async function load(force) {
      request = new AbortController();
      const { signal } = request;
      const meta = Object.assign({}, PANEL_META.get(panel));
      delete meta.error;
      PANEL_META.set(panel, meta);
      feedback.hidden = true;
      feedback.replaceChildren();
      bar.style.display = '';
      bar.classList.add('indeterminate');
      bar.firstChild.style.width = '';
      refresh.disabled = true;
      cancel.hidden = false;
      body.setAttribute('aria-busy', 'true');
      panel.classList.add('loading');
      status.textContent = t('Consultando Microsoft Graph…');
      if (!lastGenerated) body.replaceChildren(skeleton());
      try {
        const payload = await fetchReport(type, params, force, (job) => {
          if (job.total > 0) { bar.classList.remove('indeterminate'); bar.firstChild.style.width = Math.min(100, Math.round(100 * job.progress / job.total)) + '%'; }
          status.textContent = job.message ? t(job.message) : t('{n} processados…', { n: num(job.progress) });
        }, signal);
        bar.style.display = 'none';
        const content = build(payload, ctx);
        const warnings = payload._meta?.warnings || [];
        const notice = warnings.length ? h('div', { class: 'alert warn' }, h('strong', null, 'Avisos: '), h('ul', { class: 'list' }, ...warnings.map((w) => h('li', null, w)))) : null;
        body.replaceChildren(...(notice ? [notice, content] : [content]));
        PANEL_META.set(panel, { ...PANEL_META.get(panel), generatedAt: payload._meta?.generated_at || null });
        lastGenerated = fmtDate(payload._meta?.generated_at);
        status.textContent = t('Gerado em {s}', { s: lastGenerated });
        if (opts.ring) { let spec = null; try { spec = opts.ring(payload); } catch (e) { spec = null; } ctx.setRing(spec); }
      } catch (e) {
        if (e.message === 'unauthenticated') { PANEL_META.set(panel, Object.assign({}, PANEL_META.get(panel), { error: t('Sua sessão expirou. Entre novamente.') })); return; }
        bar.style.display = 'none';
        status.textContent = lastGenerated ? t('Exibindo a última consulta concluída em {s}.', { s: lastGenerated }) : '';
        if (!lastGenerated) body.replaceChildren();
        const message = signal.aborted ? t('Espera cancelada. Você pode gerar o relatório novamente.') : t(e.message);
        feedback.hidden = false;
        feedback.append(h('div', { class: 'alert ' + (signal.aborted || lastGenerated ? 'warn' : 'danger'), role: 'alert' }, raw(message)));
        if (!lastGenerated) PANEL_META.set(panel, Object.assign({}, PANEL_META.get(panel), { error: message }));
      } finally {
        request = null;
        refresh.disabled = false;
        cancel.hidden = true;
        body.removeAttribute('aria-busy');
        panel.classList.remove('loading');
      }
    }
    const loading = load(false);
    if (Array.isArray(App.harvesting)) App.harvesting.push(loading);
    return panel;
  }

  /* ------------------------------------------------------------- views */
  const views = {};

  views.overview = () => { const offscreen = Array.isArray(App.harvesting); return reportPanel('overview', {}, 'Visão geral do tenant', (p) => {
    const c = p.counts || {}, o = p.org || {};
    const box = h('div', { class: 'overview-report' });
    const group = (title, ic, items) => h('section', { class: 'kgroup' }, h('h3', null, icon(ic), h('span', null, title)), kpis(items));
    box.append(h('div', { class: 'kgroups' },
      group('Identidade', 'users', [
        { label: 'Usuários', value: c.users, onclick: () => App.go('identity') },
        { label: 'Convidados', value: c.guests, of: c.users, onclick: () => App.go('guests') },
        { label: 'Desabilitados', value: c.disabled, cls: 'warn', of: c.users, onclick: () => App.go('identity') },
        { label: 'Grupos', value: c.groups, onclick: () => App.go('groups') },
      ]),
      group('Dispositivos', 'device', [
        { label: 'Dispositivos (Entra)', value: c.devices, onclick: () => App.go('devices') },
        { label: 'Dispositivos (Intune)', value: c.intuneDevices, onclick: () => App.go('devices') },
      ]),
      group('Segurança e aplicativos', 'shield', [
        { label: 'Políticas CA', value: c.caTotal, onclick: () => App.go('ca') },
        { label: 'CA ativas', value: c.caEnabled, cls: 'ok', of: c.caTotal, ofText: '{p} das políticas', onclick: () => App.go('ca') },
        { label: 'CA somente relatório', value: c.caReportOnly, cls: 'warn', onclick: () => App.go('ca') },
        { label: 'App registrations', value: c.apps, onclick: () => App.go('appsec') },
        { label: 'Service principals', value: c.sps, onclick: () => App.go('appsec') },
      ])));

    // Highlights computed from the overview itself (no extra Graph calls).
    const notes = [];
    const paid = (p.licenses || []).filter((l) => (l.kind || 'paid') === 'paid');
    if (o.adSync) {
      const hrs = o.adLastSync ? Math.floor((Date.now() - new Date(o.adLastSync).getTime()) / 3600000) : null;
      if (hrs === null) notes.push(['warn', t('Sincronização com o AD habilitada, mas sem data da última sincronização.')]);
      else if (hrs >= 24) notes.push(['danger', t('Última sincronização do AD (Entra Connect / Cloud Sync) há {n} horas — verifique o servidor de sincronização.', { n: num(hrs) })]);
      else if (hrs >= 3) notes.push(['warn', t('Última sincronização do AD há {n} horas (o ciclo padrão é de 30 minutos).', { n: num(hrs) })]);
    }
    const over = paid.filter((l) => l.enabled > 0 && l.consumed > l.enabled);
    const full = paid.filter((l) => l.enabled > 0 && l.consumed <= l.enabled && l.consumed / l.enabled >= 0.95);
    const idle = paid.filter((l) => l.enabled > 0 && l.consumed === 0);
    if (over.length) notes.push(['danger', t('{n} licença(s) com mais atribuições do que lugares comprados.', { n: over.length }), () => App.go('licensing')]);
    if (full.length) notes.push(['warn', t('{n} licença(s) com 95% ou mais dos lugares em uso.', { n: full.length }), () => App.go('licensing')]);
    if (idle.length) notes.push(['warn', t('{n} licença(s) paga(s) sem nenhum usuário atribuído.', { n: idle.length }), () => App.go('licensing')]);
    if (c.caReportOnly) notes.push(['info', t('{n} política(s) de Acesso Condicional em modo somente relatório — avalie e ative.', { n: c.caReportOnly }), () => App.go('ca')]);
    if (c.caTotal === 0) notes.push(['danger', t('Nenhuma política de Acesso Condicional. Sem Security Defaults, o tenant fica sem MFA obrigatório.'), () => App.go('authpolicies')]);
    if (!notes.length) notes.push(['ok', t('Nenhum ponto de atenção nas contagens gerais. Abra os relatórios para a análise completa.')]);
    box.append(insightList(notes.map(([tone, text, go]) => ({ tone, text, act: go ? ['Ver relatório', go] : null })), 'Destaques'));

    const KIND_BADGE = { free: ['Gratuita', 'ok'], trial: ['Avaliação', 'warn'], nonseat: ['Não por usuário', ''] };
    box.append(h('div', { class: 'grid2 two' },
      h('div', { class: 'panel' }, h('h3', null, 'Organização'), kv([['Nome', raw(o.name || '—')], ['Tenant ID', h('span', { class: 'mono-val' }, raw(o.id || '—'))], ['País', raw(o.country || '—')], ['Criado em', fmtDay(o.created)], ['Tipo', raw(o.tenantType || '—')], ['Sincronização AD', o.adSync ? t('Sim — último sync {s}', { s: fmtDate(o.adLastSync) }) : t('Não (cloud-only)')]])),
      h('div', { class: 'panel' }, h('h3', null, 'Domínios verificados'), h('ul', { class: 'list' }, ...(p.domains || []).map((d) => h('li', null, raw(d.name), d.default ? ' ' : '', d.default ? badge('padrão', 'info') : null)))),
      h('div', { class: 'panel span2' }, h('h3', null, 'Licenças'), h('div', { class: 'tablewrap', style: 'max-height:340px' }, h('table', { style: 'min-width:0' },
        h('thead', null, h('tr', null, h('th', null, 'Produto'), h('th', null, 'Uso'), h('th', null, 'Consumidas'), h('th', null, 'Compradas'))),
        h('tbody', null, ...(p.licenses || []).map((l) => h('tr', null,
          h('td', null, product(l.sku, l.part), KIND_BADGE[l.kind] ? h('span', null, ' ', badge(...KIND_BADGE[l.kind])) : null),
          h('td', null, l.enabled > 0 && (l.kind || 'paid') === 'paid' ? meter(Math.round((100 * l.consumed) / l.enabled), l.consumed > l.enabled ? 'danger' : (l.consumed / l.enabled >= 0.95 ? 'warn' : 'ok')) : '—'),
          h('td', null, num(l.consumed)), h('td', null, num(l.enabled)))))))))
    );
    return box;
  }, { subtitle: 'Contagens rápidas via $count. Use os menus para relatórios detalhados.', icon: 'home',
    tools: offscreen ? null : h('button', { class: 'btn sm primary', type: 'button', onclick: executiveDialog, title: t('Excel pronto para a diretoria: indicadores, gráficos, destaques e planilhas com filtros de cada área — montado no seu navegador.') }, icon('sheet'), 'Gerar Excel executivo'),
    ring: (p) => {
      const paid = (p.licenses || []).filter((l) => (l.kind || 'paid') === 'paid' && l.enabled > 0);
      const used = paid.reduce((a, l) => a + (l.consumed || 0), 0), bought = paid.reduce((a, l) => a + (l.enabled || 0), 0), pc = pctOf(used, bought);
      return bought ? { pct: Math.min(100, pc), label: 'dos lugares pagos em uso', caption: t('{a} de {b} licenças', { a: num(used), b: num(bought) }), tone: pc > 100 ? 'danger' : pc >= 80 ? 'ok' : 'warn' } : null;
    } }); };

  /* Identity */
  const identityColumns = [
    { key: 'displayName', label: 'Nome' }, { key: 'upn', label: 'UPN' },
    { key: 'userType', label: 'Tipo', render: (r) => badge(r.userType === 'Guest' ? 'Convidado' : 'Membro', r.userType === 'Guest' ? 'warn' : 'info') },
    { key: 'enabled', label: 'Ativo', render: (r) => bool(r.enabled) },
    { key: 'source', label: 'Origem' },
    { key: 'lastSignIn', label: 'Último login', render: (r) => fmtDate(r.lastSignIn), export: (r) => r.lastSignIn },
    { key: 'daysSinceSignIn', label: 'Dias sem login', render: (r) => (r.siaUnknown ? '—' : r.daysSinceSignIn === null ? badge('nunca', 'danger') : r.daysSinceSignIn) },
    { key: 'signInState', label: 'Situação do login', exportOnly: true, export: (r) => signInState(r) },
    { key: 'licensed', label: 'Licenciado', render: (r) => bool(r.licensed) },
    { key: 'licenses', label: 'Licenças', render: (r) => r.licenses?.length ? raw(r.licenses.length > 2 ? r.licenses.slice(0, 2).join(', ') + ` (+${r.licenses.length - 2})` : r.licenses.join(', ')) : '—', wrap: true },
    { key: 'licenseCodes', label: 'Códigos das licenças (skuPartNumber)', exportOnly: true },
    { key: 'adminRoles', label: 'Funções admin', render: (r) => r.adminRoles?.length ? badge(list(r.adminRoles, 2), 'danger') : '—', wrap: true },
    { key: 'department', label: 'Departamento' }, { key: 'jobTitle', label: 'Cargo' },
    { key: 'created', label: 'Criado em', render: (r) => fmtDay(r.created), export: (r) => r.created },
    { key: 'pwdChanged', label: 'Senha alterada', render: (r) => fmtDay(r.pwdChanged), export: (r) => r.pwdChanged },
    { key: 'guestState', label: 'Estado convite' },
    { key: 'mail', label: 'E-mail', exportOnly: true },
    { key: 'lastInteractive', label: 'Último login interativo', exportOnly: true, export: (r) => r.lastInteractive },
    { key: 'lastNonInteractive', label: 'Último login não interativo', exportOnly: true, export: (r) => r.lastNonInteractive },
    { key: 'paidLicense', label: 'Licença paga', exportOnly: true, export: (r) => !!r.paidLicense },
    { key: 'company', label: 'Empresa', exportOnly: true }, { key: 'usageLocation', label: 'Local de uso', exportOnly: true },
    { key: 'lastSync', label: 'Último sync do AD', exportOnly: true, export: (r) => r.lastSync },
    { key: 'id', label: 'Object ID', exportOnly: true },
  ];
  const userDetail = (r) => modal(r.displayName || r.upn, kv([['UPN', r.upn], ['E-mail', r.mail], ['Object ID', r.id], ['Tipo', r.userType], ['Ativo', yesNo(r.enabled)], ['Origem', r.source], ['Criado em', fmtDate(r.created)], ['Último login (qualquer)', fmtDate(r.lastSignIn)], ['Último login interativo', fmtDate(r.lastInteractive)], ['Último login não interativo', fmtDate(r.lastNonInteractive)], ['Dias sem login', r.daysSinceSignIn ?? 'nunca'], ['Licenças', r.licenses?.length ? raw(r.licenses.join(', ')) : '—'], ['Funções administrativas', r.adminRoles], ['Departamento', r.department], ['Cargo', r.jobTitle], ['Empresa', r.company], ['Localização de uso', r.usageLocation], ['Senha alterada em', fmtDate(r.pwdChanged)], ['Estado do convite', r.guestState]]));

  views.identity = () => reportPanel('identity', {}, 'Identidade — usuários', (p) => {
    const s = p.summary, box = h('div'), sia = !!p._meta.premium;
    if (!sia) for (const r of p.rows) r.siaUnknown = true; // no signInActivity: an empty date means "unknown", not "never"
    queueMicrotask(() => { App.params.identityFilter = null; }); // one-shot filter from another report
    const table = DataTable({ rows: p.rows, columns: identityColumns, name: 'identidade', onRowClick: userDetail, filters: [
      { id: 'enabled', label: 'Ativos', fn: (r) => r.enabled }, { id: 'disabled', label: 'Desabilitados', fn: (r) => !r.enabled },
      { id: 'guests', label: 'Convidados', fn: (r) => r.userType === 'Guest' }, { id: 'synced', label: 'AD sincronizados', fn: (r) => r.source !== 'Cloud-only' }, { id: 'cloud', label: 'Cloud-only', fn: (r) => r.source === 'Cloud-only' },
      { id: 'licensed', label: 'Licenciados', fn: (r) => r.licensed }, { id: 'unlicensed', label: 'Sem licença', fn: (r) => !r.licensed },
      { id: 'admins', label: 'Administradores', fn: (r) => r.adminRoles?.length }, sia ? { id: 'never', label: 'Nunca logaram', fn: (r) => !r.lastSignIn, default: App.params.identityFilter === 'never' } : null,
      { id: 'disabledLicensed', label: 'Desabilitados c/ licença', fn: (r) => !r.enabled && r.licensed },
    ] });
    box.append(kpis([
      { label: 'Total', value: s.total }, { label: 'Ativos', value: s.enabled, cls: 'ok', of: s.total, onclick: () => table.setFilter('enabled') }, { label: 'Desabilitados', value: s.disabled, cls: 'warn', of: s.total, onclick: () => table.setFilter('disabled') },
      { label: 'Convidados', value: s.guests, onclick: () => table.setFilter('guests') }, { label: 'AD sincronizados', value: s.synced, onclick: () => table.setFilter('synced') }, { label: 'Cloud-only', value: s.cloudOnly, onclick: () => table.setFilter('cloud') },
      { label: 'Licenciados', value: s.licensed, onclick: () => table.setFilter('licensed') }, { label: 'Administradores', value: s.admins, cls: 'danger', onclick: () => table.setFilter('admins') }, { label: 'Nunca logaram', value: sia ? s.neverSignIn : null, cls: 'warn', onclick: sia ? () => table.setFilter('never') : null },
    ]));
    if (!sia) box.append(h('div', { class: 'alert warn' }, 'Atividade de login indisponível (Entra ID P1/P2 necessário). Colunas de último login ficam vazias.'));
    box.append(vizRow(
      vizCard('Situação das contas', donut([['Habilitadas', s.enabled, () => table.setFilter('enabled'), 'ok'], ['Desabilitadas', s.disabled, () => table.setFilter('disabled'), 'muted']], { centerLabel: t('contas'), key: 'enabled' })),
      vizCard('Origem das contas', donut([['AD sincronizado', s.synced, () => table.setFilter('synced')], ['Cloud-only', s.cloudOnly, () => table.setFilter('cloud')]], { centerLabel: t('contas'), key: 'source' })),
      vizCard('Por licença', barList(Object.entries(s.byLicense || {}).map(([k, v]) => [raw(k), v, () => table.setMatch('licenses', k, k)]), s.total, { limit: 8 }))));
    box.append(table);
    return box;
  }, { subtitle: 'Consulte as licenças e as funções administrativas de cada usuário. Os dados de último acesso dependem da licença do Entra ID e das permissões de leitura.', graphSource: '/users /subscribedSkus /roleManagement/directory /directoryRoles', ring: (p) => {
    if (!p._meta.premium) return null;
    const d = App.me.defaults.inactive_days || 90, en = p.rows.filter((r) => r.enabled), act = en.filter((r) => r.daysSinceSignIn !== null && r.daysSinceSignIn <= d).length, pc = pctOf(act, en.length);
    return { pct: pc, label: t('das contas habilitadas usadas nos últimos {n} dias', { n: num(d) }), caption: t('{a} de {b} contas', { a: num(act), b: num(en.length) }), tone: toneFor(pc, 85, 70) };
  } });

  /* Inactive accounts */
  const daysAgo = (iso) => { if (!iso) return null; const x = new Date(iso).getTime(); return isNaN(x) ? null : Math.max(0, Math.floor((Date.now() - x) / 86400000)); };
  /** Plain-language sign-in status for exports (an empty "last sign-in" cell alone is ambiguous). */
  function signInState(r) {
    if (r.siaUnknown) return t('Sem dados de login (requer Entra ID P1/P2)');
    if (r.daysSinceSignIn === null || r.daysSinceSignIn === undefined) return t('Nunca fez login');
    const n = (App.params.inactive && App.params.inactive.days) || App.me.defaults.inactive_days || 90;
    return r.daysSinceSignIn > n ? t('Sem login há mais de {n} dias', { n: num(n) }) : t('Login nos últimos {n} dias', { n: num(n) });
  }
  const BAND = { b180: ['Até 6 meses', 'warn', 1], b365: ['6 a 12 meses', 'warn', 2], b999: ['Mais de 1 ano', 'danger', 3], never: ['Nunca fez login', 'danger', 4] };
  /** What to do with an inactive account (most specific first; AD-synced accounts are changed on-premises). */
  function inactiveAction(r) {
    const onPrem = r.source !== 'Cloud-only' && r.userType !== 'Guest';
    if (r.adminRoles && r.adminRoles.length) return onPrem ? 'Remover as funções administrativas e desabilitar no AD local' : 'Remover as funções administrativas e desabilitar';
    if (r.userType === 'Guest') return 'Remover o convidado ou revisar o acesso';
    if (!r.enabled) return r.licensed ? 'Remover as licenças (conta já desabilitada)' : 'Excluir após o período de retenção';
    if (r.daysSinceSignIn === null) return r.licensed ? 'Conta nunca usada: remover as licenças e desabilitar' : 'Conta nunca usada: confirmar a necessidade e desabilitar';
    if (r.licensed) return onPrem ? 'Desabilitar no AD local e remover as licenças' : 'Desabilitar e remover as licenças';
    return onPrem ? 'Desabilitar a conta no AD local' : 'Desabilitar a conta';
  }
  const inactiveColumns = [
    { key: 'displayName', label: 'Nome' }, { key: 'upn', label: 'UPN' }, { key: 'mail', label: 'E-mail', exportOnly: true },
    { key: 'band', label: 'Situação', render: (r) => badge(BAND[r.band][0], BAND[r.band][1]), export: (r) => BAND[r.band][0], sortValue: (r) => BAND[r.band][2] },
    { key: 'daysSinceSignIn', label: 'Dias sem login', render: (r) => (r.daysSinceSignIn === null ? badge('nunca', 'danger') : num(r.daysSinceSignIn)), sortValue: (r) => (r.daysSinceSignIn === null ? 1e9 : r.daysSinceSignIn) },
    { key: 'lastSignIn', label: 'Último login', render: (r) => fmtDate(r.lastSignIn), export: (r) => r.lastSignIn },
    { key: 'lastInteractive', label: 'Último login interativo', exportOnly: true, export: (r) => r.lastInteractive },
    { key: 'lastNonInteractive', label: 'Último login não interativo', exportOnly: true, export: (r) => r.lastNonInteractive },
    { key: 'enabled', label: 'Conta habilitada', render: (r) => bool(r.enabled, false) },
    { key: 'userType', label: 'Tipo', render: (r) => badge(r.userType === 'Guest' ? 'Convidado' : 'Membro', r.userType === 'Guest' ? 'warn' : 'info'), export: (r) => (r.userType === 'Guest' ? 'Convidado' : 'Membro') },
    { key: 'source', label: 'Origem' },
    { key: 'paidLicense', label: 'Licença paga', render: (r) => (r.paidLicense ? badge('Sim', 'danger') : '—'), export: (r) => !!r.paidLicense },
    { key: 'licenses', label: 'Licenças', render: (r) => r.licenses?.length ? raw(r.licenses.length > 2 ? r.licenses.slice(0, 2).join(', ') + ` (+${r.licenses.length - 2})` : r.licenses.join(', ')) : '—', wrap: true },
    { key: 'licenseCodes', label: 'Códigos das licenças (skuPartNumber)', exportOnly: true },
    { key: 'adminRoles', label: 'Funções admin', render: (r) => r.adminRoles?.length ? badge(list(r.adminRoles, 2), 'danger') : '—', wrap: true },
    { key: 'department', label: 'Departamento' }, { key: 'jobTitle', label: 'Cargo' },
    { key: 'company', label: 'Empresa', exportOnly: true }, { key: 'usageLocation', label: 'Local de uso', exportOnly: true },
    { key: 'created', label: 'Criada em', render: (r) => fmtDay(r.created), export: (r) => r.created },
    { key: 'ageDays', label: 'Dias desde a criação', exportOnly: true },
    { key: 'pwdChanged', label: 'Senha alterada', render: (r) => fmtDay(r.pwdChanged), export: (r) => r.pwdChanged },
    { key: 'lastSync', label: 'Último sync do AD', exportOnly: true, export: (r) => r.lastSync },
    { key: 'action', label: 'Ação recomendada', wrap: true, render: (r) => h('span', { class: 'rec' }, t(r.action)), export: (r) => r.action },
    { key: 'id', label: 'Object ID', exportOnly: true },
  ];
  views.inactive = () => reportPanel('identity', {}, 'Contas inativas', (p, ctx) => {
    const P0 = App.params.inactive || {};
    const days = h('input', { type: 'number', min: 1, max: 730, value: P0.days || App.me.defaults.inactive_days || 90, class: 'j365-input', 'aria-label': 'Dias sem login' });
    const cb = (on) => h('input', { type: 'checkbox', checked: !!on });
    const cDis = cb(P0.disabled), cGuest = cb(P0.guests ?? true), cLic = cb(P0.licensed), cNever = cb(P0.never ?? true);
    const out = h('div');
    const form = h('form', { class: 'criteria', onsubmit: (e) => { e.preventDefault(); draw(); } },
      h('label', { class: 'crit-days' }, h('span', null, 'Sem login há mais de'), days, h('span', null, 'dias')),
      h('label', { class: 'crit-opt' }, cDis, h('span', null, 'Incluir contas desabilitadas')),
      h('label', { class: 'crit-opt' }, cGuest, h('span', null, 'Incluir convidados')),
      h('label', { class: 'crit-opt' }, cLic, h('span', null, 'Somente licenciadas')),
      h('label', { class: 'crit-opt' }, cNever, h('span', null, 'Incluir quem nunca fez login')),
      h('button', { class: 'btn primary sm', type: 'submit' }, 'Aplicar'));
    const box = h('div', null, form, out);
    function draw() {
      const d = Math.max(1, Math.min(730, Math.round(+days.value) || 90));
      days.value = d;
      App.params.inactive = { days: d, disabled: cDis.checked, guests: cGuest.checked, licensed: cLic.checked, never: cNever.checked };
      ctx.setTitle(t('Contas inativas há mais de {n} dias', { n: num(d) }));
      out.innerHTML = '';
      // Without signInActivity (no Entra ID P1/P2) nobody can be called inactive: an empty date is "unknown", not "never".
      form.hidden = !p._meta.premium;
      if (!p._meta.premium) {
        ctx.setRing(null);
        out.append(h('div', { class: 'alert warn' }, h('strong', null, 'Atividade de login indisponível neste tenant.'), ' ',
          'A Microsoft só informa o último login das contas (signInActivity) com Microsoft Entra ID P1 ou P2 e a permissão AuditLog.Read.All. Sem esse dado não é possível saber há quanto tempo cada conta está sem login, então nenhuma conta é marcada como inativa.', ' ',
          h('button', { type: 'button', class: 'link-btn', onclick: () => App.go('identity') }, 'Revisar contas em Usuários')));
        out.append(DataTable({ rows: [], columns: inactiveColumns, name: `inativos-${d}d`, emptyText: 'Sem dados de login para avaliar a inatividade.' }));
        return;
      }
      // Accounts in scope, then inactive ones. Never signed in counts only when the account is older than the window
      // (an account created last week is not "inactive for 90 days").
      const scope = p.rows.filter((r) => (cDis.checked || r.enabled) && (cGuest.checked || r.userType !== 'Guest') && (!cLic.checked || r.licensed));
      const rows = [], recentNever = [];
      for (const r of scope) {
        const x = Object.assign({}, r, { ageDays: daysAgo(r.created) });
        x.band = r.daysSinceSignIn === null ? 'never' : r.daysSinceSignIn > 365 ? 'b999' : r.daysSinceSignIn > 180 ? 'b365' : 'b180';
        x.action = inactiveAction(x);
        if (r.daysSinceSignIn !== null) { if (r.daysSinceSignIn > d) rows.push(x); }
        else if (cNever.checked) { if (x.ageDays === null || x.ageDays > d) rows.push(x); else recentNever.push(x); }
      }
      rows.sort((a, b) => (b.daysSinceSignIn ?? 1e9) - (a.daysSinceSignIn ?? 1e9));
      const n = (fn) => rows.filter(fn).length;
      const enabled = n((r) => r.enabled), paid = n((r) => r.paidLicense), admins = n((r) => r.adminRoles?.length), guests = n((r) => r.userType === 'Guest');
      const never = n((r) => r.daysSinceSignIn === null), year = n((r) => r.daysSinceSignIn !== null && r.daysSinceSignIn > 365), onPrem = n((r) => r.source !== 'Cloud-only' && r.userType !== 'Guest');
      const share = pctOf(rows.length, scope.length);
      ctx.setRing(p._meta.premium && scope.length ? { pct: share, label: 'das contas no escopo estão inativas', caption: t('{a} de {b} contas', { a: num(rows.length), b: num(scope.length) }), tone: toneFor(share, 5, 15, true) } : null);

      const table = DataTable({ rows, columns: inactiveColumns, name: `inativos-${d}d`, onRowClick: userDetail, emptyText: 'Nenhuma conta inativa com estes critérios.', filters: [
        { id: 'enabled', label: 'Ainda habilitadas', fn: (r) => r.enabled }, { id: 'paid', label: 'Com licença paga', fn: (r) => r.paidLicense }, { id: 'adm', label: 'Administradores', fn: (r) => r.adminRoles?.length },
        { id: 'never', label: 'Nunca fizeram login', fn: (r) => r.daysSinceSignIn === null }, { id: 'year', label: 'Mais de 1 ano', fn: (r) => r.daysSinceSignIn !== null && r.daysSinceSignIn > 365 },
        { id: 'guest', label: 'Convidados', fn: (r) => r.userType === 'Guest' }, { id: 'onprem', label: 'Sincronizadas do AD', fn: (r) => r.source !== 'Cloud-only' && r.userType !== 'Guest' },
      ] });
      out.append(kpis([
        { label: 'Contas inativas', value: rows.length, cls: rows.length ? 'warn' : 'ok', of: scope.length, ofText: '{p} das contas no escopo' },
        { label: 'Ainda habilitadas', value: enabled, cls: enabled ? 'danger' : 'ok', of: rows.length, ofText: '{p} das inativas', onclick: () => table.setFilter('enabled') },
        { label: 'Com licença paga', value: paid, cls: paid ? 'danger' : 'ok', of: rows.length, ofText: '{p} das inativas', onclick: () => table.setFilter('paid') },
        { label: 'Administradores', value: admins, cls: admins ? 'danger' : 'ok', onclick: () => table.setFilter('adm') },
        { label: 'Nunca fizeram login', value: never, cls: never ? 'warn' : '', onclick: () => table.setFilter('never') },
        { label: 'Mais de 1 ano sem login', value: year, cls: year ? 'warn' : '', onclick: () => table.setFilter('year') },
        { label: 'Convidados', value: guests, onclick: () => table.setFilter('guest') },
        { label: 'Sincronizadas do AD', value: onPrem, onclick: () => table.setFilter('onprem') },
      ]));
      out.append(insightList([
        enabled ? { tone: 'danger', text: t('{n} conta(s) inativa(s) ainda habilitada(s): desabilite ou bloqueie o login para reduzir a superfície de ataque.', { n: num(enabled) }), act: ['Ver contas', () => table.setFilter('enabled')] } : null,
        admins ? { tone: 'danger', text: t('{n} administrador(es) inativo(s): remova primeiro as funções privilegiadas (ou torne-as elegíveis no PIM).', { n: num(admins) }), act: ['Ver contas', () => table.setFilter('adm')] } : null,
        paid ? { tone: 'warn', text: t('{n} conta(s) inativa(s) com licença paga: remova as licenças para liberar custo.', { n: num(paid) }), act: ['Ver contas', () => table.setFilter('paid')] } : null,
        onPrem ? { tone: 'info', text: t('{n} conta(s) sincronizada(s) do AD local: desabilite no AD — alterações feitas na nuvem são sobrescritas pela sincronização.', { n: num(onPrem) }), act: ['Ver contas', () => table.setFilter('onprem')] } : null,
        guests ? { tone: 'warn', text: t('{n} convidado(s) inativo(s): remova ou use revisões de acesso do Entra ID.', { n: num(guests) }), act: ['Ver contas', () => table.setFilter('guest')] } : null,
        recentNever.length ? { tone: 'info', text: t('{n} conta(s) criada(s) nos últimos {d} dias ainda sem login não entram nesta contagem (não estão inativas há mais de {d} dias).', { n: num(recentNever.length), d: num(d) }), act: ['Ver em Usuários', () => { App.params.identityFilter = 'never'; App.go('identity'); }] } : null,
        !rows.length && p._meta.premium ? { tone: 'ok', text: t('Nenhuma conta inativa há mais de {d} dias com os critérios atuais.', { d: num(d) }) } : null,
      ], 'Ações recomendadas'));
      if (rows.length) {
        const bands = Object.entries(BAND).map(([k, [lbl, tone]]) => [lbl, n((r) => r.band === k), () => table.setMatch('band', k, t(lbl)), k === 'b180' ? '#e8b04b' : k === 'b365' ? TONE_HEX.warn : k === 'b999' ? TONE_HEX.danger : '#8d2f2f']);
        out.append(vizRow(
          vizCard('Tempo sem login', donut(bands, { centerLabel: t('inativas'), key: 'band' })),
          vizCard('Por departamento', barList(countBy(rows, (r) => r.department, { empty: 'Sem departamento' }).map(([l, v, k]) => [l, v, k === null ? null : () => table.setMatch('department', k === '\u0000' ? '' : k, k === '\u0000' ? t('Sem departamento') : k)]), rows.length, { key: 'department' })),
          vizCard('Licenças em contas inativas', paid || rows.some((r) => r.licensed) ? barList(countBy(rows.filter((r) => r.licensed), (r) => r.licenses).map(([l, v, k]) => [l, v, k === null ? null : () => table.setMatch('licenses', k, k)]), rows.length) : h('p', { class: 'muted small' }, 'Nenhuma conta inativa com licença.'))));
      }
      out.append(table);
    }
    draw();
    return box;
  }, { subtitle: 'Considera o login mais recente entre interativo, não interativo e bem-sucedido (signInActivity). Contas que nunca fizeram login entram só quando foram criadas antes do período. Recomendação: desabilitar → remover licenças → excluir após o período de retenção.' });

  /* MFA */
  views.mfa = () => reportPanel('mfa', {}, 'MFA — registro de métodos de autenticação', (p) => {
    const s = p.summary, box = h('div');
    const cols = [
      { key: 'displayName', label: 'Nome' }, { key: 'upn', label: 'UPN' }, { key: 'isAdmin', label: 'Admin', render: (r) => r.isAdmin ? badge('Sim', 'danger') : '—' },
      { key: 'mfaRegistered', label: 'MFA registrado', render: (r) => bool(r.mfaRegistered) }, { key: 'mfaCapable', label: 'MFA capaz', render: (r) => bool(r.mfaCapable) },
      { key: 'phishingResistant', label: 'Resistente a phishing', render: (r) => bool(r.phishingResistant) }, { key: 'passwordless', label: 'Passwordless', render: (r) => bool(r.passwordless) },
      { key: 'risk', label: 'Risco', render: (r) => badge(r.risk, { 'Crítico': 'danger', 'Alto': 'danger', 'Médio': 'warn', 'Baixo': 'ok' }[r.risk]) },
      { key: 'defaultMethod', label: 'Método padrão', render: (r) => r.defaultMethod ? t(methodName(r.defaultMethod)) : '—', export: (r) => methodName(r.defaultMethod) },
      { key: 'methods', label: 'Métodos', render: (r) => list((r.methods || []).map(methodName), 3), export: (r) => (r.methods || []).map(methodName), wrap: true },
      { key: 'ssprRegistered', label: 'SSPR registrado', render: (r) => bool(r.ssprRegistered) }, { key: 'userType', label: 'Tipo' }, { key: 'lastUpdated', label: 'Atualizado', render: (r) => fmtDate(r.lastUpdated), export: (r) => r.lastUpdated },
      { key: 'weakOnly', label: 'Somente métodos fracos', exportOnly: true, export: (r) => !!r.weakOnly },
      { key: 'preferredSecondary', label: 'Método secundário preferido', exportOnly: true, export: (r) => (r.preferredSecondary ? methodName(r.preferredSecondary) : '') },
      { key: 'systemPreferred', label: 'System-preferred MFA', exportOnly: true, export: (r) => !!r.systemPreferred },
      { key: 'systemMethods', label: 'Métodos system-preferred', exportOnly: true, export: (r) => (r.systemMethods || []).map(methodName) },
      { key: 'ssprEnabled', label: 'SSPR habilitado', exportOnly: true, export: (r) => !!r.ssprEnabled }, { key: 'ssprCapable', label: 'SSPR capaz', exportOnly: true, export: (r) => !!r.ssprCapable },
    ];
    const table = DataTable({ rows: p.rows, columns: cols, name: 'mfa', onRowClick: (r) => modal(r.displayName || r.upn, kv([['UPN', r.upn], ['Admin', yesNo(r.isAdmin)], ['MFA registrado', yesNo(r.mfaRegistered)], ['MFA capaz', yesNo(r.mfaCapable)], ['Passwordless', yesNo(r.passwordless)], ['Resistente a phishing', yesNo(r.phishingResistant)], ['Somente métodos fracos', yesNo(r.weakOnly)], ['Métodos registrados', (r.methods || []).map(methodName)], ['Método padrão', methodName(r.defaultMethod)], ['Método secundário preferido', methodName(r.preferredSecondary)], ['System-preferred', yesNo(r.systemPreferred)], ['Métodos system-preferred', (r.systemMethods || []).map(methodName)], ['SSPR registrado / habilitado / capaz', `${yesNo(r.ssprRegistered)} / ${yesNo(r.ssprEnabled)} / ${yesNo(r.ssprCapable)}`], ['Atualizado', fmtDate(r.lastUpdated)]])), filters: [
      { id: 'nomfa', label: 'Sem MFA', fn: (r) => !r.mfaRegistered }, { id: 'admnomfa', label: 'Admins sem MFA', fn: (r) => r.isAdmin && !r.mfaRegistered }, { id: 'admins', label: 'Admins', fn: (r) => r.isAdmin },
      { id: 'weak', label: 'Somente métodos fracos (SMS/voz/e-mail)', fn: (r) => r.weakOnly }, { id: 'strong', label: 'Resistente a phishing', fn: (r) => r.phishingResistant }, { id: 'pwl', label: 'Passwordless', fn: (r) => r.passwordless }, { id: 'nosspr', label: 'Sem SSPR', fn: (r) => !r.ssprRegistered },
    ] });
    box.append(kpis([
      { label: 'Usuários (ativos)', value: s.total }, { label: 'MFA registrado', value: s.mfaRegistered, cls: 'ok' }, { label: 'Sem MFA', value: s.mfaNotRegistered, cls: 'danger', onclick: () => table.setFilter('nomfa') },
      { label: 'Admins sem MFA', value: s.adminsNoMfa, cls: 'danger', onclick: () => table.setFilter('admnomfa') }, { label: 'Resistente a phishing', value: s.phishingResistant, cls: 'ok', onclick: () => table.setFilter('strong') }, { label: 'Passwordless', value: s.passwordless, onclick: () => table.setFilter('pwl') },
      { label: 'Só métodos fracos', value: s.weakOnly, cls: 'warn', onclick: () => table.setFilter('weak') }, { label: 'SSPR registrado', value: s.ssprRegistered },
    ]));
    const strength = [['Resistente a phishing', 0, () => table.setFilter('strong'), 'ok'], ['MFA padrão', 0, null, 'info'], ['Somente métodos fracos', 0, () => table.setFilter('weak'), 'warn'], ['Sem MFA', 0, () => table.setFilter('nomfa'), 'danger']];
    for (const r of p.rows) strength[!r.mfaRegistered ? 3 : r.phishingResistant ? 0 : r.weakOnly ? 2 : 1][1]++;
    box.append(vizRow(
      vizCard('Força da autenticação', donut(strength, { centerLabel: t('usuários') })),
      vizCard('Métodos registrados', barList(Object.entries(s.byMethod || {}).sort((a, b) => b[1] - a[1]).map(([k, v]) => [methodName(k), v]), s.total, { limit: 8 })),
      vizCard('Método padrão', barList(Object.entries(s.byDefault || {}).sort((a, b) => b[1] - a[1]).map(([k, v]) => [methodName(k), v]), s.total, { limit: 8 }))));
    box.append(table);
    return box;
  }, { subtitle: 'Confira quais contas habilitadas registraram MFA e quais têm métodos resistentes a phishing. O relatório também identifica SMS, voz, e-mail e perguntas de segurança.', graphSource: '/reports/authenticationMethods/userRegistrationDetails', ring: (p) => {
    const s = p.summary, pc = pctOf(s.mfaRegistered, s.total);
    return { pct: pc, label: 'dos usuários com MFA registrado', caption: t('{p} com método resistente a phishing', { p: fmtPct(pctOf(s.phishingResistant, s.total)) }), tone: toneFor(pc, 95, 85) };
  } });

  /* Devices */
  const PLATFORM = { windows: 'Windows', windowsServer: 'Windows Server', macos: 'macOS', ios: 'iOS', ipados: 'iPadOS', android: 'Android', linux: 'Linux', chromeos: 'ChromeOS', other: 'Outros' };
  const SUPPORT = { supported: ['Suportado', 'ok'], ending: ['Termina em breve', 'warn'], entOnly: ['Só Enterprise/Education', 'warn'], unsupported: ['Fora de suporte', 'danger'], unknown: ['Não avaliado', ''] };
  const COMPLIANCE = { compliant: ['Conforme', 'ok'], noncompliant: ['Não conforme', 'danger'], ingraceperiod: ['Em período de carência', 'warn'], unknown: ['Desconhecido', ''], configmanager: ['Configuration Manager', 'info'], conflict: ['Conflito', 'warn'], error: ['Erro', 'danger'], remediated: ['Corrigido', 'ok'], notapplicable: ['Não aplicável', ''], notassigned: ['Não atribuído', ''] };
  const OWNERSHIP = { company: 'Corporativo', personal: 'Pessoal', unknown: 'Desconhecido' };
  const ENROLL = {
    userEnrollment: 'Registro pelo usuário', deviceEnrollmentManager: 'Gerenciador de registro (DEM)', appleBulkWithUser: 'Apple ADE (com usuário)', appleBulkWithoutUser: 'Apple ADE (sem usuário)',
    windowsAzureADJoin: 'Windows — ingresso no Entra', windowsBulkUserless: 'Windows — provisionamento em massa', windowsAutoEnrollment: 'Windows — registro automático', windowsBulkAzureDomainJoin: 'Windows — ingresso em massa',
    windowsCoManagement: 'Windows — cogerenciamento (ConfigMgr)', windowsAzureADJoinUsingDeviceAuth: 'Windows — ingresso no Entra (autenticação do dispositivo)', appleUserEnrollment: 'Apple User Enrollment',
    appleUserEnrollmentWithServiceAccount: 'Apple User Enrollment (conta de serviço)', appleAccountDrivenUserEnrollment: 'Apple — registro orientado por conta', azureAdJoinUsingAzureVmExtension: 'VM do Azure (extensão Entra)',
    androidEnterpriseDedicatedDevice: 'Android Enterprise — dedicado', androidEnterpriseFullyManaged: 'Android Enterprise — totalmente gerenciado', androidEnterpriseCorporateWorkProfile: 'Android Enterprise — perfil de trabalho corporativo',
    androidAOSPUserOwnedDeviceEnrollment: 'Android AOSP — com usuário', androidAOSPUserlessDeviceEnrollment: 'Android AOSP — sem usuário', unknown: 'Desconhecido',
  };
  const AGENT = { mdm: 'MDM', eas: 'Exchange ActiveSync', easMdm: 'EAS + MDM', intuneClient: 'Agente do Intune (PC)', configurationManagerClient: 'Configuration Manager', configurationManagerClientMdm: 'Configuration Manager + MDM (cogerenciado)', configurationManagerClientMdmEas: 'Configuration Manager + MDM + EAS', jamf: 'Jamf', googleCloudDevicePolicyController: 'Google (Chrome Enterprise)', microsoft365ManagedMdm: 'Microsoft 365 (MDM básico)', msSense: 'Defender for Endpoint (gerenciamento de segurança)', intuneAosp: 'Intune (AOSP)', unknown: 'Desconhecido' };
  const isoDay = fmtDay;
  const supportNote = (n) => (n && n.tpl ? t(n.tpl, { a: n.a ? isoDay(n.a) : '', b: n.b ? isoDay(n.b) : '', c: n.c ? isoDay(n.c) : '', os: n.os || '', min: n.min ?? '' }) : '');
  const compliance = (c) => COMPLIANCE[String(c || '').toLowerCase()] || (c ? [c, ''] : null);
  const hasComplianceIssue = (r) => !!r.intuneId && ['noncompliant', 'error', 'conflict'].includes(String(r.compliance || '').toLowerCase());
  function compliancePanel(params) {
    const stateLabel = (state) => (compliance(state) || ['—'])[0];
    return reportPanel('device_compliance', params, 'Motivos de não conformidade', (p) => DataTable({
      name: 'device-compliance', sheet: 'Conformidade por política', rows: p.rows, emptyText: 'O Intune não retornou uma política ou configuração com falha para este dispositivo.',
      columns: [
        { key: 'policy', label: 'Política', wrap: true, render: (row) => raw(row.policy || '—') },
        { key: 'policyState', label: 'Estado da política', render: (row) => stateLabel(row.policyState), export: (row) => stateLabel(row.policyState) },
        { key: 'setting', label: 'Configuração', wrap: true, render: (row) => row.setting ? raw(row.setting) : t('Não detalhada pelo Intune') },
        { key: 'state', label: 'Estado informado', render: (row) => badge(...(compliance(row.state) || ['—', ''])), export: (row) => stateLabel(row.state) },
        { key: 'instance', label: 'Instância', wrap: true, render: (row) => raw(row.instance || '—') }, { key: 'description', label: 'Descrição do erro', wrap: true, render: (row) => raw(row.description || '—') }, { key: 'code', label: 'Código do erro' },
      ],
    }), { icon: 'shield', subtitle: 'Estados publicados pelo Intune para o dispositivo selecionado. Algumas políticas informam apenas o estado geral.' });
  }
  function complianceControl(r) {
    if (!hasComplianceIssue(r)) return null;
    if (!App.me.reports.includes('device_compliance')) return h('p', { class: 'muted small' }, 'Para consultar os motivos, o operador pode ativar Detalhes de conformidade do Intune nas configurações do WordPress.');
    const host = h('div');
    const button = h('button', { class: 'btn sm', type: 'button', onclick: () => host.replaceChildren(compliancePanel({ id: r.intuneId, name: r.name || '' })) }, 'Consultar políticas e configurações');
    host.append(button);
    return host;
  }
  function deviceDetail(r) {
    const sup = SUPPORT[r.support] || SUPPORT.unknown;
    // Remote actions for this device (only those Microsoft documents for its platform).
    const acts = r.intuneId && Actions.info('intune').enabled ? Object.entries(DEV_ACTIONS).filter(([k]) => !devWhy(k, r)) : [];
    const can = Actions.can('intune');
    const bar = acts.length ? h('div', { class: 'detail-actions' }, h('span', { class: 'seg-label' }, 'Ações remotas'),
      ...acts.map(([k, A]) => h('button', { type: 'button', class: 'btn sm' + (A.tone === 'warn' ? ' warn' : ''), disabled: !can, title: can ? t(A.desc) : t('Requer {s} (atribuição ativa).', { s: (Actions.info('intune').roles || []).join(', ') }), onclick: () => deviceActionDialog([r], k) }, icon(A.icon), A.label))) : null;
    modal(r.name || r.serial || r.id, h('div', null, complianceControl(r), bar, h('div', { class: 'grid2' },
      h('div', null, h('h3', null, 'Sistema'), kv([['Plataforma', PLATFORM[r.platform] || r.platform], ['Sistema operacional', raw(r.os || '—')], ['Versão', raw(r.osVersion || '—')], ['Release', raw(r.osRelease || '—')], ['Suporte', badge(sup[0], sup[1])], ['Fim do suporte', isoDay(r.supportEnd)], ['Observação', supportNote(r.supportNote) || '—']])),
      h('div', null, h('h3', null, 'Identidade (Entra ID)'), kv([['Classificação', r.classification], ['Ativo', yesNo(r.enabled)], ['Sincronizado do AD', yesNo(r.syncedFromAd)], ['Propriedade', OWNERSHIP[String(r.ownership || '').toLowerCase()] || r.ownership], ['Registrado em', fmtDate(r.registered)], ['Último login (Entra)', fmtDate(r.lastSignInEntra)], ['Dias sem login', r.daysSinceEntra ?? '—'], ['Device ID', raw(r.deviceId || '—')]])),
      h('div', null, h('h3', null, 'Gerenciamento (Intune)'), kv([['No Intune', yesNo(r.inIntune)], ['Conformidade', r.compliance ? badge(...compliance(r.compliance)) : '—'], ['Último sync', fmtDate(r.lastSyncIntune)], ['Dias sem sync', r.daysSinceSync ?? '—'], ['Usuário primário', raw(r.primaryUser || '—')], ['Agente de gerenciamento', AGENT[r.managementAgent] || r.managementAgent], ['Tipo de registro', ENROLL[r.enrollmentType] || r.enrollmentType], ['Registrado no Intune', fmtDate(r.enrolled)], ['Categoria', r.category]])),
      h('div', null, h('h3', null, 'Hardware e segurança'), kv([['Fabricante', raw(r.manufacturer || '—')], ['Modelo', raw(r.model || '—')], ['Serial', raw(r.serial || '—')], ['Criptografado', r.encrypted === null ? '—' : yesNo(r.encrypted)], ['Supervisionado', r.supervised === null || r.supervised === undefined ? '—' : yesNo(r.supervised)], ['Autopilot', r.autopilot === null ? '—' : yesNo(r.autopilot)], ['Perfil Autopilot', raw(r.autopilotProfile || '—')]])))));
  }
  views.devices = () => {
    const wrap = h('div');
    const days = h('input', { type: 'number', min: 1, max: 730, value: App.params.devStale || App.me.defaults.stale_device_days, class: 'j365-input', 'aria-label': 'Obsoleto após (dias sem login no Entra e sem sync no Intune)' });
    const form = h('form', { class: 'rp-form', title: t('Obsoleto após (dias sem login no Entra e sem sync no Intune)'), onsubmit: (e) => { e.preventDefault(); draw(); } }, h('label', null, h('span', null, 'Obsoleto após'), days, h('span', null, 'dias')), h('button', { class: 'btn sm glass', type: 'submit' }, 'Aplicar'));
    const out = wrap;
    function draw() {
      out.innerHTML = '';
      App.params.devStale = +days.value;
      out.append(reportPanel('devices', { stale_days: +days.value }, 'Inventário de dispositivos (Entra ID + Intune)', (p) => {
        const s = p.summary, box = h('div');
        const by = s.bySupport || {};
        const intune = !!p._meta.intune;
        const syncStale = (r) => r.inIntune === true && (r.daysSinceSync == null || r.daysSinceSync > p._meta.stale_days);
        // Results from a job started before 1.4 can still be opened in this tab.
        for (const row of p.rows) {
          if (row.osBuild === undefined) {
            const version = String(row.osVersion || '');
            row.osBuild = ['windows', 'windowsServer'].includes(row.platform) ? (version.match(/^\s*\d+\.\d+\.(\d+)(?:\.|$)/)?.[1] || version.match(/\((\d+)\)/)?.[1] || '') : '';
          }
        }
        const isWin10 = (r) => /^Windows 10 /.test(r.osRelease || '') && !/LTS[BC]/.test(r.osRelease || '');
        const cols = [
          { key: 'name', label: 'Nome' },
          { key: 'compliance', label: 'Conformidade', render: (r) => {
            const value = r.compliance ? badge(...compliance(r.compliance)) : '—';
            if (!hasComplianceIssue(r) || !App.me.reports.includes('device_compliance')) return value;
            return h('span', null, value, ' ', h('button', { class: 'btn sm', type: 'button', onkeydown: (e) => e.stopPropagation(), onclick: (e) => { e.stopPropagation(); modal(r.name || r.intuneId, compliancePanel({ id: r.intuneId, name: r.name || '' })); } }, 'Ver motivos'));
          }, export: (r) => r.compliance ? compliance(r.compliance)[0] : '' },
          { key: 'platform', label: 'Plataforma', render: (r) => PLATFORM[r.platform] || r.platform, export: (r) => PLATFORM[r.platform] || r.platform },
          { key: 'osRelease', label: 'Release', render: (r) => raw(r.osRelease || '—') },
          { key: 'osVersion', label: 'Versão do SO' }, { key: 'osBuild', label: 'Build do Windows', sortValue: (r) => r.osBuild ? Number(r.osBuild) : null },
          { key: 'support', label: 'Suporte', render: (r) => { const x = SUPPORT[r.support] || SUPPORT.unknown; const b = badge(x[0], x[1]); const n = supportNote(r.supportNote); if (n) b.title = n; return b; }, export: (r) => (SUPPORT[r.support] || SUPPORT.unknown)[0], sortValue: (r) => ({ unsupported: 0, ending: 1, entOnly: 2, supported: 3, unknown: 4 }[r.support] ?? 5) },
          { key: 'supportEnd', label: 'Fim do suporte', render: (r) => isoDay(r.supportEnd), export: (r) => r.supportEnd },
          { key: 'supportNote', label: 'Observação de suporte', exportOnly: true, export: (r) => supportNote(r.supportNote) },
          { key: 'classification', label: 'Classificação', render: (r) => badge(r.classification.split(' (')[0], { 'Híbrido': 'info', 'Cloud-only': 'ok', 'Registrado': 'warn' }[r.classification.split(' (')[0]] || '') },
          { key: 'stale', label: 'Obsoleto', render: (r) => bool(r.stale, false) },
          { key: 'lastSignInEntra', label: 'Último login (Entra)', render: (r) => fmtDate(r.lastSignInEntra), export: (r) => r.lastSignInEntra }, { key: 'daysSinceEntra', label: 'Dias (Entra)' },
          { key: 'inIntune', label: 'Intune', render: (r) => bool(r.inIntune) },
          { key: 'lastSyncIntune', label: 'Último sync (Intune)', render: (r) => fmtDate(r.lastSyncIntune), export: (r) => r.lastSyncIntune }, { key: 'primaryUser', label: 'Usuário primário' },
          { key: 'encrypted', label: 'Criptografado', render: (r) => r.encrypted === null ? '—' : bool(r.encrypted) },
          { key: 'ownership', label: 'Propriedade', render: (r) => OWNERSHIP[String(r.ownership || '').toLowerCase()] || r.ownership || '—', export: (r) => OWNERSHIP[String(r.ownership || '').toLowerCase()] || r.ownership },
          { key: 'enabled', label: 'Ativo', render: (r) => bool(r.enabled) }, { key: 'autopilot', label: 'Autopilot', render: (r) => r.autopilot === null ? '—' : bool(r.autopilot) },
          { key: 'manufacturer', label: 'Fabricante' }, { key: 'model', label: 'Modelo' }, { key: 'serial', label: 'Serial' },
          { key: 'registered', label: 'Registrado em', render: (r) => fmtDay(r.registered), export: (r) => r.registered },
          { key: 'os', label: 'SO (informado)', exportOnly: true }, { key: 'deviceId', label: 'Device ID', exportOnly: true },
          { key: 'trustType', label: 'Tipo de ingresso (trustType)', exportOnly: true }, { key: 'joinType', label: 'Tipo de ingresso (Intune)', exportOnly: true },
          { key: 'syncedFromAd', label: 'Sincronizado do AD', exportOnly: true, export: (r) => (r.syncedFromAd === null || r.syncedFromAd === undefined ? null : !!r.syncedFromAd) },
          { key: 'compliantEntra', label: 'Conforme (Entra)', exportOnly: true, export: (r) => (r.compliantEntra === null || r.compliantEntra === undefined ? null : !!r.compliantEntra) },
          { key: 'managedEntra', label: 'Gerenciado (Entra)', exportOnly: true, export: (r) => (r.managedEntra === null || r.managedEntra === undefined ? null : !!r.managedEntra) },
          { key: 'managementAgent', label: 'Agente de gerenciamento', exportOnly: true, export: (r) => (r.managementAgent ? AGENT[r.managementAgent] || r.managementAgent : '') },
          { key: 'managementType', label: 'Tipo de gerenciamento', exportOnly: true },
          { key: 'enrollmentType', label: 'Tipo de registro', exportOnly: true, export: (r) => (r.enrollmentType ? ENROLL[r.enrollmentType] || r.enrollmentType : '') },
          { key: 'enrolled', label: 'Registrado no Intune em', exportOnly: true, export: (r) => r.enrolled },
          { key: 'daysSinceSync', label: 'Dias sem sync (Intune)', exportOnly: true },
          { key: 'supervised', label: 'Supervisionado', exportOnly: true, export: (r) => (r.supervised === null || r.supervised === undefined ? null : !!r.supervised) },
          { key: 'autopilotProfile', label: 'Perfil Autopilot', exportOnly: true }, { key: 'profileType', label: 'Tipo de perfil', exportOnly: true },
          { key: 'category', label: 'Categoria', exportOnly: true }, { key: 'intuneId', label: 'ID no Intune', exportOnly: true },
        ];
        const table = DataTable({ rows: p.rows, columns: cols, name: 'dispositivos', onRowClick: deviceDetail,
          facets: [{ key: 'osRelease', label: 'Release' }, { key: 'osVersion', label: 'Versão do SO' }, { key: 'osBuild', label: 'Build do Windows' }],
          segments: platformSegments(App.params.devPlatform || 'all', (id) => { App.params.devPlatform = id; }),
          filters: [
            { id: 'unsup', label: 'Fora de suporte', fn: (r) => r.support === 'unsupported' }, { id: 'ending', label: 'Suporte terminando (≤ 90 dias)', fn: (r) => r.support === 'ending' }, { id: 'entonly', label: 'Só Enterprise/Education', fn: (r) => r.support === 'entOnly' }, { id: 'win10', label: 'Windows 10', fn: isWin10 },
            { id: 'stale', label: 'Obsoletos', fn: (r) => r.stale }, { id: 'hybrid', label: 'Híbridos', fn: (r) => r.trustType === 'ServerAd' }, { id: 'cloud', label: 'Cloud-only (Entra joined)', fn: (r) => r.trustType === 'AzureAd' }, { id: 'reg', label: 'Registrados (BYOD)', fn: (r) => r.trustType === 'Workplace' },
            intune && { id: 'intune', label: 'No Intune', fn: (r) => r.inIntune === true }, intune && { id: 'nointune', label: 'Fora do Intune', fn: (r) => r.inIntune === false }, intune && { id: 'noncomp', label: 'Não conformes', fn: (r) => String(r.compliance).toLowerCase() === 'noncompliant' }, intune && { id: 'unenc', label: 'Sem criptografia', fn: (r) => r.encrypted === false }, intune && { id: 'nosync', label: 'Sem sync Intune', fn: syncStale },
            { id: 'personal', label: 'Pessoais', fn: (r) => String(r.ownership).toLowerCase() === 'personal' }, { id: 'disabled', label: 'Desabilitados', fn: (r) => r.enabled === false }, intune && { id: 'orphan', label: 'Somente Intune (órfãos)', fn: (r) => String(r.id).startsWith('intune:') },
          ] });
        box.append(kpis([
          { label: 'Dispositivos no inventário', value: p.rows.length }, { label: 'Total (Entra)', value: s.total }, { label: 'Fora de suporte', value: by.unsupported || 0, cls: by.unsupported ? 'danger' : 'ok', onclick: () => table.setFilter('unsup') },
          { label: 'Suporte terminando', value: by.ending || 0, cls: by.ending ? 'warn' : '', onclick: () => table.setFilter('ending'), title: 'Fim do suporte em até 90 dias.' },
          { label: 'Só Enterprise/Education', value: by.entOnly || 0, cls: by.entOnly ? 'warn' : '', onclick: () => table.setFilter('entonly'), title: 'Versões do Windows 11 que só seguem com suporte nas edições Enterprise/Education.' },
          { label: 'Windows 10', value: p.rows.filter(isWin10).length, cls: p.rows.some(isWin10) ? 'danger' : 'ok', onclick: () => table.setFilter('win10') },
          { label: 'Híbridos', value: s.hybrid, onclick: () => table.setFilter('hybrid') }, { label: 'Cloud-only', value: s.entraJoined, cls: 'ok', onclick: () => table.setFilter('cloud') }, { label: 'Registrados', value: s.registered, onclick: () => table.setFilter('reg') },
          { label: t('Obsoletos (> {n} dias)', { n: p._meta.stale_days }), value: s.stale, cls: s.stale ? 'danger' : '', onclick: s.stale == null ? null : () => table.setFilter('stale') },
          ...[['No Intune', s.intuneManaged, 'intune', ''], ['Fora do Intune', s.notInIntune, 'nointune', 'warn'], ['Não conformes', s.nonCompliant, 'noncomp', 'danger'], ['Sem criptografia', s.unencrypted, 'unenc', 'warn'], ['Sem sync Intune', s.intuneStale, 'nosync', 'warn'], ['Órfãos Intune', s.intuneOrphans, 'orphan', '']].map(([label, value, id, cls]) => ({ label, value: intune ? value : null, cls: intune && value ? cls : '', onclick: intune ? () => table.setFilter(id) : null })),
        ]));
        const plat = Object.entries(s.byPlatform || {}).sort((a, b) => b[1] - a[1]).map(([k, v]) => [PLATFORM[k] || k, v, () => table.setSegment(k === 'ipados' ? 'ios' : k)]);
        const rel = Object.entries(s.byRelease || {}).sort((a, b) => b[1] - a[1]).map(([k, v]) => [raw(k), v, () => table.setMatch('osRelease', k, k)]);
        const supp = [['Suportado', by.supported || 0, null, 'ok'], ['Termina em breve', by.ending || 0, () => table.setFilter('ending'), 'warn'], ['Só Enterprise/Education', by.entOnly || 0, () => table.setFilter('entonly'), '#e8b04b'], ['Fora de suporte', by.unsupported || 0, () => table.setFilter('unsup'), 'danger'], ['Não avaliado', by.unknown || 0, null, 'muted']];
        box.append(vizRow(
          vizCard('Por plataforma', donut(plat, { total: p.rows.length, centerLabel: t('dispositivos'), key: 'platform' })),
          vizCard('Suporte do sistema', donut(supp, { centerLabel: t('dispositivos'), key: 'support' })),
          vizCard('Por versão do sistema', barList(rel, p.rows.length, { limit: 10, key: 'osRelease' }))));
        if (by.unsupported || by.ending || by.entOnly) box.append(h('div', { class: 'alert warn' }, 'Confira a edição e a data de fim de suporte antes de planejar as atualizações.'));
        if (p._meta.previousEnrollments) box.append(h('p', { class: 'muted small' }, t('Registros de inscrição anteriores agrupados pelo ID do dispositivo no Entra: {n}. O inventário usa a inscrição com sincronização mais recente.', { n: num(p._meta.previousEnrollments) })));
        box.append(table);
        box.append(h('p', { class: 'muted small foot-note' }, t('Ciclo de vida conforme Microsoft (Windows release health, Lifecycle) e sistemas suportados pelo Intune, verificado em {s}. Edição do Windows nem sempre é informada: quando ausente, vale a data Enterprise/Education.', { s: isoDay(p._meta.lifecycleAsOf || '2026-11-01') })));
        return box;
      }, { subtitle: 'Consulte os dispositivos do Entra ID e do Intune. Filtre por versão ou build e abra um dispositivo para ver suas políticas de conformidade.', controls: form, ring: (p) => {
        const mdm = p.rows.filter((r) => r.inIntune);
        if (mdm.length) { const ok = mdm.filter((r) => String(r.compliance).toLowerCase() === 'compliant').length, pc = pctOf(ok, mdm.length); return { pct: pc, label: 'dos dispositivos do Intune em conformidade', caption: t('{a} de {b} dispositivos', { a: num(ok), b: num(mdm.length) }), tone: toneFor(pc, 90, 75) }; }
        const assessed = p.rows.filter((r) => ['supported', 'ending', 'unsupported'].includes(r.support));
        const bad = assessed.filter((r) => r.support === 'unsupported').length, pc = pctOf(assessed.length - bad, assessed.length);
        return assessed.length ? { pct: pc, label: 'com suporte entre os dispositivos avaliados', caption: t('{a} de {b} dispositivos', { a: num(assessed.length - bad), b: num(assessed.length) }), tone: toneFor(pc, 95, 85) } : null;
      } }));
    }
    draw();
    return wrap;
  };

  /* Apps */
  views.apps = () => reportPanel('apps', {}, 'Aplicativos descobertos e homologação', (p) => {
    const s = p.summary, box = h('div');
    const cols = [
      { key: 'name', label: 'Aplicativo' }, { key: 'publisher', label: 'Publisher' }, { key: 'platform', label: 'Plataforma' },
      { key: 'approved', label: 'Homologado', render: (r) => r.approvalKnown === false ? badge('Indeterminado', 'warn') : r.approved ? badge('Sim', 'ok') : badge('Não', 'danger'), export: (r) => r.approvalKnown === false ? t('Indeterminado') : r.approved }, { key: 'approvedBy', label: 'Critério', wrap: true },
      { key: 'deviceCount', label: 'Dispositivos', render: (r) => num(r.deviceCount) }, { key: 'versionCount', label: 'Versões' }, { key: 'latestVersion', label: 'Versão mais recente' },
      { key: 'versions', label: 'Versões (dispositivos)', render: (r) => list(r.versions.map((v) => `${v.version} (${v.devices})`), 3), export: (r) => r.versions.map((v) => `${v.version} (${v.devices})`), wrap: true },
    ];
    const table = DataTable({ rows: p.rows, columns: cols, name: 'aplicativos', onRowClick: (r) => appDetail(r), filters: [
      { id: 'notapproved', label: 'Não homologados', fn: (r) => !r.approved && r.approvalKnown !== false }, { id: 'approved', label: 'Homologados', fn: (r) => r.approved }, { id: 'multi', label: 'Múltiplas versões', fn: (r) => r.versionCount > 1 },
      s.unknown ? { id: 'unknown', label: 'Indeterminado', fn: (r) => r.approvalKnown === false } : null,
      { id: 'win', label: 'Windows', fn: (r) => r.platform === 'windows' }, { id: 'mac', label: 'macOS', fn: (r) => r.platform === 'macOS' }, { id: 'ios', label: 'iOS', fn: (r) => r.platform === 'iOS' }, { id: 'android', label: 'Android', fn: (r) => /android/i.test(r.platform) },
    ] });
    box.append(kpis([
      { label: 'Aplicativos únicos', value: s.apps }, { label: 'Entradas (app+versão)', value: s.entries }, { label: 'Homologados', value: s.approved, cls: 'ok', onclick: () => table.setFilter('approved') }, { label: 'Não homologados', value: s.notApproved, cls: 'danger', onclick: () => table.setFilter('notapproved') },
      { label: 'Via Intune', value: s.approvedIntune }, { label: 'Via allowlist', value: s.approvedAllowlist }, { label: 'Via publisher', value: s.approvedPublisher }, { label: 'Múltiplas versões', value: s.multiVersion, cls: 'warn', onclick: () => table.setFilter('multi') }, { label: 'Catálogo Intune', value: s.intuneCatalog },
    ]));
    box.append(h('div', { class: 'alert' }, 'Homologação por nome no catálogo publicado do Intune ', p.settings.auto_approve_intune_apps ? badge('ativo', 'ok') : badge('desativado', 'warn'), ' · (2) regra na allowlist do tenant · (3) publisher Microsoft ', p.settings.trust_microsoft_publisher ? badge('ativo', 'ok') : badge('desativado', 'warn'), '. Ajuste em Configurações.'));
    if (p.rows.length) {
      box.append(vizRow(
        vizCard('Homologação', donut([['Homologados', s.approved, () => table.setFilter('approved'), 'ok'], ['Não homologados', s.notApproved, () => table.setFilter('notapproved'), 'danger']], { centerLabel: t('aplicativos'), key: 'approved' })),
        vizCard('Por plataforma', donut(countBy(p.rows, (r) => r.platform, { limit: 6 }).map(([l, v]) => [l, v]), { centerLabel: t('aplicativos'), key: 'platform' })),
        vizCard('Mais instalados', barList(p.rows.slice(0, 8).map((r) => [raw(r.name), r.deviceCount, () => appDetail(r)]), 0))));
    }
    box.append(table);
    return box;
  }, { subtitle: 'Software detectado nos dispositivos. Selecione um aplicativo para consultar suas versões e instalações.', ring: (p) => {
    const s = p.summary, pc = pctOf(s.approved, s.apps);
    return s.apps && !s.unknown ? { pct: pc, label: 'dos aplicativos homologados', caption: t('{n} sem homologação', { n: num(s.notApproved) }), tone: toneFor(pc, 90, 70) } : null;
  } });

  function appDetail(r) {
    const body = h('div');
    body.append(kv([['Publisher', r.publisher], ['Plataforma', r.platform], ['Homologado', r.approvalKnown === false ? t('Indeterminado') : r.approved ? t('Sim') + ' — ' + t(r.approvedBy) : t('Não')], ['Dispositivos (soma das versões)', num(r.deviceCount)]]));
    body.append(h('h3', { style: 'margin-top:14px' }, 'Versões'));
    body.append(DataTable({ rows: r.versions, name: 'app-versoes', sheet: 'Versões', pageSize: 10, compact: true, columns: [
      { key: 'version', label: 'Versão' }, { key: 'devices', label: 'Dispositivos' },
      { key: 'id', label: 'ID', exportOnly: true },
      { key: 'detail', render: (v) => h('button', { type: 'button', class: 'btn sm', onclick: () => loadDevices([v.id], r.name + ' ' + v.version) }, 'Dispositivos') },
    ] }));
    const batches = [], versionIds = r.versions.map((v) => v.id);
    for (let i = 0; i < versionIds.length; i += 50) {
      const ids = versionIds.slice(i, i + 50);
      const label = versionIds.length <= 50 ? t('Listar todos os dispositivos com este app') : t('Dispositivos das versões {a}–{b}', { a: i + 1, b: i + ids.length });
      batches.push(h('button', { class: 'btn primary', type: 'button', onclick: () => loadDevices(ids, versionIds.length <= 50 ? r.name : r.name + ' · ' + label) }, raw(label)));
    }
    const actions = h('div', { class: 'actions' },
      ...batches,
      !r.approved && App.me.user?.can?.manageApps ? h('button', { class: 'btn', onclick: async () => { try { await api('allowlist', { method: 'POST', body: { app_name: r.name, publisher: '', platform: r.platform, match_type: 'exact', notes: 'Adicionado pelo relatório' } }); delete App.cache['apps{}']; m.remove(); App.go('apps'); } catch (e) { alert(e.message); } } }, 'Homologar (adicionar à allowlist)') : null
    );
    body.append(actions);
    const devOut = h('div');
    body.append(devOut);
    const m = modal(r.name, body);
    function loadDevices(ids, label) {
      devOut.replaceChildren(appDevicesPanel(ids, label));
    }
  }

  function appDevicesPanel(ids, label) {
      const own = (x) => OWNERSHIP[String(x.ownership || '').toLowerCase()] || x.ownership || '';
      const cmp = (x) => String(x.compliance || '').toLowerCase();
      return reportPanel('app_devices', { ids, name: label }, t('Dispositivos com {s}', { s: label }), (p) => DataTable({ rows: p.rows, columns: [
        { key: 'name', label: 'Dispositivo' }, { key: 'user', label: 'Usuário' }, { key: 'os', label: 'SO' }, { key: 'osVersion', label: 'Versão SO' },
        { key: 'compliance', label: 'Conformidade', render: (x) => { const c = compliance(x.compliance); return c ? badge(c[0], c[1]) : '—'; }, export: (x) => (compliance(x.compliance) || [''])[0] },
        { key: 'lastSync', label: 'Último sync', render: (x) => fmtDate(x.lastSync), export: (x) => x.lastSync },
        { key: 'ownership', label: 'Propriedade', render: (x) => own(x) || '—', export: own },
        { key: 'model', label: 'Modelo', exportOnly: true }, { key: 'serial', label: 'Serial', exportOnly: true },
        { key: 'enrollmentType', label: 'Tipo de registro', exportOnly: true, export: (x) => (x.enrollmentType ? ENROLL[x.enrollmentType] || x.enrollmentType : '') },
      ], filters: [
        { id: 'noncompliant', label: 'Não conformes', fn: (x) => cmp(x) === 'noncompliant' }, { id: 'compliant', label: 'Conformes', fn: (x) => cmp(x) === 'compliant' }, { id: 'personal', label: 'Pessoais', fn: (x) => String(x.ownership || '').toLowerCase() === 'personal' },
      ], name: `app-${label.replace(/[^a-z0-9]+/gi, '_')}`, sheet: 'Dispositivos com o app', pageSize: 25 }), { icon: 'device' });
  }

  const APP_REPORT_TITLES = { app_assignments: 'Atribuições do aplicativo', app_failures: 'Dispositivos com falhas' };
  const INTUNE_INTENTS = { required: 'Obrigatória', available: 'Disponível', availableWithoutEnrollment: 'Disponível sem registro', uninstall: 'Desinstalação' };
  const INTUNE_TARGETS = {
    groupAssignmentTarget: 'Grupo incluído', exclusionGroupAssignmentTarget: 'Grupo excluído', allLicensedUsersAssignmentTarget: 'Todos os usuários licenciados',
    allDevicesAssignmentTarget: 'Todos os dispositivos', configurationManagerCollectionAssignmentTarget: 'Coleção do Configuration Manager',
  };
  const INTUNE_PUBLISHING = { published: 'Publicado', processing: 'Em processamento', notPublished: 'Não publicado' };

  function managedAppPanel(type, params) {
    const global = type === 'intune_assignments';
    const assignments = global || type === 'app_assignments';
    const title = global ? 'Atribuições do catálogo' : APP_REPORT_TITLES[type];
    const coverage = { assignment: 'Atribuição confirmada', none: 'Sem atribuições', unavailable: 'Consulta incompleta' };
    const label = (values, key) => t(values[key] || key || 'Indeterminado');
    return reportPanel(type, params, title, (p) => {
      const columns = assignments ? [
        ...(global ? [{ key: 'appName', label: 'Aplicativo' }, { key: 'appId', label: 'ID do aplicativo' }, { key: 'coverage', label: 'Cobertura', value: (r) => label(coverage, r.coverage) }] : []),
        { key: 'intent', label: 'Intenção', value: (r) => r.coverage === 'none' ? '—' : label(INTUNE_INTENTS, r.intent) },
        { key: 'target', label: 'Alvo', value: (r) => r.coverage === 'none' ? '—' : label(INTUNE_TARGETS, r.target) },
        { key: 'groupName', label: 'Nome do grupo', value: (r) => r.groupId && !r.groupName ? t('Nome indisponível') : r.groupName },
        { key: 'groupId', label: 'ID do grupo' }, { key: 'collectionId', label: 'ID da coleção' },
        { key: 'filterType', label: 'Modo do filtro', value: (r) => ({ include: t('Inclusão'), exclude: t('Exclusão'), none: '—' })[r.filterType] || r.filterType },
        { key: 'filterId', label: 'ID do filtro' }, { key: 'id', label: 'ID da atribuição', exportOnly: true },
      ] : [
        { key: 'name', label: 'Dispositivo' }, { key: 'deviceId', label: 'ID do dispositivo' }, { key: 'user', label: 'Usuário' },
        { key: 'status', label: 'Estado', value: (r) => t(r.status === 'failed' ? 'Falha na instalação' : 'Falha na desinstalação') },
        { key: 'code', label: 'Código de erro' }, { key: 'detail', label: 'Detalhes' }, { key: 'version', label: 'Versão' },
        { key: 'modified', label: 'Última atualização', render: (r) => fmtDate(r.modified) },
      ];
      return DataTable({ rows: p.rows, columns, name: global ? type : type + '-' + params.id, sheet: title, pageSize: 25,
        emptyText: global ? 'Nenhum registro.' : assignments ? 'O Intune não retornou atribuições para este aplicativo.' : 'Nenhuma falha identificada nos registros retornados pelo Intune.',
        filters: assignments ? [...Object.entries(INTUNE_INTENTS).map(([id, text]) => ({ id, label: text, fn: (r) => r.intent === id })),
          ...(global ? ['none', 'unavailable'].map((id) => ({ id, label: coverage[id], fn: (r) => r.coverage === id })) : [])] : [],
        facets: global ? [{ key: 'target', label: 'Alvo' }, { key: 'groupName', label: 'Nome do grupo' }] : [],
      });
    }, { icon: assignments ? 'users' : 'device', subtitle: global ? 'Consulte os aplicativos e seus destinos por intenção de implantação. Os grupos excluídos aparecem como alvos separados.' : params.name || params.id });
  }

  views.intune_assignments = () => managedAppPanel('intune_assignments', {});

  views.managed_apps = () => reportPanel('managed_apps', {}, 'Apps gerenciados', (p) => {
    const open = (type, r) => modal(r.name || r.id, managedAppPanel(type, { id: r.id, name: r.name.slice(0, 200) }));
    const appLink = (type, r, text, action) => {
      const title = t(action, { s: r.name || r.id });
      const count = type === 'app_failures';
      const label = count ? text + ' · ' + title : title;
      return h('button', { type: 'button', class: 'dt-drill' + (count && r.failedDevices > 0 ? ' has-failures' : ''), 'aria-label': label, title: label, onclick: () => open(type, r) }, raw(text));
    };
    const table = DataTable({ rows: p.rows, name: 'intune-apps', sheet: 'Apps gerenciados', columns: [
      { key: 'name', label: 'Aplicativo', render: (r) => appLink('app_assignments', r, r.name || r.id, 'Ver atribuições de {s}') },
      { key: 'failedDevices', label: 'Falhas', xlLabel: 'Dispositivos com falhas', sortValue: (r) => r.failedDevices,
        render: (r) => appLink('app_failures', r, r.failedDevices == null ? t('Sem contagem') : num(r.failedDevices), 'Ver falhas de {s}'),
        export: (r) => r.failedDevices ?? t('Sem contagem') },
      { key: 'publisher', label: 'Publisher' },
      { key: 'type', label: 'Tipo' }, { key: 'state', label: 'Publicação', value: (r) => t(INTUNE_PUBLISHING[r.state] || r.state || 'Indeterminado') },
      { key: 'modified', label: 'Última atualização', render: (r) => fmtDate(r.modified) },
      { key: 'id', label: 'ID do aplicativo', exportOnly: true },
    ], filters: [
      { id: 'failed', label: 'Com falhas', fn: (r) => r.failedDevices > 0 },
      { id: 'zero', label: 'Zero falhas', fn: (r) => r.failedDevices === 0 },
      { id: 'unknown', label: 'Sem contagem', fn: (r) => r.failedDevices == null },
    ] });
    return h('div', null, kpis([
      { label: 'Apps gerenciados', value: p.summary.apps }, { label: 'Apps com falhas confirmadas', value: p.summary.withFailures, onclick: () => table.setFilter('failed') },
      { label: 'Apps sem contagem', value: p.summary.unknown, onclick: () => table.setFilter('unknown') },
    ]), table);
  }, { subtitle: 'Selecione o aplicativo para ver as atribuições ou a contagem de falhas para consultar os dispositivos. As contagens vêm do Microsoft Graph beta.' });

  /* Conditional Access */
  views.ca = () => reportPanel('ca', {}, 'Políticas de Acesso Condicional', (p) => {
    const s = p.summary, box = h('div');
    const cols = [
      { key: 'name', label: 'Política', wrap: true }, { key: 'stateLabel', label: 'Estado', render: (r) => badge(r.stateLabel, { enabled: 'ok', disabled: 'danger', enabledForReportingButNotEnforced: 'warn' }[r.state]) },
      { key: 'includeUsers', label: 'Usuários incl.', render: (r) => list([...r.includeUsers, ...r.includeGroups.map((g) => 'G: ' + g), ...r.includeRoles.map((x) => 'R: ' + x), r.includeGuests && 'Convidados: ' + r.includeGuests].filter(Boolean), 3), export: (r) => [...r.includeUsers, ...r.includeGroups.map((g) => 'G: ' + g), ...r.includeRoles.map((x) => 'R: ' + x)], wrap: true },
      { key: 'excludeUsers', label: 'Exclusões', render: (r) => list([...r.excludeUsers, ...r.excludeGroups.map((g) => 'G: ' + g), ...r.excludeRoles.map((x) => 'R: ' + x), r.excludeGuests && 'Convidados: ' + r.excludeGuests].filter(Boolean), 3), export: (r) => [...r.excludeUsers, ...r.excludeGroups.map((g) => 'G: ' + g), ...r.excludeRoles.map((x) => 'R: ' + x)], wrap: true },
      { key: 'includeApps', label: 'Aplicativos', render: (r) => list([...r.includeApps, ...r.userActions, ...r.authContexts], 3), wrap: true },
      { key: 'grantControls', label: 'Controles (grant)', render: (r) => list(r.grantControls, 3) + (r.grantControls.length > 1 ? ` [${r.grantOperator}]` : ''), wrap: true },
      { key: 'sessionControls', label: 'Sessão', render: (r) => list(r.sessionControls, 2), wrap: true },
      { key: 'clientAppTypes', label: 'Client apps', render: (r) => list(r.clientAppTypes, 2) }, { key: 'platformsInc', label: 'Plataformas', render: (r) => list(r.platformsInc, 3) }, { key: 'locationsInc', label: 'Locais', render: (r) => list(r.locationsInc, 2) + (r.locationsExc.length ? ` (excl: ${list(r.locationsExc, 2)})` : '') },
      { key: 'signInRisk', label: 'Risco login', render: (r) => list(r.signInRisk) }, { key: 'userRisk', label: 'Risco usuário', render: (r) => list(r.userRisk) }, { key: 'modified', label: 'Modificada', render: (r) => fmtDate(r.modified || r.created), export: (r) => r.modified },
      { key: 'includeGroups', label: 'Grupos incluídos', exportOnly: true }, { key: 'includeRoles', label: 'Funções incluídas', exportOnly: true }, { key: 'includeGuests', label: 'Convidados incluídos', exportOnly: true },
      { key: 'excludeGroups', label: 'Grupos excluídos', exportOnly: true }, { key: 'excludeRoles', label: 'Funções excluídas', exportOnly: true }, { key: 'excludeGuests', label: 'Convidados excluídos', exportOnly: true },
      { key: 'excludeApps', label: 'Aplicativos excluídos', exportOnly: true }, { key: 'userActions', label: 'Ações de usuário', exportOnly: true }, { key: 'authContexts', label: 'Authentication context', exportOnly: true },
      { key: 'grantOperator', label: 'Operador (grant)', exportOnly: true }, { key: 'platformsExc', label: 'Plataformas excluídas', exportOnly: true }, { key: 'locationsExc', label: 'Locais excluídos', exportOnly: true },
      { key: 'deviceFilter', label: 'Filtro de dispositivos', exportOnly: true }, { key: 'insiderRisk', label: 'Risco interno', exportOnly: true },
      { key: 'created', label: 'Criada', exportOnly: true, export: (r) => r.created }, { key: 'id', label: 'ID da política', exportOnly: true },
    ];
    const table = DataTable({ rows: p.rows, columns: cols, name: 'acesso-condicional', onRowClick: (r) => modal(r.name, h('div', null, kv([['Estado', r.stateLabel], ['Criada', fmtDate(r.created)], ['Modificada', fmtDate(r.modified)], ['ID', r.id]]), h('div', { class: 'grid2', style: 'margin-top:12px' },
      h('div', null, h('h3', null, 'Atribuições'), kv([['Usuários incluídos', r.includeUsers], ['Grupos incluídos', r.includeGroups], ['Funções incluídas', r.includeRoles], ['Convidados incluídos', r.includeGuests], ['Usuários excluídos', r.excludeUsers], ['Grupos excluídos', r.excludeGroups], ['Funções excluídas', r.excludeRoles], ['Convidados excluídos', r.excludeGuests]])),
      h('div', null, h('h3', null, 'Recursos e condições'), kv([['Aplicativos incluídos', r.includeApps], ['Aplicativos excluídos', r.excludeApps], ['Ações de usuário', r.userActions], ['Authentication context', r.authContexts], ['Client app types', r.clientAppTypes], ['Plataformas', r.platformsInc.length ? r.platformsInc.join(', ') + (r.platformsExc.length ? ` (excl: ${r.platformsExc.join(', ')})` : '') : '—'], ['Locais incluídos', r.locationsInc], ['Locais excluídos', r.locationsExc], ['Risco de login', r.signInRisk], ['Risco de usuário', r.userRisk], ['Risco interno', r.insiderRisk], ['Filtro de dispositivos', r.deviceFilter]])),
      h('div', null, h('h3', null, 'Controles'), kv([['Operador', r.grantOperator], ['Grant', r.grantControls], ['Sessão', r.sessionControls]]))
    ))), filters: [
      { id: 'enabled', label: 'Ativadas', fn: (r) => r.state === 'enabled' }, { id: 'report', label: 'Somente relatório', fn: (r) => r.state === 'enabledForReportingButNotEnforced' }, { id: 'disabled', label: 'Desativadas', fn: (r) => r.state === 'disabled' },
      { id: 'mfa', label: 'Exigem MFA', fn: (r) => r.grantControls.some((c) => /MFA|Strength/.test(c)) }, { id: 'block', label: 'Bloqueio', fn: (r) => r.grantControls.includes('Bloquear') }, { id: 'excl', label: 'Com exclusões', fn: (r) => r.excludeUsers.length || r.excludeGroups.length || r.excludeRoles.length },
      { id: 'risk', label: 'Baseadas em risco', fn: (r) => r.signInRisk.length || r.userRisk.length }, { id: 'legacy', label: 'Legacy auth', fn: (r) => r.clientAppTypes.some((c) => /exchangeActiveSync|other/.test(c)) },
    ] });
    box.append(kpis([
      { label: 'Políticas', value: s.total }, { label: 'Ativadas', value: s.enabled, cls: 'ok', onclick: () => table.setFilter('enabled') }, { label: 'Somente relatório', value: s.reportOnly, cls: 'warn', onclick: () => table.setFilter('report') }, { label: 'Desativadas', value: s.disabled, cls: 'danger', onclick: () => table.setFilter('disabled') },
      { label: 'Exigem MFA (ativas)', value: s.requireMfa }, { label: 'Authentication Strength', value: s.authStrength, cls: 'ok' }, { label: 'Dispositivo conforme', value: s.requireCompliant }, { label: 'Bloqueiam legacy auth', value: s.blockLegacy, cls: s.blockLegacy ? 'ok' : 'danger' }, { label: 'Sem exclusões', value: s.noExclusions },
    ]));
    if (!s.blockLegacy) box.append(h('div', { class: 'alert warn' }, 'Nenhuma política ativa bloqueando autenticação legada (Exchange ActiveSync / Other clients). Recomendação Zero Trust: criar política de bloqueio.'));
    if (!s.authStrength) box.append(h('div', { class: 'alert warn' }, 'Nenhuma política ativa usa Authentication Strength. Considere exigir MFA resistente a phishing para administradores.'));
    if (p.rows.length) {
      const on = p.rows.filter((r) => r.state === 'enabled');
      box.append(vizRow(
        vizCard('Estado das políticas', donut([['Ativadas', s.enabled, () => table.setFilter('enabled'), 'ok'], ['Somente relatório', s.reportOnly, () => table.setFilter('report'), 'warn'], ['Desativadas', s.disabled, () => table.setFilter('disabled'), 'muted']], { centerLabel: t('políticas'), key: 'stateLabel' })),
        vizCard('Controles exigidos (políticas ativas)', barList(countBy(on, (r) => r.grantControls, { empty: 'Sem controle de concessão', label: (k) => t(k) }).map(([l, v]) => [l, v]), on.length))));
    }
    box.append(table);
    return box;
  }, { subtitle: 'Veja a quem cada política se aplica, quais aplicativos ela protege, quem está excluído e quais condições de acesso ela exige.', graphSource: '/identity/conditionalAccess/policies /identity/conditionalAccess/namedLocations /policies/authenticationStrengthPolicies', ring: (p) => {
    const s = p.summary, pc = pctOf(s.enabled, s.total);
    return s.total ? { pct: pc, label: 'das políticas ativadas', caption: t('{n} exigem MFA', { n: num(s.requireMfa) }), tone: s.blockLegacy && s.requireMfa ? toneFor(pc, 70, 40) : 'warn' } : { pct: 0, label: 'das políticas ativadas', caption: t('Nenhuma política de Acesso Condicional'), tone: 'danger' };
  } });

  /* Groups */
  views.groups = () => reportPanel('groups', {}, 'Grupos', (p) => {
    const s = p.summary, box = h('div');
    const cols = [
      { key: 'name', label: 'Grupo', wrap: true }, { key: 'type', label: 'Tipo' }, { key: 'source', label: 'Origem', render: (r) => badge(r.source, r.source === 'Cloud-only' ? 'ok' : 'info') }, { key: 'membership', label: 'Associação' },
      { key: 'members', label: 'Membros', render: (r) => num(r.members) }, { key: 'users', label: 'Usuários', render: (r) => num(r.users) }, { key: 'nestedGroups', label: 'Grupos aninhados', render: (r) => r.nestedGroups ? badge(num(r.nestedGroups), 'warn') : num(r.nestedGroups) }, { key: 'devices', label: 'Dispositivos', render: (r) => num(r.devices) }, { key: 'others', label: 'Outros (SP/contatos)', render: (r) => num(r.others) },
      { key: 'composition', label: 'Composição', render: (r) => badge(r.composition, { 'Vazio': 'danger', 'Misto': 'warn', 'Desconhecido': '' }[r.composition] || 'info') }, { key: 'owners', label: 'Proprietários', render: (r) => r.owners === 0 && r.ownersApplicable !== false ? badge('0', 'danger') : num(r.owners) },
      { key: 'mail', label: 'E-mail' }, { key: 'roleAssignable', label: 'Atribuível a função', render: (r) => r.roleAssignable ? badge('Sim', 'danger') : '—' }, { key: 'isTeam', label: 'Team', render: (r) => r.isTeam ? badge('Sim', 'info') : '—' }, { key: 'visibility', label: 'Visibilidade' }, { key: 'created', label: 'Criado', render: (r) => fmtDay(r.created), export: (r) => r.created }, { key: 'rule', label: 'Regra dinâmica', wrap: true },
      { key: 'description', label: 'Descrição', exportOnly: true }, { key: 'ruleState', label: 'Estado da regra', exportOnly: true },
      { key: 'onPremDomain', label: 'Domínio do AD', exportOnly: true }, { key: 'lastSync', label: 'Último sync do AD', exportOnly: true, export: (r) => r.lastSync },
      { key: 'expiration', label: 'Expiração', exportOnly: true, export: (r) => r.expiration }, { key: 'id', label: 'Object ID', exportOnly: true },
    ];
    const table = DataTable({ rows: p.rows, columns: cols, name: 'grupos', onRowClick: (r) => modal(r.name, kv([['Object ID', r.id], ['Descrição', r.description], ['Tipo', r.type], ['Origem', r.source + (r.onPremDomain ? ` (${r.onPremDomain})` : '')], ['Associação', r.membership], ['Regra', r.rule], ['Estado da regra', r.ruleState], ['Membros totais', num(r.members)], ['Usuários', num(r.users)], ['Grupos aninhados', num(r.nestedGroups)], ['Dispositivos', num(r.devices)], ['Outros', num(r.others)], ['Proprietários', num(r.owners)], ['Composição', r.composition], ['Atribuível a função', yesNo(r.roleAssignable)], ['Team', yesNo(r.isTeam)], ['Visibilidade', r.visibility], ['E-mail', r.mail], ['Criado', fmtDate(r.created)], ['Último sync AD', fmtDate(r.lastSync)], ['Expiração', fmtDate(r.expiration)]])), filters: [
      { id: 'synced', label: 'AD sincronizados', fn: (r) => r.source !== 'Cloud-only' }, { id: 'cloud', label: 'Cloud-only', fn: (r) => r.source === 'Cloud-only' }, { id: 'nested', label: 'Com grupos aninhados', fn: (r) => r.isNested }, { id: 'empty', label: 'Vazios', fn: (r) => r.isEmpty },
      { id: 'users', label: 'Somente usuários', fn: (r) => r.composition === 'Somente usuários' }, { id: 'devices', label: 'Somente dispositivos', fn: (r) => r.composition === 'Somente dispositivos' }, { id: 'mixed', label: 'Mistos', fn: (r) => r.composition === 'Misto' }, { id: 'withobj', label: 'Com objetos', fn: (r) => r.members > 0 },
      { id: 'dynamic', label: 'Dinâmicos', fn: (r) => r.membership === 'Dinâmico' }, { id: 'm365', label: 'Microsoft 365', fn: (r) => r.type === 'Microsoft 365' }, { id: 'sec', label: 'Segurança', fn: (r) => r.type.startsWith('Segurança') }, { id: 'dist', label: 'Distribuição', fn: (r) => r.type === 'Distribuição' }, { id: 'noowner', label: 'Sem proprietário', fn: (r) => r.owners === 0 && r.ownersApplicable !== false }, { id: 'role', label: 'Atribuíveis a função', fn: (r) => r.roleAssignable }, { id: 'teams', label: 'Teams', fn: (r) => r.isTeam },
    ] });
    box.append(kpis([
      { label: 'Grupos', value: s.total }, { label: 'AD sincronizados', value: s.synced, onclick: () => table.setFilter('synced') }, { label: 'Cloud-only', value: s.cloudOnly, cls: 'ok', onclick: () => table.setFilter('cloud') }, { label: 'Microsoft 365', value: s.m365, onclick: () => table.setFilter('m365') }, { label: 'Segurança', value: s.security + s.mailSecurity, onclick: () => table.setFilter('sec') }, { label: 'Distribuição', value: s.distribution, onclick: () => table.setFilter('dist') },
      { label: 'Dinâmicos', value: s.dynamic, onclick: () => table.setFilter('dynamic') }, { label: 'Com aninhamento', value: s.nested, cls: 'warn', onclick: () => table.setFilter('nested') }, { label: 'Vazios', value: s.empty, cls: 'danger', onclick: () => table.setFilter('empty') }, { label: 'Só usuários', value: s.usersOnly, onclick: () => table.setFilter('users') }, { label: 'Só dispositivos', value: s.devicesOnly, onclick: () => table.setFilter('devices') }, { label: 'Mistos', value: s.mixed, onclick: () => table.setFilter('mixed') }, { label: 'Sem proprietário', value: s.noOwner, cls: 'warn', onclick: () => table.setFilter('noowner') }, { label: 'Atribuíveis a função', value: s.roleAssignable, cls: 'danger', onclick: () => table.setFilter('role') }, { label: 'Teams', value: s.teams, onclick: () => table.setFilter('teams') },
    ]));
    if (p.rows.length) {
      box.append(vizRow(
        vizCard('Por tipo', donut(countBy(p.rows, (r) => r.type, { limit: 5, label: (k) => t(k) }).map(([l, v, k]) => [l, v, k && k !== '\u0000' ? () => table.setMatch('type', k, k) : null]), { centerLabel: t('grupos'), key: 'type' })),
        vizCard('Origem', donut([['AD sincronizado', s.synced, () => table.setFilter('synced')], ['Cloud-only', s.cloudOnly, () => table.setFilter('cloud')]], { centerLabel: t('grupos'), key: 'source' })),
        vizCard('Composição', donut(countBy(p.rows, (r) => r.composition, { limit: 6, label: (k) => t(k) }).map(([l, v, k]) => [l, v, k && k !== '\u0000' ? () => table.setMatch('composition', k, k) : null, k === 'Vazio' ? 'danger' : k === 'Misto' ? 'warn' : null]), { centerLabel: t('grupos'), key: 'composition' }))));
    }
    box.append(table);
    return box;
  }, { subtitle: 'Compare o número de membros por grupo e encontre grupos vazios ou sem proprietário.', ring: (p) => {
    const app = p.rows.filter((r) => r.ownersApplicable !== false), ok = app.filter((r) => r.owners > 0).length, pc = pctOf(ok, app.length);
    return app.length ? { pct: pc, label: 'dos grupos com proprietário', caption: t('{n} vazios', { n: num(p.summary.empty) }), tone: toneFor(pc, 90, 75) } : null;
  } });

  /* Licensing */
  const KIND = { paid: ['Paga', 'info'], free: ['Gratuita', 'ok'], trial: ['Avaliação', 'warn'], nonseat: ['Não por usuário', ''] };
  const SKU_STATUS_CLS = { Enabled: 'ok', Warning: 'warn', Suspended: 'danger', Deleted: 'danger', LockedOut: 'danger' };
  const product = (name, code) => h('div', { class: 'prod' }, h('span', { class: 'prod-name' }, raw(name)), code && code !== name ? h('span', { class: 'prod-code' }, raw(code)) : null);
  views.licensing = () => reportPanel('licensing', {}, 'Licenciamento & custo', (p) => {
    const s = p.summary, box = h('div');
    const skuTable = DataTable({ rows: p.rows, name: 'licencas-sku', sheet: 'Licenças', pageSize: 25, onRowClick: (r) => modal(r.name, h('div', null,
      kv([['Produto', raw(r.name)], ['Código (skuPartNumber)', raw(r.part)], ['SKU ID', raw(r.skuId)], ['Tipo', (KIND[r.kind] || [r.kind])[0]], ['Status', r.statusLabel], ['Compradas', num(r.enabled)], ['Consumidas', num(r.consumed)], ['Disponíveis', num(r.available)], ['Em aviso', num(r.warning)], ['Suspensas', num(r.suspended)], ['Aplica-se a', r.appliesTo === 'Company' ? 'Organização' : 'Usuário']]),
      h('h3', { style: 'margin-top:14px' }, t('Planos de serviço ({n})', { n: num(r.plans) })),
      h('ul', { class: 'list cols' }, ...(r.planNames || []).map((n) => h('li', null, raw(n)))))), columns: [
      { key: 'name', label: 'Produto', render: (r) => product(r.name, r.part), export: (r) => r.name, wrap: true },
      { key: 'part', label: 'Código (skuPartNumber)', exportOnly: true },
      { key: 'kind', label: 'Tipo', render: (r) => badge(...(KIND[r.kind] || [r.kind])), export: (r) => (KIND[r.kind] || [r.kind])[0] },
      { key: 'consumed', label: 'Consumidas', render: (r) => num(r.consumed) }, { key: 'enabled', label: 'Compradas', render: (r) => num(r.enabled) },
      { key: 'available', label: 'Disponíveis', render: (r) => r.available < 0 ? badge(num(r.available), 'danger') : num(r.available) },
      { key: 'pctUsed', label: '% uso', render: (r) => meter(r.pctUsed, r.pctUsed > 100 ? 'danger' : (r.pctUsed >= 95 ? 'warn' : 'ok')), export: (r) => r.pctUsed },
      { key: 'warning', label: 'Em aviso', render: (r) => r.warning ? badge(num(r.warning), 'warn') : '—' },
      { key: 'statusLabel', label: 'Status', render: (r) => badge(r.statusLabel || '—', SKU_STATUS_CLS[r.status] || '') },
      { key: 'plans', label: 'Planos de serviço', render: (r) => num(r.plans) },
      { key: 'planNames', label: 'Planos de serviço (códigos)', exportOnly: true },
      { key: 'suspended', label: 'Suspensas', exportOnly: true }, { key: 'appliesTo', label: 'Aplica-se a', exportOnly: true, export: (r) => (r.appliesTo === 'Company' ? 'Organização' : 'Usuário') },
      { key: 'skuId', label: 'SKU ID', exportOnly: true },
    ], filters: [
      { id: 'paid', label: 'Pagas', fn: (r) => r.kind === 'paid' }, { id: 'free', label: 'Gratuitas/avaliação', fn: (r) => r.kind !== 'paid' },
      { id: 'near', label: 'Quase esgotadas (≥ 95%)', fn: (r) => r.kind === 'paid' && r.enabled > 0 && r.available >= 0 && r.pctUsed >= 95 }, { id: 'over', label: 'Excedidas', fn: (r) => r.available < 0 },
      { id: 'unused', label: 'Sem atribuição', fn: (r) => r.kind === 'paid' && r.enabled > 0 && r.consumed === 0 }, { id: 'status', label: 'Em aviso/suspensas', fn: (r) => ['Warning', 'Suspended', 'LockedOut'].includes(r.status) },
    ] });
    const wasteTitle = h('h3', { style: 'margin-top:18px' }, 'Licenças desperdiçadas (candidatas a recuperação)');
    const overlapTitle = h('h3', { style: 'margin-top:18px' }, 'Licenças sobrepostas');
    box.append(kpis([
      { label: 'SKUs pagos', value: s.skus, onclick: () => skuTable.setFilter('paid') }, { label: 'Lugares pagos', value: s.totalEnabled }, { label: 'Consumidos', value: s.totalConsumed }, { label: 'Disponíveis', value: s.totalAvailable, cls: 'ok' },
      { label: 'Quase esgotados (≥ 95%)', value: s.nearCapacity, cls: s.nearCapacity ? 'warn' : '', onclick: () => skuTable.setFilter('near') }, { label: 'Excedidos', value: s.overAssigned, cls: s.overAssigned ? 'danger' : '', onclick: () => skuTable.setFilter('over') },
      { label: 'Desperdício (lugares)', value: s.wasteSeats, cls: 'danger', onclick: () => wasteTitle.scrollIntoView({ behavior: 'smooth', block: 'start' }) },
      { label: 'Sobreposições (lugares)', value: s.overlapSeats ?? 0, cls: s.overlapSeats ? 'danger' : 'ok', onclick: () => overlapTitle.scrollIntoView({ behavior: 'smooth', block: 'start' }) },
      { label: 'Desabilitados c/ licença', value: s.disabledLicensed, cls: 'warn' }, { label: 'Inativos c/ licença', value: s.inactiveLicensed, cls: 'warn' }, { label: 'Convidados c/ licença', value: s.guestsLicensed },
      { label: 'Gratuitas/avaliação', value: s.freeSkus ?? 0, onclick: () => skuTable.setFilter('free') },
    ]));
    if (s.overAssigned) box.append(h('div', { class: 'alert danger' }, t('{n} produto(s) com mais licenças atribuídas do que compradas. Regularize a compra ou remova atribuições.', { n: num(s.overAssigned) })));
    if (s.expiring) box.append(h('div', { class: 'alert warn' }, t('{n} assinatura(s) em aviso, suspensas ou bloqueadas — confira a renovação no Centro de administração do Microsoft 365.', { n: num(s.expiring) })));
    if (s.freeSkus) box.append(h('p', { class: 'muted small' }, 'Licenças gratuitas, de avaliação e as que não são vendidas por usuário (ex.: Microsoft Power Automate Free) ficam fora dos totais pagos e do desperdício.'));
    const paidRows = p.rows.filter((r) => r.kind === 'paid');
    if (paidRows.length) {
      const waste = Math.min(s.wasteSeats || 0, s.totalConsumed || 0);
      box.append(vizRow(
        vizCard('Lugares pagos', donut([['Em uso', Math.max(0, (s.totalConsumed || 0) - waste), null, 'info'], ['Desperdício (recuperável)', waste, () => wasteTitle.scrollIntoView({ behavior: 'smooth', block: 'start' }), 'danger'], ['Disponíveis', Math.max(0, s.totalAvailable || 0), null, 'muted']], { centerLabel: t('lugares') })),
        vizCard('Consumo por produto', barList([...paidRows].sort((a, b) => b.consumed - a.consumed).map((r) => [raw(r.name), r.consumed, () => skuTable.setSearch(r.name)]), s.totalConsumed, { limit: 8 }))));
    }
    box.append(h('h3', null, 'Produtos (SKUs)'), skuTable);

    box.append(wasteTitle);
    if (!p._meta.premium) box.append(h('div', { class: 'alert warn' }, 'Sem Entra ID P1/P2: o critério de inatividade não é avaliado (só desabilitados e convidados).'));
    box.append(DataTable({ rows: p.waste, name: 'licencas-desperdicio', sheet: 'Licenças · Desperdício', onRowClick: (r) => modal(r.displayName || r.upn, kv([['UPN', raw(r.upn || '—')], ['Motivo', reason(r.reason)], ['Licenças', r.licenses], ['Lugares', r.seats], ['Ativo', yesNo(r.enabled)], ['Último login', fmtDate(r.lastSignIn)]])), columns: [
      { key: 'displayName', label: 'Usuário' }, { key: 'upn', label: 'UPN' }, { key: 'reason', label: 'Motivo', render: (r) => badge(reason(r.reason), 'warn'), export: (r) => reason(r.reason), wrap: true }, { key: 'seats', label: 'Lugares' }, { key: 'licenses', label: 'Licenças', render: (r) => list(r.licenses, 3), wrap: true }, { key: 'lastSignIn', label: 'Último login', render: (r) => fmtDate(r.lastSignIn), export: (r) => r.lastSignIn },
    ], filters: [{ id: 'disabled', label: 'Desabilitados', fn: (r) => !r.enabled }, { id: 'guest', label: 'Convidados', fn: (r) => /Convidado/.test(r.reason) }, { id: 'inactive', label: 'Inativos/nunca logaram', fn: (r) => /Inativo|Nunca/.test(r.reason) }] }));

    box.append(overlapTitle, h('p', { class: 'muted small' }, 'O usuário tem um produto que já está incluído em outro produto dele (ex.: Enterprise Mobility + Security E3 junto com Microsoft 365 E3). Remover o redundante libera o lugar sem perda de serviço — confira antes se há planos desabilitados no produto maior.'));
    box.append(DataTable({ rows: p.overlaps || [], name: 'licencas-sobrepostas', sheet: 'Licenças · Sobreposições', emptyText: 'Nenhuma sobreposição encontrada.', columns: [
      { key: 'displayName', label: 'Usuário' }, { key: 'upn', label: 'UPN' },
      { key: 'redundant', label: 'Licença redundante', render: (r) => list(r.redundant, 3), wrap: true }, { key: 'coveredBy', label: 'Já incluída em', render: (r) => list(r.coveredBy, 2), wrap: true },
      { key: 'seats', label: 'Lugares' }, { key: 'check', label: 'Verificar', render: (r) => r.check ? badge('Planos desabilitados', 'warn') : '—', export: (r) => (r.check ? 'Planos desabilitados' : '') },
      { key: 'enabled', label: 'Ativo', render: (r) => bool(r.enabled) },
    ] }));
    if (p._meta.catalog) box.append(h('p', { class: 'muted small foot-note' }, t('Nomes oficiais da Microsoft (catálogo de {s}).', { s: /^\d{4}-\d{2}-\d{2}$/.test(p._meta.catalog) ? isoDay(p._meta.catalog) : p._meta.catalog })));
    return box;
  }, { subtitle: 'Compare as licenças disponíveis e atribuídas. Revise contas desabilitadas ou inativas, convidados licenciados e usuários com produtos sobrepostos.', graphSource: '/subscribedSkus /users', ring: (p) => {
    const s = p.summary, pc = pctOf(s.totalConsumed, s.totalEnabled);
    return s.totalEnabled ? { pct: Math.min(100, pc), label: 'dos lugares pagos em uso', caption: t('{n} lugares recuperáveis', { n: num(s.wasteSeats || 0) }), tone: pc > 100 ? 'danger' : pc >= 80 ? ((s.wasteSeats || 0) > (s.totalConsumed || 0) * 0.15 ? 'warn' : 'ok') : 'warn' } : null;
  } });

  /* Guests */
  views.guests = () => reportPanel('guests', {}, 'Colaboração externa (convidados)', (p) => {
    const s = p.summary, box = h('div');
    const table = DataTable({ rows: p.rows, name: 'convidados', onRowClick: (r) => modal(r.displayName || r.upn, kv([['UPN', r.upn], ['E-mail', r.mail], ['Domínio', r.domain], ['Estado', r.state], ['Ativo', yesNo(r.enabled)], ['Criado', fmtDate(r.created)], ['Último login', fmtDate(r.lastSignIn)], ['Dias sem login', r.daysSinceSignIn ?? 'nunca'], ['Empresa', r.company]])), columns: [
      { key: 'displayName', label: 'Nome' }, { key: 'upn', label: 'UPN' }, { key: 'domain', label: 'Domínio' }, { key: 'state', label: 'Convite', render: (r) => badge(r.state || '—', r.state === 'Accepted' ? 'ok' : r.pending ? 'warn' : '') }, { key: 'enabled', label: 'Ativo', render: (r) => bool(r.enabled) }, { key: 'lastSignIn', label: 'Último login', render: (r) => fmtDate(r.lastSignIn), export: (r) => r.lastSignIn }, { key: 'daysSinceSignIn', label: 'Dias', render: (r) => r.daysSinceSignIn === null ? badge('nunca', 'danger') : r.daysSinceSignIn }, { key: 'stale', label: 'Obsoleto', render: (r) => r.stale ? badge('Sim', 'danger') : '—' }, { key: 'created', label: 'Criado', render: (r) => fmtDay(r.created), export: (r) => r.created },
      { key: 'mail', label: 'E-mail', exportOnly: true }, { key: 'company', label: 'Empresa', exportOnly: true },
      { key: 'stateChanged', label: 'Convite atualizado em', exportOnly: true, export: (r) => r.stateChanged }, { key: 'id', label: 'Object ID', exportOnly: true },
    ], filters: [{ id: 'pending', label: 'Pendentes', fn: (r) => r.pending }, { id: 'stale', label: 'Obsoletos', fn: (r) => r.stale }, { id: 'disabled', label: 'Desabilitados', fn: (r) => !r.enabled }, { id: 'never', label: 'Nunca logaram', fn: (r) => !!p._meta.premium && r.daysSinceSignIn === null }] });
    box.append(kpis([
      { label: 'Convidados', value: s.total }, { label: 'Pendentes', value: s.pending, cls: 'warn', onclick: () => table.setFilter('pending') }, { label: 'Aceitos', value: s.accepted, cls: 'ok' }, { label: 'Obsoletos', value: p._meta.premium ? s.stale : null, cls: 'danger', onclick: p._meta.premium ? () => table.setFilter('stale') : null }, { label: 'Nunca logaram', value: p._meta.premium ? s.neverSignIn : null, cls: 'warn', onclick: p._meta.premium ? () => table.setFilter('never') : null }, { label: 'Desabilitados', value: s.disabled, onclick: () => table.setFilter('disabled') },
    ]));
    if (!p._meta.premium) box.append(h('div', { class: 'alert warn' }, 'Sem Entra ID P1/P2 a Microsoft não informa o último login dos convidados: "Obsoletos" e "Nunca logaram" não são avaliados.'));
    if (s.total) {
      box.append(vizRow(
        vizCard('Situação do convite', donut([['Aceitos', s.accepted, null, 'ok'], ['Pendentes', s.pending, () => table.setFilter('pending'), 'warn']], { total: s.total, centerLabel: t('convidados'), key: 'state' })),
        vizCard('Por domínio de origem', barList(Object.entries(s.byDomain || {}).sort((a, b) => b[1] - a[1]).map(([k, v]) => [raw(k), v, () => table.setMatch('domain', k, k)]), s.total, { limit: 8, key: 'domain' }))));
    }
    box.append(table);
    return box;
  }, { subtitle: 'Verifique quais convidados aceitaram o convite, quais ainda não responderam e quais estão há mais tempo sem acessar o ambiente.', graphSource: '/users', ring: (p) => {
    const s = p.summary, ok = p.rows.filter((r) => r.state === 'Accepted' && !r.stale && r.enabled).length, pc = pctOf(ok, s.total);
    return s.total ? { pct: pc, label: 'dos convidados ativos e com convite aceito', caption: p._meta.premium ? t('{n} obsoletos', { n: num(s.stale) }) : '', tone: toneFor(pc, 80, 60) } : null;
  } });

  /* Domains */
  views.domains = () => reportPanel('domains', {}, 'Domínios', (p) => {
    const s = p.summary, box = h('div');
    box.append(kpis([{ label: 'Domínios', value: s.total }, { label: 'Verificados', value: s.verified, cls: 'ok' }, { label: 'Não verificados', value: s.unverified, cls: s.unverified ? 'warn' : '' }, { label: 'Federados', value: s.federated }, { label: 'Managed', value: s.managed }]));
    box.append(DataTable({ rows: p.rows, name: 'dominios', columns: [
      { key: 'name', label: 'Domínio' }, { key: 'isDefault', label: 'Padrão', render: (r) => r.isDefault ? badge('padrão', 'info') : '—' }, { key: 'isVerified', label: 'Verificado', render: (r) => bool(r.isVerified) }, { key: 'authType', label: 'Autenticação', render: (r) => badge(r.authType, r.authType === 'Federated' ? 'warn' : 'ok') }, { key: 'supportedServices', label: 'Serviços', render: (r) => list(r.supportedServices, 4), wrap: true }, { key: 'isInitial', label: 'Inicial (onmicrosoft)', render: (r) => r.isInitial ? 'Sim' : '—' },
      { key: 'isRoot', label: 'Domínio raiz', exportOnly: true, export: (r) => !!r.isRoot }, { key: 'adminManaged', label: 'Gerenciado pelo administrador', exportOnly: true, export: (r) => !!r.adminManaged },
      { key: 'passwordValidity', label: 'Validade da senha (dias)', exportOnly: true, export: (r) => (r.passwordValidity && r.passwordValidity < 2147483647 ? r.passwordValidity : t('Nunca expira')) },
    ] }));
    return box;
  }, { subtitle: 'Identifique o domínio padrão e confira a verificação, o tipo de autenticação e os serviços associados a cada domínio.', graphSource: '/domains', ring: (p) => {
    const s = p.summary, pc = pctOf(s.verified, s.total);
    return s.total ? { pct: pc, label: 'dos domínios verificados', caption: t('{n} federados', { n: num(s.federated) }), tone: toneFor(pc, 100, 80) } : null;
  } });

  /* Auth policies posture */
  views.authpolicies = () => reportPanel('authpolicies', {}, 'Postura de autenticação do tenant', (p) => {
    const box = h('div');
    const sd = p.securityDefaults, a = p.authorization || {};
    const flag = (ok, t, f) => badge(ok ? t : f, ok ? 'ok' : 'danger');
    box.append(kpis([
      { label: 'Security Defaults', value: sd ? (sd.enabled ? 'Ativado' : 'Desativado') : '—', cls: sd && sd.enabled ? 'ok' : 'warn' },
      { label: 'Legacy auth bloqueada', value: p.legacyAuthBlocked === null ? '—' : (p.legacyAuthBlocked ? 'Sim' : 'Não'), cls: p.legacyAuthBlocked ? 'ok' : 'danger' },
      { label: 'SSPR habilitado', value: a.allowedToUseSSPR ? 'Sim' : 'Não', cls: a.allowedToUseSSPR ? 'ok' : 'warn' },
      { label: 'Métodos configurados', value: (p.methods || []).length },
    ]));
    box.append(h('div', { class: 'grid2' },
      h('div', { class: 'panel' }, h('h3', null, 'Configurações de diretório (authorizationPolicy)'), kv([
        ['Usuários podem consentir apps', flag(!a.allowUserConsentForApps, 'Bloqueado', 'Permitido (risco)')],
        ['Usuários podem criar apps', flag(!a.allowedToCreateApps, 'Não', 'Sim (risco)')],
        ['Usuários podem criar grupos de segurança', a.allowedToCreateSecurityGroups ? 'Sim' : 'Não'],
        ['Usuários leem outros usuários', a.allowedToReadOtherUsers ? 'Sim' : 'Não'],
        ['Acesso de convidados', a.guestAccessLabel || '—'],
        ['Email verificado pode ingressar', flag(!a.allowEmailVerifiedToJoin, 'Não', 'Sim (risco)')],
        ['MSOL PowerShell bloqueado', a.blockMsolPowerShell ? 'Sim' : 'Não'],
      ])),
      h('div', { class: 'panel' }, h('h3', null, 'Métodos de autenticação (policy)'), (p.methods || []).length ? DataTable({ rows: p.methods, name: 'metodos-auth', pageSize: 25, columns: [
        { key: 'id', label: 'Método', render: (r) => t(methodName(r.id)), export: (r) => methodName(r.id), sortValue: (r) => t(methodName(r.id)) },
        { key: 'state', label: 'Estado', render: (r) => badge(r.state === 'enabled' ? 'Habilitado' : 'Desabilitado', r.state === 'enabled' ? 'ok' : 'muted'), export: (r) => (r.state === 'enabled' ? 'Habilitado' : 'Desabilitado') },
        { key: 'targets', label: 'Alvos', render: (r) => r.state === 'enabled' ? list(r.targets, 2) : '—', wrap: true },
      ], compact: true, filters: [{ id: 'on', label: 'Habilitados', fn: (r) => r.state === 'enabled' }, { id: 'off', label: 'Desabilitados', fn: (r) => r.state !== 'enabled' }] }) : h('p', { class: 'muted' }, 'Indisponível.'))
    ));
    if (sd && sd.enabled) box.append(h('div', { class: 'alert warn' }, 'Security Defaults ON: ideal para tenants simples, mas impede políticas de Acesso Condicional granulares. Em ambientes enterprise, desative e use CA com Authentication Strength.'));
    return box;
  }, { subtitle: 'Confira os padrões de segurança do diretório, os métodos de autenticação configurados e se os usuários podem consentir com aplicativos ou redefinir a própria senha.', graphSource: '/policies/identitySecurityDefaultsEnforcementPolicy /policies/authenticationMethodsPolicy /policies/authorizationPolicy' });

  /* Privileged access */
  views.privileged = () => reportPanel('privileged', {}, 'Acessos privilegiados (RBAC/PIM)', (p) => {
    const s = p.summary, box = h('div');
    const table = DataTable({ rows: p.rows, name: 'acessos-privilegiados', onRowClick: (r) => modal(r.name || r.upn, kv([['Função', r.role], ['Tipo de atribuição', r.kind], ['Privilegiada', yesNo(r.privileged)], ['Principal', r.name], ['UPN', r.upn], ['Tipo de principal', r.principalType], ['MFA registrado', r.mfa === null ? 'n/a' : yesNo(r.mfa)], ['Início', fmtDate(r.start)], ['Fim', fmtDate(r.end)], ['Member type', r.memberType]])), columns: [
      { key: 'role', label: 'Função', render: (r) => r.privileged ? badge(r.role, 'danger') : r.role, wrap: true }, { key: 'name', label: 'Principal' }, { key: 'principalType', label: 'Tipo', render: (r) => badge(r.principalType, r.principalType === 'user' ? 'info' : 'warn') }, { key: 'kind', label: 'Atribuição', render: (r) => badge(r.kind, r.kind.startsWith('Permanente') ? 'warn' : 'ok') }, { key: 'mfa', label: 'MFA', render: (r) => r.principalType !== 'user' ? '—' : bool(r.mfa) }, { key: 'upn', label: 'UPN' }, { key: 'end', label: 'Expira', render: (r) => r.end ? fmtDate(r.end) : '—', export: (r) => r.end },
      { key: 'privileged', label: 'Função privilegiada', exportOnly: true, export: (r) => !!r.privileged }, { key: 'start', label: 'Início', exportOnly: true, export: (r) => r.start },
      { key: 'scope', label: 'Escopo', exportOnly: true, export: (r) => (r.scope === '/' ? t('Diretório inteiro') : r.scope) }, { key: 'memberType', label: 'Tipo de associação', exportOnly: true },
      { key: 'roleId', label: 'ID da função', exportOnly: true }, { key: 'principalId', label: 'ID do principal', exportOnly: true },
    ], filters: [
      { id: 'standing', label: 'Permanentes', fn: (r) => r.kind.startsWith('Permanente') }, { id: 'eligible', label: 'Elegíveis (PIM)', fn: (r) => r.kind.startsWith('Elegível') }, { id: 'activated', label: 'Ativados (PIM)', fn: (r) => r.kind.startsWith('Ativado') }, { id: 'priv', label: 'Funções privilegiadas', fn: (r) => r.privileged }, { id: 'privstanding', label: 'Permanentes privilegiadas', fn: (r) => r.privileged && r.kind.startsWith('Permanente') }, { id: 'ga', label: 'Global Administrators', fn: (r) => r.roleId === '62e90394-69f5-4237-9190-012177145e10' && !r.kind.startsWith('Elegível') }, { id: 'nomfa', label: 'Admins sem MFA', fn: (r) => r.principalType === 'user' && r.mfa === false }, { id: 'nonuser', label: 'Grupos/SPs', fn: (r) => r.principalType !== 'user' },
    ] });
    const standing = s.standing ?? s.active;
    box.append(kpis([
      { label: 'Atribuições', value: s.active + s.eligible }, { label: 'Permanentes', value: standing, cls: 'warn', onclick: () => table.setFilter('standing') }, { label: 'Elegíveis (PIM)', value: s.eligible, cls: 'ok', onclick: () => table.setFilter('eligible') }, { label: 'Permanentes privilegiadas', value: s.standingPrivileged, cls: 'danger', onclick: () => table.setFilter('privstanding') },
      { label: 'Global Administrators', value: s.globalAdmins ?? 0, cls: (s.globalAdmins || 0) > 4 ? 'danger' : ((s.globalAdmins || 0) < 2 ? 'warn' : 'ok'), onclick: () => table.setFilter('ga'), title: 'A Microsoft recomenda de 2 a 4 Global Administrators.' },
      { label: 'Admins sem MFA', value: s.adminsNoMfa, cls: 'danger', onclick: () => table.setFilter('nomfa') }, { label: 'Principals únicos', value: s.uniquePrincipals }, { label: 'Grupos atribuídos', value: s.groupsAssigned, onclick: () => table.setFilter('nonuser') }, { label: 'SPs atribuídos', value: s.spAssigned },
    ]));
    if (s.pim === false) box.append(h('div', { class: 'alert warn' }, 'PIM indisponível neste tenant (requer Entra ID P2 ou Governance) — todas as atribuições são permanentes. Zero Trust recomenda PIM just-in-time para funções privilegiadas.'));
    else if (s.eligible === 0) box.append(h('div', { class: 'alert warn' }, 'Nenhuma atribuição elegível (PIM) encontrada — o tenant pode não ter Entra ID P2 ou usa apenas atribuições permanentes. Zero Trust recomenda PIM just-in-time para funções privilegiadas.'));
    if (p.rows.length) {
      const kinds = countBy(p.rows, (r) => r.kind.split(' (')[0], { limit: 4, label: (k) => t(k) });
      box.append(vizRow(
        vizCard('Tipo de atribuição', donut(kinds.map(([l, v, k]) => [l, v, k === 'Permanente' ? () => table.setFilter('standing') : k === 'Elegível' ? () => table.setFilter('eligible') : k === 'Ativado' ? () => table.setFilter('activated') : null, k === 'Permanente' ? 'warn' : k === 'Elegível' ? 'ok' : 'info']), { centerLabel: t('atribuições'), key: 'kind' })),
        vizCard('Por função', barList(Object.entries(s.byRole || {}).sort((a, b) => b[1] - a[1]).map(([k, v]) => [raw(k), v, () => table.setMatch('role', k, k)]), p.rows.length, { limit: 8 }))));
    }
    box.append(table);
    return box;
  }, { subtitle: 'Veja quem tem acesso administrativo ativo ou elegível. A Microsoft classifica as funções privilegiadas. A elegibilidade via PIM depende de uma licença compatível.', graphSource: '/roleManagement/directory/roleAssignmentScheduleInstances /roleManagement/directory/roleEligibilityScheduleInstances /roleManagement/directory/roleDefinitions', ring: (p) => {
    if (p.summary.pim === false) return null;
    const priv = p.rows.filter((r) => r.privileged), jit = priv.filter((r) => !r.kind.startsWith('Permanente')).length, pc = pctOf(jit, priv.length);
    return priv.length ? { pct: pc, label: 'dos acessos privilegiados via PIM (just-in-time)', caption: t('{n} permanentes', { n: num(priv.length - jit) }), tone: toneFor(pc, 80, 50) } : null;
  } });

  /* Risk (Identity Protection) */
  views.risk = () => reportPanel('risk', {}, 'Risco de identidade (Identity Protection)', (p) => {
    const s = p.summary, box = h('div');
    box.append(kpis([
      { label: 'Usuários em risco', value: s.total, cls: s.total ? 'danger' : 'ok' }, { label: 'Alto', value: s.high, cls: 'danger' }, { label: 'Médio', value: s.medium, cls: 'warn' }, { label: 'Baixo', value: s.low }, { label: 'Comprometidos confirmados', value: s.confirmedCompromised, cls: 'danger' }, { label: 'Detecções (30 dias)', value: s.detections },
    ]));
    if (s.total) box.append(vizRow(vizCard('Nível de risco', donut([['Alto', s.high, null, 'danger'], ['Médio', s.medium, null, 'warn'], ['Baixo', s.low, null, 'info']], { total: s.total, centerLabel: t('usuários'), key: 'level' }))));
    box.append(h('h3', null, 'Usuários em risco'));
    box.append(DataTable({ rows: p.rows, name: 'usuarios-risco', sheet: 'Risco · Usuários', columns: [
      { key: 'displayName', label: 'Usuário' }, { key: 'upn', label: 'UPN' }, { key: 'level', label: 'Nível', render: (r) => badge(r.level, { high: 'danger', medium: 'warn', low: 'info' }[r.level] || '') }, { key: 'state', label: 'Estado', render: (r) => badge(r.state, r.state === 'confirmedCompromised' ? 'danger' : 'warn') }, { key: 'detail', label: 'Detalhe' }, { key: 'updated', label: 'Atualizado', render: (r) => fmtDate(r.updated), export: (r) => r.updated },
    ], filters: [{ id: 'high', label: 'Alto', fn: (r) => r.level === 'high' }, { id: 'atrisk', label: 'Em risco', fn: (r) => r.state === 'atRisk' }] }));
    if (p.detections && p.detections.length) {
      box.append(h('h3', { style: 'margin-top:16px' }, 'Detecções dos últimos 30 dias'));
      box.append(DataTable({ rows: p.detections, name: 'deteccoes-risco', sheet: 'Risco · Detecções', pageSize: 25, columns: [
        { key: 'detected', label: 'Quando', render: (r) => fmtDate(r.detected), export: (r) => r.detected }, { key: 'upn', label: 'Usuário' }, { key: 'type', label: 'Tipo' }, { key: 'level', label: 'Nível', render: (r) => badge(r.level, { high: 'danger', medium: 'warn' }[r.level] || '') }, { key: 'ip', label: 'IP' }, { key: 'location', label: 'Local' }, { key: 'state', label: 'Estado' },
        { key: 'detectionTiming', label: 'Momento da detecção', exportOnly: true },
      ] }));
    }
    return box;
  }, { subtitle: 'Encontre usuários em risco, confira a gravidade e examine as detecções do Microsoft Entra ID Protection. Requer Entra ID P2.', graphSource: '/identityProtection/riskyUsers /identityProtection/riskDetections' });

  /* App security */
  views.appsec = () => reportPanel('appsec', {}, 'Aplicativos, consentimentos & credenciais', (p) => {
    const s = p.summary, box = h('div');
    const objectType = (r) => t(({ 'App Registration': 'Registro de aplicativo', 'Service Principal': 'Entidade de serviço' })[r.ownerType] || r.ownerType);
    const credentialType = (r) => t(({ Secret: 'Segredo', Certificado: 'Certificado' })[r.credType] || r.credType);
    const expiry = (r) => t(({ 'expirado': 'Expirada', 'expira <30d': 'Expira em até 30 dias', 'expira <90d': 'Expira entre 31 e 90 dias', 'sem validade': 'Validade desconhecida', ok: 'Mais de 90 dias' })[r.status] || r.status);
    box.append(kpis([
      { label: 'Enterprise apps', value: s.enterpriseApps }, { label: 'De terceiros', value: s.thirdParty, cls: 'warn' }, { label: 'Credenciais', value: s.credsTotal }, { label: 'Expiradas', value: s.credsExpired, cls: 'danger' }, { label: 'Expiram <30d', value: s.creds30, cls: 'warn' }, { label: 'Consentimentos', value: s.consents }, { label: 'Consentimentos de risco', value: s.riskyConsents, cls: s.riskyConsents ? 'danger' : 'ok' }, { label: 'Tenant-wide', value: s.tenantWideConsents, cls: 'warn' },
    ]));
    if (s.credsTotal) {
      const undated = s.credsUndated ?? p.rows.filter((r) => r.daysToExpiry == null).length;
      const ok = Math.max(0, s.credsTotal - s.credsExpired - s.creds30 - undated);
      box.append(vizRow(
        vizCard('Credenciais', donut([['Mais de 30 dias', ok, () => credentials.setFilter('later'), 'ok'], ['Expiram em até 30 dias', s.creds30, () => credentials.setFilter('soon'), 'warn'], ['Expiradas', s.credsExpired, () => credentials.setFilter('exp'), 'danger'], ['Sem validade', undated, () => credentials.setFilter('noexp'), 'muted']], { centerLabel: t('credenciais'), key: 'status' })),
        vizCard('Consentimentos OAuth', donut([['Com escopo de risco', s.riskyConsents, null, 'danger'], ['Demais', Math.max(0, s.consents - s.riskyConsents), null, 'info']], { centerLabel: t('consentimentos') }))));
    }
    if (Array.isArray(p.rotation)) {
      box.append(h('h3', null, 'Plano de renovação de credenciais'));
      box.append(h('p', { class: 'muted' }, 'Revise os vencimentos dos próximos 90 dias por objeto e tipo de credencial. A validade no diretório não confirma qual credencial o aplicativo usa.'));
      box.append(DataTable({ rows: p.rotation, name: 'credential-renewals', sheet: 'Renovação de credenciais', columns: [
        { key: 'owner', label: 'Aplicativo', wrap: true }, { key: 'ownerType', label: 'Objeto', value: objectType }, { key: 'credType', label: 'Tipo', value: credentialType },
        { key: 'nextExpiry', label: 'Próximo vencimento', render: (r) => fmtDate(r.nextExpiry), export: (r) => r.nextExpiry },
        { key: 'expired', label: 'Expiradas' }, { key: 'within7', label: 'Em até 7 dias' }, { key: 'within30', label: 'De 8 a 30 dias' },
        { key: 'within90', label: 'De 31 a 90 dias' }, { key: 'longValid', label: 'Ativas além de 90 dias' },
        { key: 'objectId', label: 'ID do objeto', exportOnly: true }, { key: 'appId', label: 'App ID', exportOnly: true },
      ], filters: [
        { id: 'week', label: 'Vencem nesta semana', fn: (r) => r.within7 > 0 },
        { id: 'expired', label: 'Com credencial expirada', fn: (r) => r.expired > 0 },
        { id: 'short', label: 'Sem credencial ativa além de 90 dias', fn: (r) => r.longValid === 0 },
      ] }));
    }
    box.append(h('h3', null, 'Credenciais (segredos/certificados)'));
    const credentials = DataTable({ rows: p.rows, name: 'credenciais-apps', sheet: 'Credenciais de apps', columns: [
      { key: 'owner', label: 'Aplicativo', wrap: true }, { key: 'ownerType', label: 'Objeto', value: objectType }, { key: 'credType', label: 'Tipo', value: credentialType }, { key: 'status', label: 'Status', value: expiry, render: (r) => badge(expiry(r), { 'expirado': 'danger', 'expira <30d': 'danger', 'expira <90d': 'warn', 'ok': 'ok', 'sem validade': 'warn' }[r.status]) }, { key: 'daysToExpiry', label: 'Dias p/ expirar', render: (r) => r.daysToExpiry === null ? '—' : r.daysToExpiry }, { key: 'end', label: 'Expira em', render: (r) => fmtDate(r.end), export: (r) => r.end }, { key: 'displayName', label: 'Nome da credencial' },
      { key: 'start', label: 'Criada em', exportOnly: true, export: (r) => r.start }, { key: 'appId', label: 'App ID', exportOnly: true },
      { key: 'objectId', label: 'ID do objeto', exportOnly: true }, { key: 'credentialId', label: 'ID da credencial', exportOnly: true },
    ], filters: [{ id: 'exp', label: 'Expiradas', fn: (r) => r.daysToExpiry !== null && r.daysToExpiry < 0 }, { id: 'soon', label: 'Expiram em até 30 dias', fn: (r) => r.daysToExpiry !== null && r.daysToExpiry >= 0 && r.daysToExpiry <= 30 }, { id: 'later', label: 'Mais de 30 dias', fn: (r) => r.daysToExpiry > 30 }, { id: 'noexp', label: 'Sem validade', fn: (r) => r.daysToExpiry === null }] });
    box.append(credentials);
    box.append(h('h3', { style: 'margin-top:16px' }, 'Consentimentos OAuth (delegados)'));
    box.append(DataTable({ rows: p.consents, name: 'consentimentos', sheet: 'Consentimentos OAuth', onRowClick: (r) => modal(r.client || r.clientId, kv([['Cliente', r.client], [t('Escopos ({n})', { n: r.scopeCount }), r.scopes], ['Escopos de risco', r.risky.length ? r.risky : '—'], ['Tenant-wide', yesNo(r.tenantWide)], ['Concessões por usuário', r.userGrants]])), columns: [
      { key: 'client', label: 'Aplicativo', wrap: true }, { key: 'scopeCount', label: 'Escopos' }, { key: 'risky', label: 'Escopos de risco', render: (r) => r.risky.length ? badge(list(r.risky, 2), 'danger') : '—', wrap: true }, { key: 'tenantWide', label: 'Tenant-wide', render: (r) => r.tenantWide ? badge('Sim', 'warn') : 'Usuário' }, { key: 'userGrants', label: 'Usuários' },
      { key: 'scopes', label: 'Todos os escopos', exportOnly: true }, { key: 'clientId', label: 'ID do cliente', exportOnly: true },
    ], filters: [{ id: 'risky', label: 'Com escopo de risco', fn: (r) => r.risky.length }, { id: 'tw', label: 'Tenant-wide', fn: (r) => r.tenantWide }] }));
    return box;
  }, { subtitle: 'Encontre credenciais expiradas ou próximas do vencimento e confira quais aplicativos receberam consentimento para acessar dados sensíveis.', graphSource: '/applications /servicePrincipals /oauth2PermissionGrants', ring: (p) => {
    const s = p.summary, undated = s.credsUndated ?? p.rows.filter((r) => r.daysToExpiry == null).length;
    const ok = Math.max(0, s.credsTotal - s.credsExpired - s.creds30 - undated), pc = pctOf(ok, s.credsTotal);
    return s.credsTotal ? { pct: pc, label: 'das credenciais de apps em dia', caption: t('{n} expiradas', { n: num(s.credsExpired) }), tone: s.credsExpired ? 'danger' : toneFor(pc, 90, 75) } : null;
  } });

  /* Secure Score */
  function scoreHistory(days) {
    const chart = h('div', { class: 'score-days' });
    const items = [];
    const dates = new Intl.DateTimeFormat(CFG.intl || 'pt-BR', { month: 'short', day: 'numeric', timeZone: 'UTC' });
    for (const day of days.slice(-8)) {
      const value = pctOf(day.current, day.max);
      const date = new Date(day.date);
      const label = Number.isFinite(date.getTime()) ? dates.format(date) : '—';
      items.push([label, value == null ? null : Math.round(value * 10) / 10]);
      const points = t('{a} de {b} pontos', { a: num(day.current), b: num(day.max) });
      chart.append(h('div', { class: 'score-day', title: label + ' · ' + points },
        h('strong', null, raw(fmtPct(value))),
        h('span', { class: 'score-track', 'aria-hidden': 'true' }, value == null ? null : h('i', { style: 'height:' + Math.max(0, Math.min(value, 100)) + '%' })),
        h('span', null, raw(label))));
    }
    BAR_DATA.set(chart, { items, total: null, opts: { kind: 'scoreHistory' } });
    return vizCard('Evolução da pontuação', chart);
  }

  views.securescore = () => reportPanel('securescore', {}, 'Microsoft Secure Score', (p) => {
    const s = p.summary, box = h('div');
    box.append(kpis([
      { label: 'Pontos', value: s.current === null || s.current === undefined ? null : Math.round(s.current) }, { label: 'Pontuação máxima', value: s.max === null || s.max === undefined ? null : Math.round(s.max) },
      { label: 'Usuários ativos', value: s.activeUsers }, { label: 'Controles avaliados', value: s.controls },
      { label: 'Ganho possível', value: (p.rows || []).reduce((a, r) => a + (r.gap > 0 ? r.gap : 0), 0), cls: 'warn' },
    ]));
    box.append(h('p', { class: 'muted small' }, t('Medido em {s}.', { s: fmtDate(s.date) })));
    if (p.trend?.length > 1) box.append(scoreHistory(p.trend));
    box.append(h('h3', { style: 'margin-top:8px' }, 'Controles (maior ganho primeiro)'));
    box.append(DataTable({ rows: p.rows, name: 'secure-score', onRowClick: (r) => modal(r.name, kv([['Categoria', r.category], ['Pontuação', `${r.score} / ${r.max}`], ['% implementado', r.implemented === null ? '—' : r.implemented + '%'], ['Estado', r.state], ['Impacto no usuário', r.userImpact], ['Remediação', r.remediation]])), columns: [
      { key: 'name', label: 'Controle', wrap: true }, { key: 'category', label: 'Categoria' }, { key: 'score', label: 'Pontos', render: (r) => `${num(r.score)}/${num(r.max)}`, export: (r) => r.score }, { key: 'max', label: 'Pontos máximos', exportOnly: true }, { key: 'implemented', label: '% impl.', render: (r) => r.implemented === null ? '—' : badge(r.implemented + '%', r.implemented >= 100 ? 'ok' : (r.implemented > 0 ? 'warn' : 'danger')) }, { key: 'gap', label: 'Ganho possível', render: (r) => r.gap > 0 ? badge('+' + num(r.gap), 'warn') : '—' }, { key: 'userImpact', label: 'Impacto' },
      { key: 'state', label: 'Estado', exportOnly: true }, { key: 'description', label: 'Descrição', exportOnly: true }, { key: 'remediation', label: 'Remediação', exportOnly: true },
    ], filters: [{ id: 'gap', label: 'Com ganho', fn: (r) => r.gap > 0 }, { id: 'identity', label: 'Identidade', fn: (r) => /identity/i.test(r.category) }] }));
    return box;
  }, { subtitle: 'Compare a pontuação de segurança com o máximo disponível e identifique os controles que oferecem o maior ganho potencial.', graphSource: '/security/secureScores /security/secureScoreControlProfiles', ring: (p) => {
    const s = p.summary;
    return s.percent === null || s.percent === undefined ? null : { pct: s.percent, label: 'Microsoft Secure Score', caption: t('{a} de {b} pontos', { a: num(Math.round(s.current)), b: num(Math.round(s.max)) }), tone: toneFor(s.percent, 70, 50) };
  } });

  function rememberIdentityScore() {
    // OAuth callbacks return to the portal without its original fragment. Retain only this known view.
    try { sessionStorage.setItem('j365.identityScoreReturn', String(Date.now() + 30 * 60 * 1000)); } catch (_) { /* optional */ }
  }
  const entraScoreLink = (style) => h('a', { class: style, href: 'https://entra.microsoft.com/#view/Microsoft_AAD_IAM/SecurityMenuBlade/~/IdentitySecureScore', target: '_blank', rel: 'noopener noreferrer' }, 'Abrir pontuação no Microsoft Entra');

  function identityScoreAccess(moduleOff, permissions) {
    const actions = h('div', { class: 'actions' });
    const box = h('section', { class: 'panel' }, h('h2', null, 'Microsoft Entra Identity Score'));
    const copyLink = (url, label) => h('button', { class: 'btn sm', type: 'button', onclick: (e) => J.copyText(url, e.currentTarget) }, label);
    if (moduleOff) {
      box.append(h('h3', null, 'Módulo desativado no WordPress'), h('p', null, 'O administrador do WordPress pode marcar Identity Score (Microsoft Entra) em Configurações → Módulos de relatório e salvar. Depois, um administrador do tenant aprova a leitura das recomendações.'));
      if (CFG.modulesUrl) actions.append(CFG.canManageModules ? h('a', { class: 'btn primary sm', href: CFG.modulesUrl, target: '_blank', rel: 'noopener' }, 'Abrir módulos no WordPress') : copyLink(CFG.modulesUrl, 'Copiar link para o administrador do WordPress'));
    } else {
      const perTenant = (permissions.mode || CFG.tenancy) === 'per_tenant';
      const can = App.me.user?.can || {};
      box.append(h('h3', null, 'Aprovação pendente no tenant'), h('p', null, 'A leitura das pontuações e recomendações de identidade requer a permissão DirectoryRecommendations.Read.All.'));
      box.append(h('p', null, perTenant ? 'Um administrador autorizado do tenant pode atualizar o aplicativo e aprovar a permissão na Microsoft.' : 'O operador do portal atualiza o registro do aplicativo no Assistente do WordPress. Depois, um Global Administrator ou Privileged Role Administrator aprova a permissão para este tenant.'));
      if (!perTenant && CFG.setup?.can && window.J365Landing?.openSetup) actions.append(h('button', { class: 'btn sm', type: 'button', onclick: () => window.J365Landing.openSetup() }, 'Abrir assistente do aplicativo'));
      if (permissions.approveUrl) {
        if (perTenant ? can.updateTenantApp : can.manageAccess) actions.append(h('a', { class: 'btn primary sm', href: permissions.approveUrl, onclick: rememberIdentityScore }, perTenant ? 'Atualizar o aplicativo' : 'Aprovar agora'));
        actions.append(copyLink(permissions.approveUrl, 'Copiar link para o administrador'));
      }
    }
    actions.append(entraScoreLink('btn sm'), h('button', { class: 'btn sm', type: 'button', onclick: () => renderShell() }, 'Verificar acesso novamente'));
    box.append(actions);
    return box;
  }
  views.identityscore = () => {
    const moduleOff = !(App.me.reports || []).includes('identityscore'), permissions = App.me.permissions || {};
    if (moduleOff || (permissions.blocked || []).includes('identityscore')) return identityScoreAccess(moduleOff, permissions);
    return identityScoreReport();
  };
  const identityScoreReport = () => reportPanel('identityscore', {}, 'Microsoft Entra Identity Score', (p) => {
    const s = p.summary;
    const box = h('div');
    const status = { active: 'Ativa', completedBySystem: 'Concluído pelo sistema', completedByUser: 'Concluído pelo usuário', dismissed: 'Descartada', postponed: 'Adiada', riskAccepted: 'Risco aceito', planned: 'Planejada', thirdParty: 'Resolvido por terceiro', alternateMitigation: 'Mitigação alternativa' };
    const priority = { high: 'Alta', medium: 'Média', low: 'Baixa' };
    if (s.percent == null) box.append(h('p', { class: 'alert warn' }, 'O Entra ainda não forneceu uma medição completa para este tenant.'));
    box.append(kpis([
      { label: 'Pontos', value: s.current }, { label: 'Pontuação máxima', value: s.max },
      { label: 'Recomendações', value: p.rows.length }, { label: 'Medições', value: p.history.length },
    ]));
    if (s.date) box.append(h('p', { class: 'muted small' }, t('Medido em {s}.', { s: fmtDate(s.date) })));
    if (p.history.length > 1) box.append(scoreHistory(p.history.slice(0, 8).reverse()));
    box.append(h('h3', null, 'Recomendações de identidade'), DataTable({
      rows: p.rows, name: 'identity-recommendations', sheet: 'Recomendações de identidade',
      columns: [
        { key: 'name', label: 'Recomendação', wrap: true }, { key: 'status', label: 'Estado', value: (r) => t(status[r.status] || r.status) },
        { key: 'priority', label: 'Prioridade', value: (r) => t(priority[r.priority] || r.priority) }, { key: 'current', label: 'Pontos' }, { key: 'max', label: 'Pontos máximos' },
        { key: 'gap', label: 'Ganho possível' }, { key: 'checked', label: 'Última avaliação', render: (r) => fmtDate(r.checked) },
      ], filters: [{ id: 'active', label: 'Ativas', fn: (r) => r.status === 'active' }, { id: 'gap', label: 'Com ganho', fn: (r) => r.gap > 0 }],
    }), h('h3', null, 'Histórico da pontuação'), DataTable({
      rows: p.history, name: 'identity-score-history', sheet: 'Histórico da pontuação',
      columns: [{ key: 'date', label: 'Data da medição', render: (r) => fmtDay(r.date.slice(0, 10)) },
        { key: 'current', label: 'Pontos' }, { key: 'max', label: 'Pontuação máxima' },
        { key: 'percent', label: 'Identity Score (%)', render: (r) => r.percent == null ? '—' : num(r.percent) + '%' }],
    }));
    return box;
  }, { subtitle: 'Pontuação diária e recomendações de identidade fornecidas pelo Microsoft Entra (Graph beta).', tools: entraScoreLink('btn sm glass'), ring: ({ summary: s }) =>
    s.percent == null ? null : { pct: s.percent, label: 'Identity Score', caption: t('{a} de {b} pontos', { a: num(s.current), b: num(s.max) }), tone: toneFor(s.percent, 70, 50) } });

  /* M365 usage */
  views.usage = () => {
    const wrap = h('div');
    const period = h('select', { class: 'j365-input', 'aria-label': 'Período' }, ...['D7', 'D30', 'D90', 'D180'].map((d) => h('option', { value: d, selected: d === (App.params.usagePeriod || 'D30') }, t('{n} dias', { n: d.slice(1) }))));
    const form = h('form', { class: 'rp-form', onsubmit: (e) => { e.preventDefault(); draw(); } }, h('label', null, h('span', null, 'Período'), period), h('button', { class: 'btn sm glass', type: 'submit' }, 'Aplicar'));
    const out = wrap;
    function draw() {
      out.innerHTML = '';
      App.params.usagePeriod = period.value;
      out.append(reportPanel('usage', { period: period.value }, t('Uso & adoção ({p})', { p: period.value }), (p) => {
        const s = p.summary, box = h('div');
        const hidden = !!p._meta.concealed;
        const pct = (a, b) => (b ? Math.round((100 * a) / b) : null);
        const adoption = (label, active, licensed, onclick) => ({ label, value: active, cls: '', onclick, title: licensed ? t('{a} de {b} licenciados ({c}%)', { a: num(active), b: num(licensed), c: pct(active, licensed) }) : null });
        const rows = p.rows || [];
        const shortId = (v) => (v ? String(v).slice(0, 8).toLowerCase() + '…' : '—');
        App.namesConcealed = hidden;
        if (hidden) box.append(concealedBanner());
        box.append(kpis([
          { label: 'Usuários', value: s.users },
          adoption('Ativos Exchange', s.activeExchange, s.licensedExchange, () => table.setFilter('exch')),
          adoption('Ativos Teams', s.activeTeams, s.licensedTeams, () => table.setFilter('teams')),
          { label: 'Ativos SharePoint', value: s.activeSharePoint, onclick: () => table.setFilter('spo') }, { label: 'Ativos OneDrive', value: s.activeOneDrive, onclick: () => table.setFilter('odb') },
          { label: 'Teams licenciado, sem uso', value: s.teamsLicensedInactive, cls: 'warn', onclick: () => table.setFilter('teamsidle') },
          { label: 'Licenciados sem nenhuma atividade', value: s.licensedNoActivity ?? 0, cls: s.licensedNoActivity ? 'danger' : 'ok', onclick: () => table.setFilter('none'), title: 'Com produto atribuído e sem atividade em Exchange, Teams, SharePoint e OneDrive no período — candidatos a revisão de licença.' },
        ]));
        if (s.licensedTeams || s.licensedExchange) {
          const lic = rows.filter((r) => r.products?.length && !r.deleted), act = lic.filter((r) => !r.noActivity).length;
          box.append(vizRow(
            vizCard('Atividade dos licenciados', donut([['Com atividade', act, () => table.setFilter('active'), 'ok'], ['Sem nenhuma atividade', lic.length - act, () => table.setFilter('none'), 'danger']], { centerLabel: t('usuários') })),
            vizCard('Adoção no período', barList([['Exchange', s.activeExchange], ['Teams', s.activeTeams], ['SharePoint', s.activeSharePoint], ['OneDrive', s.activeOneDrive]].map(([k, v]) => [raw(k), v]), s.users))));
        }
        if (s.refreshDate) box.append(h('p', { class: 'muted small' }, t('Dados da Microsoft atualizados até {s}.', { s: isoDay(s.refreshDate) })));
        const table = DataTable({ rows, name: 'uso-m365', onRowClick: (r) => modal(hidden ? t('Usuário (oculto)') : (r.displayName || r.upn), kv([['UPN', raw(hidden ? t('Oculto pela Microsoft') + ' · ' + r.upn : r.upn)], ['Produtos', r.products?.length ? raw(r.products.join(', ')) : '—'], ['Exchange', isoDay(r.exchange)], ['Teams', isoDay(r.teams)], ['SharePoint', isoDay(r.sharePoint)], ['OneDrive', isoDay(r.oneDrive)], ['Mensagens Teams', r.teamsMessages ?? '—'], ['Reuniões Teams', r.teamsMeetings ?? '—'], ['Chamadas Teams', r.teamsCalls ?? '—'], ['Excluído', yesNo(r.deleted)]])), columns: [
          hidden
            ? { key: 'upn', label: 'Usuário (oculto)', render: (r) => h('span', { class: 'mono muted', title: r.upn }, raw(shortId(r.upn))) }
            : { key: 'displayName', label: 'Usuário' },
          ...(hidden ? [] : [{ key: 'upn', label: 'UPN' }]),
          { key: 'products', label: 'Produtos', render: (r) => r.products?.length ? h('span', { title: r.products.join('\n') }, raw(r.products[0] + (r.products.length > 1 ? ` (+${r.products.length - 1})` : ''))) : '—', export: (r) => r.products },
          { key: 'exchange', label: 'Exchange', render: (r) => isoDay(r.exchange), export: (r) => r.exchange }, { key: 'teams', label: 'Teams', render: (r) => isoDay(r.teams), export: (r) => r.teams },
          { key: 'sharePoint', label: 'SharePoint', render: (r) => isoDay(r.sharePoint), export: (r) => r.sharePoint }, { key: 'oneDrive', label: 'OneDrive', render: (r) => isoDay(r.oneDrive), export: (r) => r.oneDrive },
          { key: 'teamsMessages', label: 'Msgs Teams', render: (r) => r.teamsMessages ?? '—' }, { key: 'teamsMeetings', label: 'Reuniões Teams', render: (r) => r.teamsMeetings ?? '—' },
          ...(hidden ? [] : [{ key: 'deleted', label: 'Excluído', render: (r) => (r.deleted ? badge('Sim', 'warn') : '—'), exportOnly: false }]),
          { key: 'teamsCalls', label: 'Chamadas Teams', exportOnly: true },
          { key: 'hasExchange', label: 'Licença com Exchange', exportOnly: true, export: (r) => !!r.hasExchange }, { key: 'hasTeams', label: 'Licença com Teams', exportOnly: true, export: (r) => !!r.hasTeams },
          { key: 'noActivity', label: 'Sem nenhuma atividade', exportOnly: true, export: (r) => !!r.noActivity },
        ], filters: [
          { id: 'none', label: 'Licenciados sem atividade', fn: (r) => r.products?.length && r.noActivity && !r.deleted }, { id: 'active', label: 'Licenciados com atividade', fn: (r) => r.products?.length && !r.noActivity && !r.deleted }, { id: 'teamsidle', label: 'Teams licenciado, sem uso', fn: (r) => r.hasTeams && !r.teams && !r.deleted }, { id: 'noexch', label: 'Sem atividade Exchange', fn: (r) => r.hasExchange && !r.exchange && !r.deleted },
          { id: 'exch', label: 'Ativos Exchange', fn: (r) => r.exchange && !r.deleted }, { id: 'teams', label: 'Ativos Teams', fn: (r) => r.teams && !r.deleted }, { id: 'spo', label: 'Ativos SharePoint', fn: (r) => r.sharePoint && !r.deleted }, { id: 'odb', label: 'Ativos OneDrive', fn: (r) => r.oneDrive && !r.deleted }, { id: 'deleted', label: 'Excluídos', fn: (r) => r.deleted },
        ] });
        box.append(table);
        return box;
      }, { subtitle: 'Veja quais serviços do Microsoft 365 e recursos do Teams os usuários utilizaram. As datas indicam a atividade mais recente no período selecionado.', graphSource: '/reports/getOffice365ActiveUserDetail /reports/getTeamsUserActivityUserDetail', controls: form, ring: (p) => {
        const lic = (p.rows || []).filter((r) => r.products?.length && !r.deleted), act = lic.filter((r) => !r.noActivity).length, pc = pctOf(act, lic.length);
        return lic.length ? { pct: pc, label: 'dos usuários licenciados com atividade no período', caption: t('{a} de {b} usuários', { a: num(act), b: num(lic.length) }), tone: toneFor(pc, 90, 75) } : null;
      } }));
    }
    draw();
    return wrap;
  };

  /* ------------------------------------------------- shared: concealed names (usage reports) */
  const REPORT_SETTINGS_URL = 'https://admin.microsoft.com/#/Settings/Services/:/Settings/L1/Reports';
  /**
   * Shows (conceal=false) or conceals again the names in the tenant's Microsoft 365 usage reports, with the
   * admin's delegated token (Graph PATCH /admin/reportSettings), then regenerates the usage reports.
   * Must be called from a click: the Microsoft authorization opens in a popup.
   */
  function setReportNames(conceal, btn) {
    const cap = 'reports';
    const run = async () => {
      if (btn) btn.disabled = true;
      try {
        const r = await api('actions/reports', { method: 'POST', body: { conceal } });
        App.namesConcealed = !!r.concealed;
        for (const k of Object.keys(App.cache)) if (/^(mailboxes|storage|usage)\b/.test(k)) delete App.cache[k];
        toast(t(r.concealed ? 'Nomes ocultados novamente nos relatórios de uso.' : 'Nomes exibidos. Gerando os relatórios novamente — a Microsoft pode levar alguns minutos para aplicar.'), 'ok', 7000);
        App.go(App.view, true);
      } catch (e) {
        if (e.data && e.data.code === 'j365_action_auth') { Actions.until[cap] = 0; toast(t('A autorização expirou. Clique de novo para autorizar.'), 'warn', 6000); }
        else if (e.message !== 'unauthenticated') toast(e.message, 'danger', 9000);
      } finally { if (btn) btn.disabled = false; }
    };
    if (Actions.ready(cap, 60)) run();
    else Actions.authorize(cap).then(run).catch((p) => Actions.explain(cap, p));
  }
  function concealedBanner() {
    const info = Actions.info('reports');
    const can = !!(info.enabled && info.allowed);
    const steps = h('ol', { class: 'steps' },
      h('li', null, 'Um Global Administrator abre o Microsoft 365 admin center → Configurações → Configurações da organização → Serviços → Relatórios.'),
      h('li', null, 'Desmarca "Exibir nomes ocultos de usuários, grupos e sites em todos os relatórios" e salva (confirme antes com a política de privacidade da empresa).'),
      h('li', null, 'Aguarde alguns minutos e clique em "Gerar novamente".'));
    return h('div', { class: 'concealed' },
      h('div', { class: 'conc-ic' }, icon('eyeOff')),
      h('div', { class: 'conc-body' },
        h('strong', null, 'A Microsoft está ocultando os nomes neste tenant'),
        h('p', null, 'Os relatórios de uso (caixas de correio, OneDrive, SharePoint e adoção) chegam com códigos no lugar de nomes e e-mails. É a configuração de privacidade dos relatórios do Microsoft 365 admin center, ligada por padrão desde 2021 — por isso não dá para identificar nem agir sobre as contas.'),
        h('div', { class: 'conc-act' },
          h('button', { class: 'btn primary sm', type: 'button', disabled: !can, title: can ? null : t('Requer {s} (atribuição ativa).', { s: (info.roles && info.roles.length ? info.roles : ['Global Administrator']).join(', ') }), onclick: (e) => setReportNames(false, e.currentTarget) }, icon('eye'), 'Mostrar nomes reais'),
          h('a', { class: 'btn sm', href: REPORT_SETTINGS_URL, target: '_blank', rel: 'noopener' }, 'Abrir no Microsoft 365 admin center')),
        h('small', null, can
          ? 'Um clique altera essa configuração do tenant com a sua conta e gera o relatório de novo. Vale para todos os relatórios de uso do Microsoft 365, fica registrado na auditoria e pode ser desfeito em Configurações → Privacidade dos relatórios.'
          : (info.enabled ? 'Somente um Global Administrator (atribuição ativa) pode alterar essa configuração — pelo botão acima ou manualmente:' : 'Para exibir os nomes, um Global Administrator altera a configuração manualmente:')),
        can ? null : steps));
  }
  const shortId = (v) => (v ? String(v).slice(0, 8).toLowerCase() + '…' : '—');
  const pctCls = (p) => (p === null || p === undefined ? '' : p >= 90 ? 'danger' : p >= 80 ? 'warn' : '');
  const usage = (used, cap, pct) => h('div', { class: 'usage-cell' }, pct === null || pct === undefined ? null : meter(pct, pctCls(pct)), h('small', null, raw(bytes(used) + (cap ? ' / ' + bytes(cap) : ''))));
  const download = (text, file, type = 'text/plain') => { const url = URL.createObjectURL(new Blob([text], { type: type + ';charset=utf-8' })); const a = h('a', { href: url, download: file }); document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 4000); };

  /** Progress + results dialog shared by actions. run(dlg) returns the job result. */
  function actionDialog(title, intro, confirmLabel, cap, buildBody, onDone) {
    const body = h('div', { class: 'act-dlg' }, intro);
    const foot = h('div', { class: 'act-foot' });
    const go = h('button', { class: 'btn primary', type: 'button' }, icon('bolt'), confirmLabel);
    const cancel = h('button', { class: 'btn', type: 'button' }, 'Cancelar');
    foot.append(cancel, go);
    body.append(foot);
    let closed = false;
    const d = J.dialog({ title: raw(title), body: h('div', { class: 'dlg-body' }, body), wide: true, onClose: () => { closed = true; } });
    cancel.onclick = () => d.close();
    const progress = (job) => {
      const pct = job.total > 0 ? Math.round((100 * job.progress) / job.total) : null;
      body.innerHTML = '';
      body.append(h('div', { class: 'act-run' }, h('div', { class: 'spinner' }), h('div', null, h('strong', null, job.message ? t(job.message) : t('Executando…')), pct !== null ? h('div', { class: 'progress' }, h('i', { style: `width:${pct}%` })) : h('div', { class: 'progress indeterminate' }, h('i')))));
    };
    const fail = (msg, retry) => {
      body.innerHTML = '';
      body.append(h('div', { class: 'alert danger' }, h('strong', null, 'Falha: '), msg));
      if (retry) body.append(h('div', { class: 'act-foot' }, h('button', { class: 'btn primary', type: 'button', onclick: retry }, icon('bolt'), 'Autorizar e tentar de novo')));
    };
    const setTitle = (txt) => { const el = d.el && d.el.querySelector('.dlg-head h2'); if (el) el.textContent = txt; };
    let running = false;
    const execute = async (payloadFn) => {
      if (running) return; // one job per confirmation
      running = true;
      progress({ message: 'Iniciando…', progress: 0, total: 0 });
      try {
        const job = await Actions.start(cap, payloadFn());
        const res = await followJob(job, progress, { exo: cap === 'exchange' });
        setTitle(t('Resultado'));
        body.innerHTML = '';
        body.append(buildBody(res));
        if (onDone) onDone(res);
      } catch (e) {
        running = false;
        if (e.needAuth) fail(e.message, (ev) => { if (ev && ev.currentTarget) ev.currentTarget.disabled = true; Actions.authorize(cap).then(() => { if (!closed) execute(payloadFn); }).catch((p) => { d.close(); Actions.explain(cap, p); }); });
        else if (e.message !== 'unauthenticated') fail(e.message);
      }
    };
    return { d, body, go, execute, fail, isClosed: () => closed, busy: false };
  }
  /** Banner on top of a result when the job stopped early (authorization expired / actions turned off). */
  const haltNote = (s) => (s && s.halted ? h('div', { class: 'alert warn' }, h('strong', null, s.halted === 'auth' ? 'A autorização expirou durante a execução. ' : 'As ações foram desativadas neste tenant durante a execução. '), t('{n} item(ns) não foram processados — estão marcados no resultado. Os demais foram concluídos.', { n: num(s.skipped || 0) })) : null);
  /** Click handler body: authorize in the same click (popup) when needed, then execute. */
  function authorizeThen(cap, dlg, payloadFn, need = 120) {
    if (dlg.busy || dlg.go.disabled) return;
    dlg.busy = true; // one job per confirmation, even with a double click or options toggled meanwhile
    dlg.go.disabled = true;
    if (Actions.ready(cap, need)) { dlg.execute(payloadFn); return; }
    // Closing the dialog while the Microsoft window is open cancels: nothing is started afterwards.
    Actions.authorize(cap).then(() => { if (!dlg.isClosed()) dlg.execute(payloadFn); }).catch((p) => { if (!dlg.isClosed()) dlg.d.close(); Actions.explain(cap, p); });
  }

  /* ------------------------------------------------------------ actions: Intune remote actions */
  const DEV_ACTIONS = {
    sync: { label: 'Sincronizar', icon: 'sync', verb: 'Sincronizar', title: 'Check-in imediato no Intune', desc: 'O dispositivo recebe na hora as políticas, os aplicativos e as ações pendentes, sem esperar o ciclo automático (até 8 horas).', platforms: 'Windows, macOS, iOS/iPadOS e Android', rbac: 'Remote tasks → Sync devices' },
    restart: { label: 'Reiniciar', icon: 'restart', verb: 'Reiniciar', title: 'Reinício remoto', desc: 'Reinicia o dispositivo em até 5 minutos. No Windows o usuário vê um aviso; no macOS, iOS/iPadOS e Android pode não haver aviso — trabalho não salvo pode ser perdido.', platforms: 'Windows, macOS, iOS/iPadOS supervisionado e Android corporativo (dedicado, totalmente gerenciado ou AOSP)', rbac: 'Remote tasks → Reboot now', tone: 'warn', ack: true },
    quickScan: { label: 'Verificação rápida', icon: 'scan', verb: 'Executar verificação rápida', title: 'Verificação rápida do Microsoft Defender', desc: 'Verifica os locais onde malware costuma se instalar. Termina em minutos, sem impacto perceptível para o usuário.', platforms: 'Windows', rbac: 'Remote tasks → Windows defender' },
    fullScan: { label: 'Verificação completa', icon: 'scan', verb: 'Executar verificação completa', title: 'Verificação completa do Microsoft Defender', desc: 'Verifica todos os arquivos e processos em execução. Pode levar horas e consumir CPU e disco — prefira fora do horário de trabalho.', platforms: 'Windows', rbac: 'Remote tasks → Windows defender', tone: 'warn' },
    defenderUpdate: { label: 'Atualizar proteção', icon: 'shieldUp', verb: 'Atualizar a proteção', title: 'Atualizar a inteligência de segurança', desc: 'Baixa agora as definições mais recentes do Microsoft Defender Antivirus, sem esperar a atualização automática.', platforms: 'Windows', rbac: 'Remote tasks → Windows defender' },
  };
  const DEV_MAX = 1000, MBX_MAX = 200; // same limits as the server (Device_Sync::MAX, Mailbox_Archive::MAX)
  const SYNC_RESULT = { ok: ['Comando enviado', 'ok'], not_found: ['Não encontrado', 'warn'], forbidden: ['Sem permissão (RBAC)', 'danger'], unsupported: ['Não suportado', 'warn'], error: ['Falha', 'danger'], skipped: ['Não processado', 'warn'] };
  /* Same estimates as the server (it refuses to start when the authorization would expire mid-way). */
  const syncNeed = (n) => Math.min(3000, 90 + Math.ceil(Math.max(1, n) / 160) * 30);
  const archiveNeed = (n, ae, mfa, op = 'enable') => Math.min(3000, 90 + Math.max(1, n) * 3 * (op === 'enable' ? 1 + (ae ? 1 : 0) + (mfa ? 1 : 0) : 1));
  const ANDROID_CORP = /^(androidEnterpriseDedicatedDevice|androidEnterpriseFullyManaged|androidAOSPUserOwnedDeviceEnrollment|androidAOSPUserlessDeviceEnrollment)$/;
  /** '' when the Intune action applies to the device (Microsoft's documented platforms), otherwise why not. */
  const devWhy = (action, r) => {
    if (!r.intuneId) return 'Não gerenciado pelo Intune';
    const a = String(r.managementAgent || '').toLowerCase();
    if (a && !/mdm|aosp|intuneclient/.test(a)) return t('Gerenciado por outro agente ({s}); as ações remotas do Intune exigem MDM.', { s: AGENT[r.managementAgent] || r.managementAgent });
    const p = r.platform;
    if (action === 'sync') {
      if (p === 'linux') return 'O Intune não oferece a ação Sincronizar para Linux (o agente faz check-in sozinho).';
      if (p === 'chromeos') return 'ChromeOS é gerenciado pelo Google Admin; a ação não se aplica.';
      return '';
    }
    if (action === 'restart') {
      if (p === 'windows' || p === 'macos') return '';
      if (p === 'ios' || p === 'ipados') return r.supervised ? '' : 'iOS/iPadOS: o reinício remoto exige dispositivo supervisionado (Apple Business Manager/ADE).';
      if (p === 'android') return ANDROID_CORP.test(r.enrollmentType || '') ? '' : 'Android: o reinício remoto só vale para dispositivos corporativos (dedicado, totalmente gerenciado ou AOSP).';
      return 'Plataforma sem reinício remoto no Intune.';
    }
    return p === 'windows' ? '' : 'Somente Windows (Microsoft Defender Antivirus).';
  };
  const MAIN_PLATFORMS = ['windows', 'macos', 'ios', 'android', 'linux'];
  /** Platform segments: the five main platforms are always shown (with 0 when absent), others only when present. */
  const platformSegments = (value, onChange) => {
    const P = (id) => (r) => (id === 'ios' ? r.platform === 'ios' || r.platform === 'ipados' : r.platform === id);
    const ic = { windows: 'device', macos: 'laptop', ios: 'phone', android: 'phone', linux: 'laptop', windowsServer: 'server', chromeos: 'laptop', other: 'box' };
    return { label: 'Plataforma', value, onChange, items: [
      { id: 'all', label: 'Todas', icon: icon('layers') },
      ...[['windows', 'Windows'], ['macos', 'macOS'], ['ios', 'iOS/iPadOS'], ['android', 'Android'], ['linux', 'Linux'], ['windowsServer', 'Windows Server'], ['chromeos', 'ChromeOS'], ['other', 'Outros']]
        .map(([id, label]) => ({ id, label, fn: P(id), icon: icon(ic[id]), always: MAIN_PLATFORMS.includes(id) })),
    ] };
  };
  /** Why the device list is empty: Intune unavailable (licence, permission) or devices not enrolled. */
  function intuneEmpty(p) {
    const m = p._meta || {}, entra = (p.rows || []).length;
    const perm = (App.me && App.me.permissions) || {};
    let title, body, steps = [], tone = 'info', act = null;
    if (m.intuneError === 'license') {
      title = 'Este tenant não tem o Microsoft Intune licenciado'; tone = 'warn';
      body = 'O Microsoft Graph respondeu que o serviço de gerenciamento de dispositivos não se aplica a este tenant.';
      steps = ['Atribua uma licença que inclua o Intune (Microsoft 365 E3/E5, Business Premium, EMS E3/E5 ou Intune Plano 1).', 'No Intune, confirme a autoridade de MDM e o registro automático de dispositivos.', 'Depois de registrar os dispositivos, clique em "Gerar novamente".'];
    } else if (m.intuneError === 'unavailable') {
      title = 'O serviço do Intune não respondeu para este tenant'; tone = 'warn';
      body = 'O Microsoft Graph não encontrou o serviço de gerenciamento de dispositivos (404). Costuma acontecer em tenants sem o Intune provisionado ou logo após a ativação da licença.';
      steps = ['Confirme no Intune admin center que o tenant tem a autoridade de MDM definida.', 'Aguarde alguns minutos e clique em "Gerar novamente".'];
    } else if (m.intuneError === 'forbidden' || m.intune === false) {
      title = 'O portal não conseguiu ler o Intune neste tenant'; tone = 'warn';
      if (CFG.tenancy === 'per_tenant') {
        body = 'O Microsoft Graph negou a leitura do Intune com a sua conta. Com permissões delegadas, a conta precisa de uma função com leitura do Intune e o aplicativo do seu tenant precisa da permissão DeviceManagementManagedDevices.Read.All.';
        steps = ['Confirme que a sua conta tem uma função com leitura do Intune (Intune Administrator, Global Reader ou uma função do RBAC do Intune).', 'Se o portal mostrar o aviso "Atualizar o aplicativo", um administrador atualiza o aplicativo do tenant.', 'Volte aqui e clique em "Gerar novamente".'];
      } else {
        body = 'A permissão de aplicativo DeviceManagementManagedDevices.Read.All não está aprovada neste tenant (ou foi revogada). Sem ela, os dispositivos do Intune não aparecem.';
        steps = ['Um Global Administrator ou Privileged Role Administrator aprova as permissões do aplicativo.', 'Volte aqui e clique em "Gerar novamente".'];
        if (perm.approveUrl) act = h('a', { class: 'btn primary sm', href: perm.approveUrl }, 'Aprovar permissões');
      }
    } else if (entra > 0) {
      title = t('{n} dispositivo(s) no Entra ID, nenhum registrado no Intune', { n: num(entra) });
      body = 'Os dispositivos existem no diretório, mas não estão inscritos no Intune — por isso não podem receber ações remotas.';
      steps = ['Entra ID → Mobilidade (MDM e WIP) → Microsoft Intune: defina o escopo de usuário do MDM (Alguns/Todos).', 'Windows: registro automático (Autopilot ou ingresso no Entra); macOS/iOS: Apple Business Manager (ADE) ou Portal da Empresa; Android: Android Enterprise.', 'Verifique as restrições de registro do Intune (plataforma, propriedade pessoal, limite por usuário).'];
    } else {
      title = 'Nenhum dispositivo neste tenant';
      body = 'O Microsoft Entra ID e o Intune não retornaram dispositivos.';
    }
    return h('div', { class: 'empty-state ' + tone },
      h('div', { class: 'es-ic' }, icon('device')),
      h('div', { class: 'es-body' }, h('strong', null, title), h('p', null, body), steps.length ? h('ol', { class: 'steps' }, ...steps.map((x) => h('li', null, x))) : null,
        h('div', { class: 'es-act' }, act, h('a', { class: 'btn sm', href: 'https://intune.microsoft.com/#view/Microsoft_Intune_DeviceSettings/DevicesMenu/~/allDevices', target: '_blank', rel: 'noopener' }, 'Abrir o Intune admin center'))));
  }
  /** Confirmation + execution of an Intune remote action on a list of devices (also used from the device details). */
  function deviceActionDialog(list, action, onDone) {
    const A = DEV_ACTIONS[action];
    const byP = {};
    list.forEach((r) => { const k = PLATFORM[r.platform] || r.platform; byP[k] = (byP[k] || 0) + 1; });
    const ack = A.ack ? h('input', { type: 'checkbox' }) : null;
    const intro = h('div', null,
      h('div', { class: 'act-sum' }, ...Object.entries(byP).map(([k, v]) => h('span', { class: 'role-chip' }, raw(t(k) + ' · ' + num(v))))),
      h('p', { class: 'lead-sm' }, A.desc),
      A.tone === 'warn' && !A.ack ? h('div', { class: 'alert warn' }, h('strong', null, 'Atenção: '), A.desc) : null,
      ack ? h('label', { class: 'opt ack' }, ack, h('span', null, h('strong', null, 'Entendo que os dispositivos serão reiniciados. '), 'Avise os usuários antes quando possível.')) : null,
      h('ul', { class: 'act-notes' },
        h('li', null, 'O comando sai agora; cada dispositivo executa quando estiver ligado e conectado (Windows via WNS, Apple e Android via push).'),
        h('li', null, t('Executado como {s}. O Intune registra a ação no log de auditoria em seu nome.', { s: App.me.user.upn })),
        h('li', null, t('Permissão do Intune necessária: {s} (ou função Intune Administrator).', { s: A.rbac })),
        !Actions.ready('intune') && CFG.tenancy !== 'per_tenant' ? h('li', null, 'Na primeira vez, uma janela da Microsoft confirma a autorização (normalmente sem senha, pelo SSO).') : null));
    const dlg = actionDialog(t('{a}: {n} dispositivo(s)', { a: t(A.label), n: num(list.length) }), intro, t(A.verb), 'intune', (res) => {
      const s = res.summary || {};
      const out = h('div', null, haltNote(s), kpis([{ label: 'Comandos enviados', value: s.ok, cls: 'ok' }, { label: 'Falhas', value: s.failed, cls: s.failed ? 'danger' : 'ok' }, ...(s.skipped ? [{ label: 'Não processados', value: s.skipped, cls: 'warn' }] : [])]));
      out.append(DataTable({ rows: res.rows || [], name: 'acao-dispositivos-resultado', compact: true, pageSize: 25, columns: [
        { key: 'name', label: 'Dispositivo', render: (r) => raw(r.name || r.id) }, { key: 'os', label: 'Plataforma', render: (r) => t(r.os || '—') },
        { key: 'status', label: 'Resultado', render: (r) => badge(...(SYNC_RESULT[r.status] || [r.status, ''])), export: (r) => (SYNC_RESULT[r.status] || [r.status])[0] },
        { key: 'message', label: 'Detalhe', wrap: true, render: (r) => reason(r.message), export: (r) => reason(r.message) },
      ] }));
      return out;
    }, (res) => { toast(t('{n} dispositivo(s) receberam o comando.', { n: num((res.summary || {}).ok || 0) }), 'ok'); if (onDone) onDone(res); });
    if (A.tone === 'warn') dlg.go.classList.add('warn');
    if (ack) { dlg.go.disabled = true; ack.onchange = () => { if (!dlg.busy) dlg.go.disabled = !ack.checked; }; }
    dlg.go.onclick = () => { if (ack && !ack.checked) return; if (ack) ack.disabled = true; authorizeThen('intune', dlg, () => ({ action, confirm: action + ':' + list.length, devices: list.map((r) => ({ id: r.intuneId, name: r.name, os: PLATFORM[r.platform] || r.platform })) }), syncNeed(list.length)); };
  }

  views.sync = () => reportPanel('devices', { stale_days: App.params.devStale || App.me.defaults.stale_device_days }, 'Ações em dispositivos', (p) => {
    const run = Actions.can('intune');
    const rows = (p.rows || []).filter((r) => r.intuneId);
    const box = h('div');
    if (!rows.length) { box.append(intuneEmpty(p)); return box; }
    let action = DEV_ACTIONS[App.params.devAction] ? App.params.devAction : 'sync';
    const sel = new Set();
    const why = (r) => devWhy(action, r);
    const hero = h('div', { class: 'action-hero' });
    const picker = h('div', { class: 'act-picker', role: 'radiogroup', 'aria-label': t('Ação') });
    const kpiSlot = h('div');
    const gate = Actions.gate('intune', 'Executar ações remotas');
    const drawHero = () => {
      const A = DEV_ACTIONS[action];
      hero.className = 'action-hero' + (A.tone ? ' ' + A.tone : '');
      hero.innerHTML = '';
      hero.append(h('div', { class: 'ah-ic' }, icon(A.icon)), h('div', null, h('strong', null, A.title), h('p', null, A.desc),
        h('div', { class: 'ah-meta' }, h('span', null, icon('device'), t('Plataformas: {s}', { s: t(A.platforms) })), h('span', null, icon('key'), t('RBAC: {s}', { s: A.rbac })))));
      picker.innerHTML = '';
      for (const [k, X] of Object.entries(DEV_ACTIONS)) {
        const n = rows.filter((r) => !devWhy(k, r)).length;
        picker.append(h('button', { type: 'button', role: 'radio', 'aria-checked': k === action ? 'true' : 'false', class: 'act-pick' + (k === action ? ' on' : '') + (X.tone ? ' ' + X.tone : ''), onclick: () => setAction(k) },
          icon(X.icon), h('span', null, X.label), h('span', { class: 'chip-n', title: t('{n} elegível(is)', { n: num(n) }) }, raw(num(n)))));
      }
      const can = rows.filter((r) => !why(r));
      const stale = rows.filter((r) => !devWhy('sync', r) && (r.daysSinceSync === null || r.daysSinceSync > 7));
      kpiSlot.innerHTML = '';
      kpiSlot.append(kpis([
        { label: 'Gerenciados pelo Intune', value: rows.length, onclick: () => table.setSegment('all') },
        { label: t('Elegíveis: {s}', { s: t(A.label) }), value: can.length, cls: 'ok', onclick: () => table.setFilter('eligible') },
        { label: 'Sem sincronizar há 7+ dias', value: stale.length, cls: stale.length ? 'warn' : 'ok', onclick: () => table.setFilter('stale') },
        { label: 'Não conformes', value: rows.filter((r) => String(r.compliance).toLowerCase() === 'noncompliant').length, cls: 'danger', onclick: () => table.setFilter('noncompliant') },
      ]));
    };
    const setAction = (k) => {
      action = k; App.params.devAction = k;
      let dropped = 0;
      for (const r of rows) if (sel.has(r.intuneId) && why(r)) { sel.delete(r.intuneId); dropped++; }
      if (dropped) toast(t('{n} dispositivo(s) removido(s) da seleção: a ação escolhida não se aplica a eles.', { n: num(dropped) }), 'warn');
      drawHero(); drawBar(); table.redraw();
    };
    const bar = h('div', { class: 'selbar' });
    const quick = h('div', { class: 'quick-sel' });
    const drawQuick = () => {
      if (!table) return;
      quick.innerHTML = '';
      const visible = table.currentRows().filter((r) => !why(r) && run);
      const notSel = visible.filter((r) => !sel.has(r.intuneId));
      quick.append(h('span', { class: 'muted small' }, t('{a} elegível(is) nesta lista para "{b}"', { a: num(visible.length), b: t(DEV_ACTIONS[action].label) })),
        visible.length ? h('button', { type: 'button', class: 'btn sm', disabled: !notSel.length, onclick: () => { notSel.forEach((r) => sel.add(r.intuneId)); refresh(); } }, icon('check'), notSel.length ? t('Selecionar os {n} exibidos', { n: num(notSel.length) }) : t('Todos os exibidos estão selecionados')) : null);
    };
    function drawBar() {
      bar.innerHTML = '';
      bar.classList.toggle('show', sel.size > 0);
      drawQuick();
      if (!sel.size) return;
      const A = DEV_ACTIONS[action];
      const over = sel.size > DEV_MAX;
      const byP = {};
      for (const r of rows) if (sel.has(r.intuneId)) { const k = PLATFORM[r.platform] || r.platform; byP[k] = (byP[k] || 0) + 1; }
      bar.append(h('div', { class: 'sb-txt' }, h('strong', null, t('{n} selecionado(s)', { n: num(sel.size) })), h('span', null, raw(Object.entries(byP).map(([k, v]) => t(k) + ' ' + num(v)).join(' · ')))),
        h('div', { class: 'sb-act' },
          h('button', { class: 'btn sm', type: 'button', onclick: () => { sel.clear(); refresh(); } }, 'Limpar seleção'),
          h('button', { class: 'btn primary' + (A.tone === 'warn' ? ' warn' : ''), type: 'button', disabled: !run || over, onclick: () => deviceActionDialog(rows.filter((r) => sel.has(r.intuneId)), action, () => { sel.clear(); refresh(); }) }, icon(A.icon), t('{a} ({n})', { a: t(A.label), n: num(sel.size) }))));
      if (over) bar.append(h('small', { class: 'sb-note warn' }, t('Selecione no máximo {n} dispositivos por execução.', { n: num(DEV_MAX) })));
    }
    const refresh = () => { drawBar(); table.redraw(); };
    let table = null;
    table = DataTable({
      rows, name: 'acoes-dispositivos', pageSize: 50,
      segments: platformSegments(App.params.syncPlatform || 'all', (id) => { App.params.syncPlatform = id; }),
      onDraw: () => drawQuick(),
      select: { set: sel, id: (r) => r.intuneId, can: (r) => !why(r) && run, why: (r) => why(r) || t('Sem permissão para executar ações'), onChange: () => drawBar() },
      searchKeys: ['name', 'primaryUser', 'serial', 'model', 'osRelease', 'osVersion', 'category'],
      onRowClick: deviceDetail,
      columns: [
        { key: 'name', label: 'Dispositivo' },
        { key: 'platform', label: 'Plataforma', render: (r) => PLATFORM[r.platform] || r.platform, export: (r) => PLATFORM[r.platform] || r.platform },
        { key: 'osRelease', label: 'Versão', render: (r) => raw(r.osRelease || r.osVersion || '—'), export: (r) => r.osRelease || r.osVersion },
        { key: 'primaryUser', label: 'Usuário principal', render: (r) => raw(r.primaryUser || '—') },
        { key: 'lastSyncIntune', label: 'Última sincronização', render: (r) => fmtDate(r.lastSyncIntune), export: (r) => r.lastSyncIntune, sortValue: (r) => r.lastSyncIntune || '' },
        { key: 'compliance', label: 'Conformidade', render: (r) => { const c = COMPLIANCE[String(r.compliance || '').toLowerCase()] || [r.compliance || '—', '']; return badge(c[0], c[1]); }, export: (r) => (COMPLIANCE[String(r.compliance || '').toLowerCase()] || [r.compliance])[0] },
        { key: 'eligible', label: 'Ação escolhida', render: (r) => { const w = why(r); const b = w ? badge('Não se aplica', '') : badge('Elegível', 'ok'); if (w) b.title = t(w); return b; }, export: (r) => why(r) ? t(why(r)) : t('Elegível') },
        { key: 'serial', label: 'Serial', exportOnly: true }, { key: 'model', label: 'Modelo', exportOnly: true }, { key: 'enrollmentType', label: 'Tipo de registro', exportOnly: true, export: (r) => ENROLL[r.enrollmentType] || r.enrollmentType },
      ],
      filters: [
        { id: 'eligible', label: 'Elegíveis para a ação', fn: (r) => !why(r) },
        { id: 'stale', label: 'Sem sincronizar há 7+ dias', fn: (r) => r.daysSinceSync === null || r.daysSinceSync > 7 },
        { id: 'noncompliant', label: 'Não conformes', fn: (r) => String(r.compliance).toLowerCase() === 'noncompliant' },
        { id: 'selected', label: 'Selecionados', fn: (r) => sel.has(r.intuneId) },
        { id: 'unsupported', label: 'Não se aplica', fn: (r) => !!why(r) },
      ],
    });
    drawHero();
    drawBar();
    box.append(picker, hero);
    if (gate) box.append(gate);
    box.append(kpiSlot, quick, table, bar);
    return box;
  }, { subtitle: 'Selecione dispositivos do Intune para sincronizar ou reiniciar, iniciar uma verificação do Defender ou atualizar suas assinaturas. As ações usam as permissões da sua conta.', graphSource: '/deviceManagement/managedDevices/{id}/syncDevice /deviceManagement/managedDevices/{id}/rebootNow /deviceManagement/managedDevices/{id}/windowsDefenderScan /deviceManagement/managedDevices/{id}/windowsDefenderUpdateSignatures' });

  /* ------------------------------------------------------------ Exchange mailboxes */
  const QUOTA = { ok: ['Normal', 'ok'], warning: ['Em aviso', 'warn'], send_blocked: ['Não envia', 'danger'], blocked: ['Não envia nem recebe', 'danger'] };
  const ARCH_RESULT = { ok: ['Ativado', 'ok'], already: ['Já estava ativo', 'info'], error: ['Falha', 'danger'], skipped: ['Ignorado', ''], unlicensed: ['Sem licença elegível', 'warn'], off: ['—', ''] };
  const ARCH_OFF = { ok: ['Arquivo desativado', 'warn'], already: ['Já estava sem arquivo', 'info'], error: ['Falha', 'danger'], skipped: ['Ignorado', ''], off: ['—', ''] };
  const MBX_OPS = {
    enable: { label: 'Ativar arquivamento', icon: 'archive', verb: 'Executar' },
    process: { label: 'Processar agora', icon: 'play', verb: 'Processar agora' },
    disable: { label: 'Desativar arquivo morto', icon: 'trash', verb: 'Desativar o arquivo morto', tone: 'danger' },
  };
  // PowerShell treats ' and the typographic quotes U+2018–U+201B alike inside single-quoted strings.
  const psQuote = (s) => "'" + String(s).replace(/['‘-‛]/g, (c) => c + c) + "'";
  function archiveScript(list, op, autoExpand, mfa) {
    const head = [
      '# ' + APP + ' — ' + t(MBX_OPS[op].label) + ' (' + new Date().toISOString().slice(0, 16).replace('T', ' ') + ' UTC)',
      '# ' + t('Requer o módulo ExchangeOnlineManagement e a função Exchange Administrator (ou Recipient Management).'),
      '#Requires -Modules ExchangeOnlineManagement',
      "$ErrorActionPreference = 'Stop'",
    ];
    const boxes = ['$Mailboxes = @(', ...list.map((m, i) => `  @{ Upn = ${psQuote(m.upn)}; Eligible = $${m.expandOk ? 'true' : 'false'} }${i < list.length - 1 ? ',' : ''}`), ')', 'Connect-ExchangeOnline -ShowBanner:$false'];
    const tail = ['$Log | Format-Table -AutoSize', "$Log | Export-Csv -Path \".\\j365-" + op + "-$(Get-Date -Format yyyyMMdd-HHmm).csv\" -NoTypeInformation -Encoding UTF8", 'Disconnect-ExchangeOnline -Confirm:$false'];
    let body;
    if (op === 'disable') {
      body = [
        '# ' + t('ATENÇÃO: o conteúdo do arquivo não volta para a caixa principal. Ele pode ser reconectado em até 30 dias; depois a Microsoft o exclui permanentemente.'),
        '$Apply = $false   # ' + t('mude para $true para executar de verdade (com $false o script só simula, -WhatIf)'),
        ...boxes,
        '$Log = foreach ($m in $Mailboxes) {',
        '  $r = [ordered]@{ Mailbox = $m.Upn; Archive = ""; Error = "" }',
        '  try {',
        '    $mbx = Get-Mailbox -Identity $m.Upn',
        "    if ($mbx.ArchiveGuid -eq [guid]::Empty) { $r.Archive = 'NoArchive' }",
        "    elseif ($Apply) { Disable-Mailbox -Identity $m.Upn -Archive -Confirm:$false | Out-Null; $r.Archive = 'Disabled' }",
        "    else { Disable-Mailbox -Identity $m.Upn -Archive -WhatIf; $r.Archive = 'WhatIf' }",
        '  } catch { $r.Error = $_.Exception.Message }',
        '  [pscustomobject]$r',
        '}',
      ];
    } else if (op === 'process') {
      body = [...boxes, '$Log = foreach ($m in $Mailboxes) {', '  $r = [ordered]@{ Mailbox = $m.Upn; Processing = ""; Error = "" }', "  try { Start-ManagedFolderAssistant -Identity $m.Upn; $r.Processing = 'Started' } catch { $r.Error = $_.Exception.Message }", '  [pscustomobject]$r', '}'];
    } else {
      body = [
        '$AutoExpand = $' + (autoExpand ? 'true' : 'false') + '   # ' + t('irreversível: não pode ser desativada depois'),
        '$StartProcessing = $' + (mfa ? 'true' : 'false'),
        ...boxes,
        '$Log = foreach ($m in $Mailboxes) {',
        '  $r = [ordered]@{ Mailbox = $m.Upn; Archive = ""; AutoExpand = ""; Processing = ""; Error = "" }',
        '  try {',
        '    $mbx = Get-Mailbox -Identity $m.Upn',
        "    if ($mbx.ArchiveStatus -eq 'Active' -or $mbx.ArchiveGuid -ne [guid]::Empty) { $r.Archive = 'AlreadyEnabled' }",
        '    else {',
        '      try { Enable-Mailbox -Identity $m.Upn -Archive | Out-Null }',
        "      catch { if ($_.Exception.Message -match 'disconnect of shard') { Set-Mailbox -Identity $m.Upn -RemoveDisabledArchive -Confirm:$false; Enable-Mailbox -Identity $m.Upn -Archive | Out-Null } else { throw } }",
        "      $r.Archive = 'Enabled'",
        '    }',
        '    if ($AutoExpand) {',
        "      if (-not $m.Eligible) { $r.AutoExpand = 'SkippedNoLicense' }",
        "      elseif ($mbx.AutoExpandingArchiveEnabled) { $r.AutoExpand = 'AlreadyEnabled' }",
        "      else { Enable-Mailbox -Identity $m.Upn -AutoExpandingArchive | Out-Null; $r.AutoExpand = 'Enabled' }",
        '    }',
        "    if ($StartProcessing) { Start-ManagedFolderAssistant -Identity $m.Upn; $r.Processing = 'Started' }",
        '  } catch { $r.Error = $_.Exception.Message }',
        '  [pscustomobject]$r',
        '}',
      ];
    }
    return [...head, ...body, ...tail].join('\r\n') + '\r\n';
  }
  /* Auto-expanding is irreversible: the script only turns it on when it was chosen in the dialog. */
  function scriptDialog(list, op = 'enable', autoExpand = false, mfa = true) {
    const txt = archiveScript(list, op, autoExpand, mfa);
    modal(t('Script PowerShell (Exchange Online)'), h('div', null,
      h('p', { class: 'muted small' }, op === 'disable' ? 'Alternativa suportada pela Microsoft: execute em um computador com o módulo ExchangeOnlineManagement. Por segurança o script começa em modo simulação ($Apply = $false).' : 'Alternativa suportada pela Microsoft: execute em um computador com o módulo ExchangeOnlineManagement. O script ativa o arquivo morto e inicia o processamento; a expansão automática (irreversível) só é aplicada se $AutoExpand = $true, e apenas nas caixas elegíveis.'),
      h('pre', { class: 'code' }, raw(txt)),
      h('div', { class: 'actions' }, h('button', { class: 'btn', type: 'button', onclick: (e) => J.copyText(txt, e.currentTarget) }, 'Copiar'), h('button', { class: 'btn primary', type: 'button', onclick: () => download(txt, `j365-${op}-${stamp()}.ps1`) }, icon('download'), 'Baixar .ps1'))));
  }
  /** Confirmation + execution of a mailbox operation (enable | process | disable) on a list of mailboxes. */
  function mailboxDialog(list, op, onDone) {
    const O = MBX_OPS[op];
    const ignored = op === 'disable' ? list.filter((r) => !r.hasArchive).length : 0;
    if (op === 'disable') list = list.filter((r) => r.hasArchive); // nothing to disable on the others
    const withArch = list.filter((r) => r.hasArchive).length, noArch = list.length - withArch, elig = list.filter((r) => r.expandOk).length;
    const cbExpand = h('input', { type: 'checkbox' }), cbAck = h('input', { type: 'checkbox' }), cbMfa = h('input', { type: 'checkbox', checked: true });
    const typed = h('input', { class: 'j365-input', type: 'text', autocomplete: 'off', spellcheck: 'false', 'aria-label': t('Digite {s} para confirmar', { s: t('DESATIVAR') }) });
    const ack = h('label', { class: 'opt ack', hidden: true }, cbAck, h('span', null, h('strong', null, 'Entendo que é irreversível. '), 'Depois de ativada, a expansão automática não pode ser desligada e impede recuperar ou restaurar a caixa como inativa no futuro.'));
    const who = h('li', null, t('Executado como {s}. Exige a função Mail Recipients do Exchange (grupos Recipient Management ou Organization Management). Uma caixa sem permissão aparece como falha no resultado, sem afetar as demais.', { s: App.me.user.upn }));
    const first = !Actions.ready('exchange') && CFG.tenancy !== 'per_tenant' ? h('li', null, 'Na primeira vez, uma janela da Microsoft confirma a autorização (normalmente sem senha, pelo SSO).') : null;
    let intro;
    if (op === 'enable') {
      intro = h('div', null,
        h('label', { class: 'opt' }, h('input', { type: 'checkbox', checked: true, disabled: true }), h('span', null, h('strong', null, 'Ativar o arquivo morto'), h('small', null, t('{a} de {b} ainda sem arquivo. Caixas que já têm arquivo são mantidas.', { a: num(noArch), b: num(list.length) })))),
        h('label', { class: 'opt' }, cbExpand, h('span', null, h('strong', null, 'Habilitar a expansão automática'), h('small', null, t('Somente nas {a} caixa(s) com licença elegível (Exchange Online Plano 2, Microsoft 365 E3/E5 ou Exchange Online Archiving). As demais são ignoradas.', { a: num(elig) }), ' ', t('Enquanto provisiona (até 30 dias), a Microsoft acrescenta 10 GB à cota do arquivo. Suportada para caixas de usuários e compartilhadas que crescem até 1 GB por dia.')))),
        ack,
        h('label', { class: 'opt' }, cbMfa, h('span', null, h('strong', null, 'Sincronizar agora (Managed Folder Assistant)'), h('small', null, 'Aplica na hora a política de retenção: move os itens antigos para o arquivo (padrão da Microsoft: mais de 2 anos) e, com a caixa acima de 96% da cota, os itens mais antigos (com mais de 60 dias). Sem isso, o processamento acontece em até 7 dias.'))),
        h('ul', { class: 'act-notes' }, who, h('li', null, 'Os relatórios de uso da Microsoft refletem a mudança com até 48 h de atraso.'), first));
    } else if (op === 'process') {
      intro = h('div', null,
        h('p', { class: 'lead-sm' }, 'Inicia agora o Managed Folder Assistant nas caixas selecionadas: aplica a política de retenção e move para o arquivo morto o que já está elegível, sem esperar o ciclo de até 7 dias.'),
        noArch ? h('div', { class: 'alert warn' }, t('{n} caixa(s) sem arquivo morto: o processamento roda, mas nada é movido para o arquivo. Ative o arquivamento primeiro.', { n: num(noArch) })) : null,
        h('ul', { class: 'act-notes' }, who, first));
    } else {
      intro = h('div', null,
        h('div', { class: 'alert danger' }, h('strong', null, 'Ação destrutiva. '), 'O conteúdo do arquivo morto NÃO volta para a caixa principal: ele fica desconectado e pode ser reconectado em até 30 dias (reativando o arquivo). Depois disso, a Microsoft o exclui permanentemente.'),
        h('ul', { class: 'act-notes' },
          ignored ? h('li', null, t('{n} caixa(s) selecionada(s) sem arquivo morto segundo o relatório foram deixadas de fora.', { n: num(ignored) })) : null,
          h('li', null, 'O relatório de uso da Microsoft pode ter até 48 h de atraso: uma caixa que já esteja sem arquivo aparece como "Já estava sem arquivo".'),
          h('li', null, 'Caixas em retenção (Litigation Hold, eDiscovery ou políticas de retenção) ou com expansão automática podem ser recusadas pela Microsoft — o motivo aparece no resultado.'),
          h('li', null, 'Antes de desativar, avalie exportar o conteúdo pelo Microsoft Purview (Pesquisa de conteúdo).'),
          who, first),
        h('label', { class: 'opt ack' }, cbAck, h('span', null, h('strong', null, 'Entendo que o conteúdo do arquivo pode ser perdido após 30 dias.'))),
        h('label', { class: 'typed' }, h('span', null, t('Para confirmar, digite {s}:', { s: t('DESATIVAR') })), typed));
    }
    const result = (res) => {
      const s2 = res.summary || {};
      const R = op === 'disable' ? ARCH_OFF : ARCH_RESULT;
      const k = op === 'disable' ? [{ label: 'Arquivos desativados', value: s2.disabled, cls: 'warn' }] : op === 'process' ? [{ label: 'Processamento iniciado', value: s2.processed }] : [{ label: 'Com arquivo morto', value: s2.archived }, { label: 'Com expansão automática', value: s2.expanded }, { label: 'Processamento iniciado', value: s2.processed }];
      const out = h('div', null, haltNote(s2), kpis([{ label: 'Concluídas', value: s2.ok, cls: 'ok' }, { label: 'Com falha', value: s2.failed, cls: s2.failed ? 'danger' : 'ok' }, ...(s2.skipped ? [{ label: 'Não processadas', value: s2.skipped, cls: 'warn' }] : []), ...k]));
      out.append(DataTable({ rows: res.rows || [], name: 'caixas-resultado', compact: true, pageSize: 25, columns: [
        { key: 'upn', label: 'Caixa', render: (r) => raw(r.upn) },
        ...(op === 'process' ? [] : [{ key: 'archive', label: 'Arquivo morto', render: (r) => badge(...(R[r.archive] || [r.archive, ''])), export: (r) => (R[r.archive] || [r.archive])[0] }]),
        ...(op === 'enable' ? [{ key: 'expand', label: 'Expansão automática', render: (r) => badge(...(ARCH_RESULT[r.expand] || [r.expand, ''])), export: (r) => (ARCH_RESULT[r.expand] || [r.expand])[0] }] : []),
        ...(op === 'disable' ? [] : [{ key: 'mfa', label: 'Processamento', render: (r) => badge(...(r.mfa === 'ok' ? ['Iniciado', 'ok'] : (ARCH_RESULT[r.mfa] || [r.mfa, '']))), export: (r) => (r.mfa === 'ok' ? t('Iniciado') : (ARCH_RESULT[r.mfa] || [r.mfa])[0]) }]),
        { key: 'message', label: 'Detalhe', wrap: true, render: (r) => reason(r.message), export: (r) => reason(r.message) },
      ] }));
      out.append(h('div', { class: 'actions' }, h('button', { class: 'btn', type: 'button', onclick: () => scriptDialog(list, op, op === 'enable' && cbExpand.checked, op !== 'enable' || cbMfa.checked) }, icon('script'), 'Script PowerShell equivalente')));
      return out;
    };
    const dlg = actionDialog(t('{a}: {n} caixa(s)', { a: t(O.label), n: num(list.length) }), intro, t(O.verb), 'exchange', result, (res) => { toast(t('{n} caixa(s) processada(s).', { n: num((res.summary || {}).ok || 0) }), 'ok'); if (onDone) onDone(res); });
    if (op === 'disable') dlg.go.classList.add('danger');
    const sync = () => {
      if (dlg.busy) return;
      if (op === 'enable') { ack.hidden = !cbExpand.checked; dlg.go.disabled = cbExpand.checked && !cbAck.checked; }
      if (op === 'disable') dlg.go.disabled = !(cbAck.checked && typed.value.trim().toUpperCase() === t('DESATIVAR').toUpperCase());
    };
    cbExpand.onchange = sync; cbAck.onchange = sync; typed.oninput = sync;
    sync();
    dlg.go.onclick = () => {
      if (dlg.go.disabled) return;
      [cbExpand, cbAck, cbMfa, typed].forEach((c) => { c.disabled = true; }); // the confirmed options are final
      const payload = () => Object.assign({ op, mailboxes: list.map((r) => ({ upn: r.upn, name: r.displayName, eligible: r.expandOk === true })) },
        op === 'enable' ? { autoExpand: cbExpand.checked, mfa: cbMfa.checked } : {},
        op === 'disable' ? { confirm: 'disable-archive:' + list.length } : (op === 'enable' && cbExpand.checked ? { confirm: 'auto-expand:' + list.length } : {}));
      authorizeThen('exchange', dlg, payload, archiveNeed(list.length, cbExpand.checked, cbMfa.checked, op));
    };
  }

  views.mailboxes = () => reportPanel('mailboxes', {}, 'Caixas de correio', (p) => {
    const s = p.summary || {}, rows = p.rows || [], hidden = !!p._meta.concealed;
    App.namesConcealed = hidden;
    const info = Actions.info('exchange');
    const sel = new Set();
    const box = h('div');
    if (hidden) box.append(concealedBanner());
    const gate = !hidden && rows.length ? Actions.gate('exchange', 'Gerenciar o arquivamento') : null;
    const canAct = Actions.can('exchange') && !hidden;
    box.append(kpis([
      { label: 'Caixas de correio', value: s.total },
      { label: 'Armazenamento usado', value: bytes(s.used) },
      { label: 'Acima de 80% da cota', value: s.near, cls: s.near ? 'warn' : 'ok', onclick: () => table.setFilter('near') },
      { label: 'Em aviso de cota', value: s.warning, cls: s.warning ? 'warn' : 'ok', onclick: () => table.setFilter('warn') },
      { label: 'Não enviam', value: s.sendBlocked, cls: s.sendBlocked ? 'danger' : 'ok', onclick: () => table.setFilter('full') },
      { label: 'Não enviam nem recebem', value: s.blocked, cls: s.blocked ? 'danger' : 'ok', onclick: () => table.setFilter('full') },
      { label: 'Sem arquivo morto', value: s.noArchive, onclick: () => table.setFilter('noarch') },
      { label: 'Elegíveis à expansão automática', value: s.expandEligible, onclick: () => table.setFilter('eligible') },
    ]));
    if (s.nearNoArchive && !hidden) {
      const near = rows.filter((r) => !r.hasArchive && r.pct !== null && r.pct >= 80);
      box.append(h('div', { class: 'insight' }, h('div', { class: 'ins-ic' }, icon('archive')), h('div', null,
        h('strong', null, t('{n} caixa(s) acima de 80% ainda sem arquivo morto.', { n: num(s.nearNoArchive) })),
        h('p', null, 'Ativar o arquivo morto (e a expansão automática, quando a licença permite) e sincronizar em seguida libera espaço da caixa principal sem custo adicional nas licenças elegíveis.')),
        h('div', { class: 'ins-act' },
          h('button', { class: 'btn sm', type: 'button', onclick: () => { near.forEach((r) => sel.add(r.upn)); table.setFilter('near'); refresh(); } }, 'Selecionar'),
          h('button', { class: 'btn primary sm', type: 'button', disabled: !canAct, onclick: () => mailboxDialog(near, 'enable', () => { sel.clear(); refresh(); }) }, icon('bolt'), 'Resolver agora'))));
    }
    const fullArch = rows.filter((r) => r.hasArchive && r.pct !== null && r.pct >= 90);
    if (fullArch.length && !hidden) {
      box.append(h('div', { class: 'insight' }, h('div', { class: 'ins-ic' }, icon('play')), h('div', null,
        h('strong', null, t('{n} caixa(s) acima de 90% já com arquivo morto.', { n: num(fullArch.length) })),
        h('p', null, 'Iniciar o processamento agora aplica a retenção e move os itens antigos para o arquivo, sem esperar o ciclo de 7 dias.')),
        h('div', { class: 'ins-act' }, h('button', { class: 'btn primary sm', type: 'button', disabled: !canAct, onclick: () => mailboxDialog(fullArch, 'process') }, icon('play'), 'Processar agora'))));
    }
    const detail = (r) => {
      const acts = canAct && !r.concealed ? h('div', { class: 'detail-actions' }, h('span', { class: 'seg-label' }, 'Ações'),
        h('button', { class: 'btn sm primary', type: 'button', onclick: () => mailboxDialog([r], 'enable') }, icon('archive'), r.hasArchive ? 'Expansão automática e processamento' : 'Ativar arquivamento'),
        h('button', { class: 'btn sm', type: 'button', onclick: () => mailboxDialog([r], 'process') }, icon('play'), 'Processar agora'),
        r.hasArchive ? h('button', { class: 'btn sm danger', type: 'button', onclick: () => mailboxDialog([r], 'disable') }, icon('trash'), 'Desativar arquivo morto') : null) : null;
      modal(hidden ? t('Caixa (oculta)') : (r.displayName || r.upn), h('div', null, acts, kv([
        ['UPN', raw(hidden ? t('Oculto pela Microsoft') + ' · ' + r.upn : r.upn)], ['Tipo', r.type || '—'], ['Uso', raw(bytes(r.used) + (r.pct !== null ? ` (${num(r.pct)}%)` : ''))],
        ['Aviso em', raw(bytes(r.warnQuota))], ['Não envia em', raw(bytes(r.sendQuota))], ['Não envia nem recebe em', raw(bytes(r.fullQuota))],
        ['Itens', num(r.items)], ['Itens excluídos (tamanho)', raw(bytes(r.deletedSize))], ['Arquivo morto', yesNo(r.hasArchive)],
        ['Expansão automática', r.expandOk === null ? '—' : (r.expandOk ? t('Elegível pela licença') : t('Licença não inclui'))], ['Última atividade', isoDay(r.lastActivity)]]),
        hidden ? concealedBanner() : null));
    };
    if (s.refreshDate) box.append(h('p', { class: 'muted small' }, t('Dados da Microsoft atualizados até {s}.', { s: isoDay(s.refreshDate) })));
    const bar = h('div', { class: 'selbar' });
    const drawBar = () => {
      bar.innerHTML = '';
      bar.classList.toggle('show', sel.size > 0);
      if (!sel.size) return;
      const list = rows.filter((r) => sel.has(r.upn));
      const over = list.length > MBX_MAX;
      const why = over ? t('Selecione no máximo {n} caixas de correio por execução.', { n: num(MBX_MAX) }) : (canAct ? null : (hidden ? t('Nomes ocultos: ações indisponíveis') : t('Requer {s} (atribuição ativa).', { s: (info.roles || []).join(', ') })));
      const go = canAct && !over;
      bar.append(h('div', { class: 'sb-txt' }, h('strong', null, t('{n} selecionado(s)', { n: num(sel.size) })), h('span', null, t('{a} sem arquivo · {b} elegíveis à expansão', { a: num(list.filter((r) => !r.hasArchive).length), b: num(list.filter((r) => r.expandOk).length) }))),
        h('div', { class: 'sb-act' },
          h('button', { class: 'btn sm', type: 'button', onclick: () => { sel.clear(); refresh(); } }, 'Limpar seleção'),
          h('button', { class: 'btn sm', type: 'button', onclick: () => scriptDialog(list, 'enable') }, icon('script'), 'Script PowerShell'),
          h('button', { class: 'btn sm danger-ghost', type: 'button', disabled: !go || !list.some((r) => r.hasArchive), title: why, onclick: () => mailboxDialog(list, 'disable', () => { sel.clear(); refresh(); }) }, icon('trash'), 'Desativar arquivo…'),
          h('button', { class: 'btn sm', type: 'button', disabled: !go, title: why, onclick: () => mailboxDialog(list, 'process', () => { sel.clear(); refresh(); }) }, icon('play'), 'Processar agora'),
          h('button', { class: 'btn primary', type: 'button', disabled: !go, title: why, onclick: () => mailboxDialog(list, 'enable', () => { sel.clear(); refresh(); }) }, icon('archive'), 'Ativar arquivamento…')));
      if (over) bar.append(h('small', { class: 'sb-note warn' }, why));
      else if (gate && !canAct) bar.append(h('small', { class: 'sb-note' }, 'Você pode gerar o script PowerShell e entregar a quem tem a permissão.'));
    };
    const refresh = () => { drawBar(); table.redraw(); };
    const table = DataTable({
      rows, name: 'caixas-de-correio', pageSize: 50,
      select: { set: sel, id: (r) => r.upn, can: (r) => !r.concealed, why: () => t('Nomes ocultos pela Microsoft'), onChange: drawBar },
      onRowClick: detail,
      columns: [
        hidden ? { key: 'upn', label: 'Caixa (oculta)', render: (r) => h('span', { class: 'mono muted', title: r.upn }, raw(shortId(r.upn))) } : { key: 'displayName', label: 'Usuário' },
        ...(hidden ? [] : [{ key: 'upn', label: 'UPN' }]),
        { key: 'used', label: 'Uso', render: (r) => usage(r.used, r.fullQuota || r.sendQuota, r.pct), export: (r) => Math.round((r.used / 1073741824) * 100) / 100, sortValue: (r) => r.used, xlLabel: 'Uso (GB)' },
        { key: 'pct', label: '% da cota', exportOnly: true, export: (r) => (r.pct === null ? '' : r.pct) },
        { key: 'quota', label: 'Cota', sortValue: (r) => ({ blocked: 0, send_blocked: 1, warning: 2, ok: 3 }[r.quota] ?? 4), render: (r) => badge(...(QUOTA[r.quota] || [r.quota, ''])), export: (r) => (QUOTA[r.quota] || [r.quota])[0] },
        { key: 'hasArchive', label: 'Arquivo morto', render: (r) => (r.hasArchive ? badge('Ativo', 'ok') : badge('Não', '')), export: (r) => (r.hasArchive ? t('Ativo') : t('Não')) },
        { key: 'expandOk', label: 'Expansão automática', render: (r) => (r.expandOk === null ? '—' : r.expandOk ? badge('Elegível', 'info') : badge('Não elegível', '')), export: (r) => (r.expandOk === null ? '' : t(r.expandOk ? 'Elegível' : 'Não elegível')) },
        { key: 'items', label: 'Itens', exportOnly: true },
        { key: 'type', label: 'Tipo', exportOnly: true },
        { key: 'sendQuota', label: 'Não envia em (GB)', exportOnly: true, export: (r) => (r.sendQuota ? Math.round((r.sendQuota / 1073741824) * 100) / 100 : '') },
        { key: 'warnQuota', label: 'Aviso em (GB)', exportOnly: true, export: (r) => (r.warnQuota ? Math.round((r.warnQuota / 1073741824) * 100) / 100 : '') },
        { key: 'fullQuota', label: 'Não envia nem recebe em (GB)', exportOnly: true, export: (r) => (r.fullQuota ? Math.round((r.fullQuota / 1073741824) * 100) / 100 : '') },
        { key: 'deletedSize', label: 'Itens excluídos (GB)', exportOnly: true, export: (r) => (r.deletedSize ? Math.round((r.deletedSize / 1073741824) * 100) / 100 : 0) },
        { key: 'archiveOk', label: 'Licença inclui arquivo morto', exportOnly: true, export: (r) => (r.archiveOk === null || r.archiveOk === undefined ? null : !!r.archiveOk) },
        { key: 'lastActivity', label: 'Última atividade', render: (r) => isoDay(r.lastActivity), export: (r) => r.lastActivity },
      ],
      filters: [
        { id: 'near', label: 'Acima de 80%', fn: (r) => r.pct !== null && r.pct >= 80 },
        { id: 'p90', label: 'Acima de 90%', fn: (r) => r.pct !== null && r.pct >= 90 },
        { id: 'warn', label: 'Em aviso', fn: (r) => r.quota === 'warning' },
        { id: 'full', label: 'Lotadas (não enviam)', fn: (r) => r.quota === 'send_blocked' || r.quota === 'blocked' },
        { id: 'noarch', label: 'Sem arquivo morto', fn: (r) => !r.hasArchive },
        { id: 'arch', label: 'Com arquivo morto', fn: (r) => r.hasArchive },
        { id: 'eligible', label: 'Elegíveis à expansão', fn: (r) => r.expandOk === true },
        { id: 'quotaok', label: 'Cota normal', fn: (r) => r.quota === 'ok' },
      ],
    });
    drawBar();
    if (rows.length) {
      const band = (r) => (r.pct === null || r.pct === undefined ? null : r.pct >= 90 ? 3 : r.pct >= 80 ? 2 : r.pct >= 50 ? 1 : 0);
      const bands = [['Abaixo de 50%', 0, null, 'ok'], ['50% a 80%', 0, null, 'info'], ['80% a 90%', 0, () => table.setFilter('near'), 'warn'], ['90% ou mais', 0, () => table.setFilter('p90'), 'danger']];
      for (const r of rows) { const b = band(r); if (b !== null) bands[b][1]++; }
      box.append(vizRow(
        vizCard('Uso da cota', donut(bands, { total: rows.length, centerLabel: t('caixas') })),
        vizCard('Arquivo morto', donut([['Com arquivo morto', s.withArchive ?? rows.filter((r) => r.hasArchive).length, () => table.setFilter('arch'), 'ok'], ['Sem arquivo morto', s.noArchive, () => table.setFilter('noarch'), 'muted']], { total: rows.length, centerLabel: t('caixas'), key: 'hasArchive' })),
        vizCard('Situação das cotas', barList(Object.entries(QUOTA).map(([k, [label]]) => [label, rows.filter((r) => r.quota === k).length, () => table.setFilter(k === 'ok' ? 'quotaok' : k === 'warning' ? 'warn' : 'full')]), rows.length))));
      box.append(vizRow(vizCard('Maiores caixas', barList([...rows].sort((a, b) => (b.used || 0) - (a.used || 0)).slice(0, 10).map((r) => [raw(hidden ? shortId(r.upn) : (r.displayName || r.upn)), r.used || 0, () => detail(r)]), 0, { fmt: bytes }))));
    }
    box.append(table, bar);
    if (gate) box.append(h('div', { class: 'gate-foot' }, gate));
    return box;
  }, { subtitle: 'Compare o uso das caixas de correio, encontre caixas próximas da cota e confira quais têm arquivo morto.', ring: (p) => {
    const s = p.summary || {}, measured = p.rows.filter((r) => Number.isFinite(r.pct));
    const pc = pctOf(measured.filter((r) => r.pct < 80).length, measured.length);
    return measured.length ? { pct: pc, label: 'das caixas com cota medida abaixo de 80%', caption: t('{n} sem enviar e-mails', { n: num((s.sendBlocked || 0) + (s.blocked || 0)) }), tone: toneFor(pc, 95, 85) } : null;
  } });

  /* ------------------------------------------------------------ OneDrive & SharePoint */
  const siteName = (url) => { if (!url) return '—'; const m = String(url).match(/\/(?:sites|teams|personal)\/([^/?#]+)/i); return m ? decodeURIComponent(m[1]) : String(url).replace(/^https?:\/\//, ''); };
  views.storage = () => { const offscreen = Array.isArray(App.harvesting); return reportPanel('storage', {}, 'OneDrive & SharePoint', (p) => {
    const s = p.summary || {}, hidden = !!p._meta.concealed;
    App.namesConcealed = hidden;
    const od = p.onedrive || [], sp = p.sharepoint || [];
    const box = h('div');
    if (hidden) box.append(concealedBanner());
    const tabs = h('div', { class: 'segbar', role: 'tablist' });
    const pane = h('div');
    const TOPS = [10, 20, 30, 40, 50];
    const top = () => App.params.storageTop || 10;
    const topPanel = (list, label) => {
      const wrap = h('div', { class: 'panel top-n' });
      const draw = () => {
        wrap.innerHTML = '';
        if (!list.length) { wrap.append(h('h3', null, 'Top por consumo'), h('p', { class: 'muted small' }, 'Nenhum registro.')); return; }
        const n = top(), slice = list.slice(0, n);
        const total = list.reduce((a, r) => a + (r.used || 0), 0), part = slice.reduce((a, r) => a + (r.used || 0), 0);
        wrap.append(h('div', { class: 'panel-head' }, h('h3', null, t('Top {n} por consumo', { n })), h('div', { class: 'topn-picks', role: 'group', 'aria-label': 'Quantidade' }, ...TOPS.map((k) => h('button', { type: 'button', class: 'seg' + (k === n ? ' on' : ''), 'aria-pressed': k === n ? 'true' : 'false', onclick: () => { App.params.storageTop = k; draw(); } }, raw(String(k)))))),
          h('p', { class: 'muted small' }, t('Os {n} maiores somam {a} ({b}% do total).', { n: slice.length, a: bytes(part), b: total ? Math.round((100 * part) / total) : 0 })),
          barList(slice.map((r) => [raw(label(r)), r.used]), 0, { fmt: bytes, limit: n }));
      };
      draw();
      return wrap;
    };
    const odPane = () => {
      const out = h('div');
      out.append(kpis([
        { label: 'Contas do OneDrive', value: s.odAccounts },
        { label: 'Armazenamento usado', value: bytes(s.odUsed) },
        { label: 'Acima de 90% do limite', value: s.odNear, cls: s.odNear ? 'warn' : 'ok', onclick: () => t1.setFilter('near') },
        { label: 'No limite', value: s.odFull, cls: s.odFull ? 'danger' : 'ok', onclick: () => t1.setFilter('full') },
        { label: 'Sem arquivos', value: s.odEmpty, onclick: () => t1.setFilter('empty') },
      ]));
      out.append(topPanel(od, (r) => (hidden ? shortId(r.upn) : (r.displayName || r.upn))));
      const t1 = DataTable({ rows: od, name: 'onedrive', sheet: 'OneDrive', columns: [
        hidden ? { key: 'upn', label: 'Conta (oculta)', render: (r) => h('span', { class: 'mono muted', title: r.upn }, raw(shortId(r.upn))) } : { key: 'displayName', label: 'Usuário' },
        ...(hidden ? [] : [{ key: 'upn', label: 'UPN' }]),
        { key: 'used', label: 'Usado', xlLabel: 'Usado (GB)', render: (r) => usage(r.used, r.allocated, r.pct), export: (r) => Math.round((r.used / 1073741824) * 100) / 100, sortValue: (r) => r.used },
        { key: 'pct', label: '% do limite', exportOnly: true, export: (r) => (r.pct === null ? '' : r.pct) },
        { key: 'allocated', label: 'Limite (GB)', exportOnly: true, export: (r) => Math.round((r.allocated / 1073741824) * 100) / 100 },
        { key: 'files', label: 'Arquivos', render: (r) => num(r.files) }, { key: 'activeFiles', label: 'Ativos (7 dias)', render: (r) => num(r.activeFiles) },
        { key: 'lastActivity', label: 'Última atividade', render: (r) => isoDay(r.lastActivity), export: (r) => r.lastActivity },
      ], filters: [
        { id: 'near', label: 'Acima de 90%', fn: (r) => r.pct !== null && r.pct >= 90 }, { id: 'full', label: 'No limite', fn: (r) => r.pct !== null && r.pct >= 100 },
        { id: 'idle', label: 'Sem atividade', fn: (r) => !r.lastActivity }, { id: 'empty', label: 'Sem arquivos', fn: (r) => r.files === 0 },
      ] });
      out.append(t1);
      return out;
    };
    const spPane = () => {
      const out = h('div');
      out.append(kpis([
        { label: 'Sites do SharePoint', value: s.spSites },
        { label: 'Armazenamento usado', value: bytes(s.spUsed) },
        { label: 'Acima de 90% do limite', value: s.spNear, cls: s.spNear ? 'warn' : 'ok', onclick: () => t2.setFilter('near') },
        { label: 'Sem atividade há 180 dias', value: s.spInactive, cls: s.spInactive ? 'warn' : 'ok', onclick: () => t2.setFilter('idle') },
      ]));
      out.append(topPanel(sp, (r) => (hidden && !r.url ? t('Site (oculto)') : siteName(r.url))));
      const t2 = DataTable({ rows: sp, name: 'sharepoint', sheet: 'SharePoint', columns: [
        { key: 'url', label: 'Site', render: (r) => h('span', { title: r.url }, raw(siteName(r.url))), export: (r) => r.url },
        { key: 'owner', label: 'Proprietário', render: (r) => raw(r.owner || '—') },
        { key: 'ownerUpn', label: 'UPN do proprietário', exportOnly: true },
        { key: 'allocated', label: 'Limite (GB)', exportOnly: true, export: (r) => (r.allocated ? Math.round((r.allocated / 1073741824) * 100) / 100 : '') },
        { key: 'template', label: 'Modelo do site', render: (r) => raw(r.template || '—') },
        { key: 'used', label: 'Usado', xlLabel: 'Usado (GB)', render: (r) => usage(r.used, r.allocated, r.pct), export: (r) => Math.round((r.used / 1073741824) * 100) / 100, sortValue: (r) => r.used },
        { key: 'pct', label: '% do limite', exportOnly: true, export: (r) => (r.pct === null || r.pct === undefined ? '' : r.pct) },
        { key: 'files', label: 'Arquivos', render: (r) => num(r.files) }, { key: 'views', label: 'Visualizações (7 dias)', render: (r) => num(r.views) },
        { key: 'lastActivity', label: 'Última atividade', render: (r) => isoDay(r.lastActivity), export: (r) => r.lastActivity },
      ], filters: [
        { id: 'near', label: 'Acima de 90%', fn: (r) => r.pct !== null && r.pct >= 90 },
        { id: 'idle', label: 'Sem atividade há 180 dias', fn: (r) => !r.lastActivity || Date.now() - Date.parse(r.lastActivity) > 180 * 864e5 },
      ] });
      out.append(t2);
      return out;
    };
    const show = (k) => {
      App.params.storageTab = k;
      tabs.innerHTML = '';
      for (const [id, label, n] of [['onedrive', 'OneDrive', od.length], ['sharepoint', 'SharePoint', sp.length]]) {
        tabs.append(h('button', { type: 'button', role: 'tab', class: 'seg' + (id === k ? ' on' : ''), 'aria-selected': id === k ? 'true' : 'false', onclick: () => show(id) }, icon(id === 'onedrive' ? 'cloud' : 'globe'), h('span', null, raw(label)), h('span', { class: 'chip-n' }, raw(num(n)))));
      }
      pane.innerHTML = '';
      pane.append(k === 'sharepoint' ? spPane() : odPane());
    };
    // The executive workbook gets both tables; the screen shows one tab at a time.
    if (offscreen) pane.append(odPane(), spPane()); else show(App.params.storageTab || 'onedrive');
    if (s.refreshDate) box.append(h('p', { class: 'muted small' }, t('Dados da Microsoft atualizados até {s}.', { s: isoDay(s.refreshDate) })));
    if (od.length || sp.length) {
      const odBands = [['Abaixo de 80%', od.filter((r) => r.pct !== null && r.pct < 80).length, null, 'ok'], ['80% a 90%', od.filter((r) => r.pct >= 80 && r.pct < 90).length, null, 'warn'], ['90% ou mais', od.filter((r) => r.pct >= 90).length, null, 'danger']];
      const unknownQuota = od.filter((account) => !Number.isFinite(account.pct)).length;
      if (unknownQuota) odBands.push(['Cota desconhecida', unknownQuota]);
      box.append(vizRow(
        od.length ? vizCard('OneDrive: uso do limite', donut(odBands, { total: od.length, centerLabel: t('contas') })) : null,
        vizCard('Armazenamento usado', donut([['OneDrive', s.odUsed || 0, () => show('onedrive')], ['SharePoint', s.spUsed || 0, () => show('sharepoint')]], { fmt: bytes, centerLabel: t('total') }))));
    }
    box.append(tabs, pane);
    return box;
  }, { subtitle: 'Compare o armazenamento usado por contas do OneDrive e sites do SharePoint. Identifique os maiores consumidores e confira quando houve atividade pela última vez.', graphSource: '/reports/getOneDriveUsageAccountDetail /reports/getSharePointSiteUsageDetail', ring: (p) => {
    const accounts = p.onedrive || [];
    const measured = accounts.filter((account) => Number.isFinite(account.pct));
    if (!measured.length) return null;
    const below = measured.filter((account) => account.pct < 90).length;
    const pc = pctOf(below, measured.length);
    return { pct: pc, label: 'das contas avaliadas abaixo de 90% da cota', caption: t('{a} de {b} contas com cota informada', { a: num(measured.length), b: num(accounts.length) }), tone: toneFor(pc, 97, 90) };
  } }); };

  function workspaceHeading(title, description, controls) {
    return h('header', { class: 'workspace-heading' },
      h('div', null, h('h2', null, title), description ? h('p', { class: 'muted small' }, description) : null),
      controls || null);
  }

  /* Settings (tenant-level). Changes re-verify the directory role live on the server. */
  views.settings = () => {
    const box = h('div', { class: 'settings-sections' });
    const ten = App.me.tenant, st = ten.settings || {}, can = (App.me.user && App.me.user.can) || {};
    const P = App.me.privacy || CFG.privacy || {};
    const note = (txt) => h('div', { class: 'alert warn small' }, txt);
    const result = (el, ok, txt) => { el.className = 'alert ' + (ok ? 'ok' : 'danger'); el.textContent = t(txt); };

    /* App homologation */
    const auto = h('input', { type: 'checkbox', checked: !!st.auto_approve_intune_apps, disabled: !can.manageApps });
    const ms = h('input', { type: 'checkbox', checked: !!st.trust_microsoft_publisher, disabled: !can.manageApps });
    const msg = h('div');
    const save = h('button', { class: 'btn primary', disabled: !can.manageApps, onclick: async () => {
      try { const r = await api('tenant/settings', { method: 'PATCH', body: { auto_approve_intune_apps: auto.checked, trust_microsoft_publisher: ms.checked } }); App.me.tenant.settings = r.settings; delete App.cache['apps{}']; result(msg, true, 'Salvo.'); } catch (e) { result(msg, false, e.message); }
    } }, 'Salvar');
    box.append(h('div', { class: 'panel' }, h('h2', null, 'Homologação de aplicativos'),
      can.manageApps ? null : note('Somente Global Administrator, Intune Administrator ou Security Administrator (atribuição ativa) podem alterar as regras de homologação.'),
      h('label', { class: 'check-row' }, auto, h('span', null, 'Homologar por correspondência de nome com um aplicativo publicado no catálogo do Intune')),
      h('label', { class: 'check-row' }, ms, h('span', null, 'Considerar homologados os aplicativos cujo publisher começa com "Microsoft"')),
      h('div', { class: 'actions' }, save), msg));

    /* Additional users (stored only as keyed pseudonyms) */
    const upns = h('textarea', { class: 'j365-input', rows: 3, style: 'width:100%;min-width:0', placeholder: 'usuario@contoso.com', disabled: !can.manageAccess, spellcheck: 'false', autocomplete: 'off' });
    const count = h('b', null, raw(String(st.extra_users_count || 0)));
    const amsg = h('div');
    const parse = () => upns.value.split(/[\s,;]+/).map((x) => x.trim().toLowerCase()).filter((x) => /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(x));
    const send = async (body, done) => {
      try { const r = await api('tenant/settings', { method: 'PATCH', body }); App.me.tenant.settings = r.settings; count.textContent = String(r.settings.extra_users_count || 0); upns.value = ''; result(amsg, true, done); } catch (e) { result(amsg, false, e.message); }
    };
    box.append(h('div', { class: 'panel' }, h('h2', null, 'Usuários adicionais com acesso ao portal'),
      h('p', { class: 'muted small' }, 'Para quem não tem função de diretório ativa (ex.: funções elegíveis via PIM). O login Microsoft no seu tenant continua obrigatório. Os UPNs são guardados apenas como pseudônimo irreversível (HMAC) — por isso não é possível listá-los: informe o UPN para remover.'),
      can.manageAccess ? null : note('Somente Global Administrator ou Privileged Role Administrator (atribuição ativa) podem liberar acesso a outros usuários.'),
      h('p', { class: 'small' }, 'Usuários adicionais cadastrados: ', count),
      upns,
      h('div', { class: 'actions' },
        h('button', { class: 'btn primary', disabled: !can.manageAccess, onclick: () => { const l = parse(); if (!l.length) return result(amsg, false, 'Informe ao menos um UPN válido.'); send({ extra_users_add: l }, 'Acesso liberado.'); } }, 'Adicionar'),
        h('button', { class: 'btn', disabled: !can.manageAccess, onclick: () => { const l = parse(); if (!l.length) return result(amsg, false, 'Informe ao menos um UPN válido.'); send({ extra_users_remove: l }, 'Acesso removido.'); } }, 'Remover'),
        h('button', { class: 'btn danger', disabled: !can.manageAccess, onclick: () => { if (window.confirm(t('Remover o acesso de todos os usuários adicionais?'))) send({ extra_users_clear: true }, 'Todos os acessos adicionais foram removidos.'); } }, 'Remover todos')),
      amsg));

    /* Actions: who may run them in this tenant (Global Administrator / Privileged Role Administrator decide) */
    const SCOPES = [
      ['roles', 'Somente funções administrativas do Entra (recomendado)', 'Global Administrator, Intune Administrator, Exchange Administrator e Exchange Recipient Administrator, com atribuição ativa (a privacidade dos relatórios exige Global Administrator). Menor privilégio por padrão.'],
      ['rbac', 'Qualquer usuário autorizado no portal, com o RBAC da Microsoft', 'Para equipes de suporte com funções do Intune (ex.: Help Desk Operator com scope tags) ou grupos de funções do Exchange. A Microsoft valida a permissão de cada pessoa em cada item.'],
      ['off', 'Desativadas neste tenant', 'O portal fica somente leitura para todos.'],
    ];
    const curScope = st.actions_scope || 'roles';
    const radios = SCOPES.map(([v, label, help]) => {
      const input = h('input', { type: 'radio', name: 'actions_scope', value: v, checked: v === curScope, disabled: !can.manageAccess, onchange: () => radios.forEach((l) => l.classList.toggle('on', l.firstChild.checked)) });
      return h('label', { class: 'opt' + (v === curScope ? ' on' : '') }, input, h('span', null, h('strong', null, label), h('small', null, help)));
    });
    const smsg = h('div');
    const anyAction = Object.values(App.me.actions || {}).some((a) => a.enabled);
    box.append(h('div', { class: 'panel' }, h('h2', null, 'Ações'),
      h('p', { class: 'muted small' }, CFG.tenancy === 'per_tenant'
        ? 'Ações em dispositivos (sincronizar, reiniciar, Microsoft Defender), arquivamento de caixas e a privacidade dos relatórios executam com a conta de quem clica (permissão delegada do aplicativo do seu tenant): o Intune, o Exchange e o Microsoft 365 aplicam o RBAC dessa pessoa e registram a ação em nome dela. Os relatórios só leem.'
        : 'Ações em dispositivos (sincronizar, reiniciar, Microsoft Defender), arquivamento de caixas e a privacidade dos relatórios executam com a conta de quem clica (permissão delegada, autorizada em uma janela da Microsoft): o Intune, o Exchange e o Microsoft 365 aplicam o RBAC dessa pessoa e registram a ação em nome dela. As permissões do aplicativo continuam somente leitura.'),
      anyAction ? null : note('As ações estão desativadas pelo operador deste portal.'),
      can.manageAccess ? null : note('Somente Global Administrator ou Privileged Role Administrator (atribuição ativa) podem mudar quem executa ações.'),
      h('div', { class: 'opts', role: 'radiogroup', 'aria-label': 'Quem pode executar ações' }, ...radios),
      h('div', { class: 'actions' }, h('button', { class: 'btn primary', disabled: !can.manageAccess, onclick: async () => {
        const v = (radios.map((l) => l.firstChild).find((i) => i.checked) || {}).value || 'roles';
        try { const r = await api('tenant/settings', { method: 'PATCH', body: { actions_scope: v } }); App.me.tenant.settings = r.settings; await Actions.reload(); result(smsg, true, 'Salvo.'); } catch (e) { result(smsg, false, e.message); }
      } }, 'Salvar')), smsg));

    /* Report names (Microsoft 365 usage reports privacy setting) */
    const rinfo = Actions.info('reports');
    const rcan = !!(rinfo.enabled && rinfo.allowed);
    const seen = Object.entries(App.cache).filter(([k, v]) => /^(mailboxes|storage|usage)\b/.test(k) && v && v._meta && typeof v._meta.concealed === 'boolean').map(([, v]) => v._meta.concealed);
    const stateNames = App.namesConcealed ?? (seen.length ? seen.some(Boolean) : null);
    box.append(h('div', { class: 'panel' }, h('h2', null, 'Privacidade dos relatórios'),
      h('p', { class: 'muted small' }, 'Configuração do Microsoft 365 admin center que oculta nomes de usuários, grupos e sites nos relatórios de uso (caixas de correio, OneDrive, SharePoint, adoção). Com os nomes ocultos, o portal não consegue identificar as contas nem executar ações nelas.'),
      h('div', { class: 'kv-inline' }, h('span', null, 'Situação atual: '), stateNames === null ? badge('Ainda não verificada — abra Caixas de correio', '') : stateNames ? badge('Nomes ocultos', 'warn') : badge('Nomes visíveis', 'ok')),
      rcan ? null : note(rinfo.enabled ? 'Somente Global Administrator (atribuição ativa) pode alterar esta configuração.' : 'As ações estão desativadas pelo operador deste portal.'),
      h('div', { class: 'actions' },
        h('button', { class: 'btn primary', type: 'button', disabled: !rcan, onclick: (e) => setReportNames(false, e.currentTarget) }, icon('eye'), 'Mostrar nomes reais'),
        h('button', { class: 'btn', type: 'button', disabled: !rcan, onclick: (e) => setReportNames(true, e.currentTarget) }, icon('eyeOff'), 'Ocultar nomes novamente'),
        h('a', { class: 'btn sm', href: REPORT_SETTINGS_URL, target: '_blank', rel: 'noopener' }, 'Abrir no Microsoft 365 admin center'))));

    /* Allowlist */
    const rulesPanel = h('div', { class: 'panel' }, h('h2', null, 'Allowlist de aplicativos (homologados)'));
    const form = h('form', { class: 'inline', onsubmit: async (e) => { e.preventDefault(); const fd = Object.fromEntries(new FormData(form).entries()); try { await api('allowlist', { method: 'POST', body: fd }); form.reset(); delete App.cache['apps{}']; loadRules(); } catch (err) { alert(err.message); } } },
      h('label', null, 'Nome / padrão', h('input', { name: 'app_name', class: 'j365-input', required: true, maxlength: 200, style: 'min-width:260px' })),
      h('label', null, 'Publisher (opcional, contém)', h('input', { name: 'publisher', class: 'j365-input', maxlength: 200 })),
      h('label', null, 'Plataforma', h('select', { name: 'platform', class: 'j365-input' }, ...['any', 'windows', 'macOS', 'iOS', 'androidOSP', 'other'].map((p) => h('option', { value: p }, p === 'any' ? 'Qualquer' : p)))),
      h('label', null, 'Correspondência', h('select', { name: 'match_type', class: 'j365-input' }, h('option', { value: 'exact' }, 'Exata'), h('option', { value: 'contains' }, 'Contém'), h('option', { value: 'prefix' }, 'Prefixo'), h('option', { value: 'regex' }, 'Regex'))),
      h('label', null, 'Observações', h('input', { name: 'notes', class: 'j365-input', maxlength: 300 })),
      h('button', { class: 'btn primary', type: 'submit', disabled: !can.manageApps }, 'Adicionar'));
    if (!can.manageApps) form.querySelectorAll('input,select').forEach((el) => { el.disabled = true; });
    const rulesOut = h('div', { style: 'margin-top:12px' });
    rulesPanel.append(can.manageApps ? null : note('Somente Global Administrator, Intune Administrator ou Security Administrator (atribuição ativa) podem homologar aplicativos.'), form, rulesOut);
    async function loadRules() {
      rulesOut.innerHTML = ''; rulesOut.append(skeleton(3));
      try {
        const r = await api('allowlist');
        rulesOut.innerHTML = '';
        rulesOut.append(DataTable({ rows: r.rules, name: 'allowlist', pageSize: 25, columns: [
          { key: 'app_name', label: 'Nome / padrão' }, { key: 'match_type', label: 'Correspondência' }, { key: 'publisher', label: 'Publisher' }, { key: 'platform', label: 'Plataforma' }, { key: 'notes', label: 'Observações', wrap: true },
          { key: 'created_by', label: 'Criado por', render: (x) => x.created_by === 'you' ? badge('Você', 'info') : badge('Outro administrador') }, { key: 'created_at', label: 'Em', render: (x) => fmtDate(x.created_at + 'Z') },
          { key: 'id', label: '', render: (x) => can.manageApps ? h('button', { class: 'btn sm danger', onclick: async (e) => { e.stopPropagation(); if (!window.confirm(t('Remover regra?'))) return; try { await api(`allowlist/${x.id}`, { method: 'DELETE' }); delete App.cache['apps{}']; loadRules(); } catch (err) { alert(err.message); } } }, 'Remover') : '' },
        ] }));
      } catch (e) { rulesOut.innerHTML = ''; rulesOut.append(h('div', { class: 'alert danger' }, e.message)); }
    }
    loadRules();
    box.append(rulesPanel);

    /* Privacy & data */
    const facts = [
      ['Sessão', t('Cookie cifrado (HttpOnly, Secure, SameSite) — sem sessão gravada no servidor. Sua função é revalidada no Entra ID a cada {n} min.', { n: P.roleCheckMinutes || 15 })],
      ['Credenciais', t(CFG.tenancy === 'per_tenant' ? 'O login ocorre na Microsoft. Os tokens delegados ficam nesta aba e acompanham as chamadas autorizadas ao portal.' : 'O login ocorre na Microsoft. O aplicativo do operador usa a credencial configurada no WordPress.')],
      ['Registro de segurança', t('Pseudonimizado (sem nomes, e-mails ou IP completo) e apagado após {n} dias.', { n: P.auditDays || 30 })],
      ['O que fica no servidor', t('O WordPress mantém os identificadores e as configurações do tenant, as regras de acesso e o registro de segurança pseudonimizado.')],
    ];
    box.append(h('div', { class: 'panel' }, h('h2', null, 'Privacidade e dados'),
      h('dl', { class: 'kv' }, ...facts.flatMap(([k, v]) => [h('dt', null, k), h('dd', null, raw(v))])),
      h('div', { class: 'actions' }, h('button', { class: 'btn', onclick: () => { App.cache = {}; toast(t('Relatórios removidos desta aba.'), 'ok'); } }, icon('refresh'), 'Limpar relatórios desta aba'))));

    box.append(h('div', { class: 'panel' }, h('h2', null, 'Conexão do tenant'), kv([['Tenant', raw(ten.name || '—')], ['Tenant ID', raw(ten.id)], ['Consentido em', fmtDate(ten.consentedAt ? ten.consentedAt + 'Z' : '')], ['Consentido por', ten.consentedByYou ? t('Você') : t('Outro administrador (pseudonimizado)')], ['Revogar', t('Exclua o aplicativo empresarial {s} no Entra ID (Aplicativos empresariais).', { s: APP })]])));
    const sections = Array.from(box.children);
    const chooser = h('select', { class: 'j365-input', onchange: () => {
      if (chooser.value === '') return;
      const section = sections[Number(chooser.value)];
      const heading = section.querySelector('h2');
      heading.tabIndex = -1;
      heading.focus({ preventScroll: true });
      section.scrollIntoView({ block: 'start' });
      chooser.value = '';
    } }, h('option', { value: '' }, 'Selecionar seção'),
      ...sections.map((section, index) => h('option', { value: String(index) }, raw(section.querySelector('h2').textContent))));
    box.prepend(h('div', { class: 'panel' }, workspaceHeading('Configurações', '', h('label', { class: 'settings-jump' }, 'Ir para seção', chooser))));
    return box;
  };

  /* Audit */
  function reportLabel(type) {
    if (APP_REPORT_TITLES[type]) return t(APP_REPORT_TITLES[type]);
    if (type === 'app_devices') return t('Dispositivos com o app');
    if (type === 'device_compliance') return t('Motivos de não conformidade');
    for (const group of NAV) {
      const item = group.items.find(([id]) => id === type);
      if (item) return t(item[1]);
    }
    return type;
  }

  function openSessionReport(payload) {
    const type = payload._meta.report;
    const params = payload._meta.params || {};
    if (type === 'app_devices') {
      // The server sorts and bounds these IDs; an older tab entry may use the original order.
      App.cache[type + JSON.stringify(params)] = payload;
      modal(t('Dispositivos com o app'), appDevicesPanel(params.ids, params.name));
    } else if (type === 'device_compliance') {
      App.cache[type + JSON.stringify(params)] = payload;
      modal(params.name || params.id, compliancePanel(params));
    } else if (APP_REPORT_TITLES[type]) {
      App.cache[type + JSON.stringify(params)] = payload;
      modal(params.name || params.id, managedAppPanel(type, params));
    } else {
      if (type === 'usage') App.params.usagePeriod = params.period;
      if (type === 'devices') App.params.devStale = params.stale_days;
      App.go(type);
    }
  }

  views.activity = () => {
    const local = h('ul', { class: 'report-jobs' });
    const remote = h('ul', { class: 'report-jobs' });
    const notice = h('p', { class: 'muted small', role: 'status' });
    const refresh = h('button', { class: 'btn sm', type: 'button', onclick: load }, icon('refresh'), 'Atualizar lista');
    let waitController = null;
    const stop = h('button', { class: 'btn sm', type: 'button', hidden: true, onclick: () => waitController?.abort() }, 'Cancelar espera');
    const view = h('section', { class: 'panel report-activity' },
      workspaceHeading('Meus relatórios', 'Retome consultas interrompidas desta sessão ou abra um resultado que já chegou a esta aba.', h('div', { class: 'job-controls' }, stop, refresh)),
      h('h3', null, 'No servidor'), notice, remote,
      h('h3', null, 'Disponíveis nesta aba'), local);
    const status = { pending: 'Aguardando', running: 'Em processamento', done: 'Pronto para abrir', error: 'Falhou' };
    let busy = false;
    const button = (label, click) => h('button', { class: 'btn sm', type: 'button', onclick: click }, label);
    const row = (title, detail, ...controls) => h('li', null,
      h('div', { class: 'job-info' }, h('strong', null, raw(title)), detail),
      h('div', { class: 'job-controls' }, ...controls));
    const supported = (type) => App.me.reports.includes(type) && !type.startsWith('act_') && (type === 'app_devices' || type === 'device_compliance' || APP_REPORT_TITLES[type] || typeof views[type] === 'function');
    function drawLocal() {
      const results = [...new Set(Object.values(App.cache))].filter((p) => supported(p?._meta?.report));
      results.sort((a, b) => String(b._meta.generated_at).localeCompare(String(a._meta.generated_at)));
      local.replaceChildren(...results.map((p) => {
        const m = p._meta, params = m.params || {};
        const suffix = m.report === 'usage' ? params.period : m.report === 'app_devices' || m.report === 'device_compliance' || APP_REPORT_TITLES[m.report] ? params.name : '';
        const title = reportLabel(m.report) + (suffix ? ' · ' + suffix : '');
        return row(title, h('small', null, raw(fmtDate(m.generated_at))), button('Abrir relatório', () => openSessionReport(p)));
      }));
      if (!results.length) local.append(h('li', { class: 'muted' }, 'Nenhum resultado disponível nesta aba.'));
    }
    async function load() {
      if (busy) return;
      busy = true;
      refresh.disabled = true;
      notice.textContent = t('Carregando…');
      drawLocal();
      try {
        const { jobs } = await api('jobs', { timeout: REPORT_REQUEST_MS });
        const rows = jobs.filter((j) => supported(j.type) && Object.hasOwn(status, j.status));
        remote.replaceChildren(...rows.map((j) => {
          const detail = h('small', null, t(status[j.status]), ' · ', raw(fmtDate(j.updatedAt)), ' · ', raw(j.job_id.slice(0, 8)));
          if (j.message) detail.append(h('span', { class: 'job-message' }, j.message));
          return row(reportLabel(j.type), detail,
            j.status !== 'error' ? button(j.status === 'done' ? 'Abrir relatório' : 'Retomar relatório', () => manage(j, false)) : null,
            button('Descartar', () => manage(j, true)));
        }));
        notice.textContent = rows.length === 50 ? t('Exibindo as 50 consultas mais recentes, com as que estão em andamento primeiro.') : rows.length ? '' : t('Nenhuma consulta pendente, resultado para retirar ou falha nesta sessão.');
        drawLocal();
      } catch (error) { notice.textContent = t(error.message); }
      finally { busy = false; refresh.disabled = false; }
    }
    async function manage(job, discard) {
      if (busy || (discard && !window.confirm(t('Descartar esta consulta e seus dados temporários?')))) return;
      busy = true;
      refresh.disabled = true;
      for (const b of remote.querySelectorAll('button')) b.disabled = true;
      try {
        if (discard) {
          await api('jobs/' + job.job_id + '/discard', { method: 'POST', timeout: REPORT_REQUEST_MS });
          readJobs.get(job.job_id)?.controller.abort(new Error(t('Relatório descartado.')));
        } else {
          waitController = new AbortController();
          stop.hidden = false;
          const payload = await withDeadline(REPORT_WAIT_MS, waitController.signal, (signal) =>
            followReadJob(job, (j) => { notice.textContent = t(j.message || status[j.status]); }, { signal, timeout: REPORT_REQUEST_MS }),
            new Error(t('O relatório excedeu o tempo de espera. Gere novamente para tentar.')));
          if (payload?._meta?.report !== job.type) throw new Error(t('O servidor devolveu um resultado inválido para o relatório.'));
          const params = payload._meta.params;
          App.cache[job.type + JSON.stringify(Array.isArray(params) ? {} : (params || {}))] = payload;
          if (view.isConnected) openSessionReport(payload);
        }
      } catch (error) {
        if (view.isConnected) {
          if (waitController?.signal.aborted) notice.textContent = t('Espera interrompida. Você pode retomar o relatório enquanto ele estiver disponível.');
          else notice.textContent = error.status === 404 ? t('O relatório foi retirado por outra aba, descartado ou expirou.') : t(error.message);
        }
        drawLocal();
        return;
      } finally {
        waitController = null;
        stop.hidden = true;
        busy = false;
        refresh.disabled = false;
        for (const b of remote.querySelectorAll('button')) b.disabled = false;
      }
      if (view.isConnected) await load();
    }
    load();
    return view;
  };

  views.audit = () => {
    const P = App.me.privacy || CFG.privacy || {};
    const box = h('div', { class: 'panel' }, workspaceHeading('Auditoria do tenant', t('Eventos deste portal, guardados por {n} dias. Filtre todo o período e carregue eventos anteriores para ampliar a pesquisa ou exportação.', { n: P.auditDays || 30 })));
    const ACTOR = { you: ['Você', 'info'], other: ['Outro usuário (pseudônimo)', ''], system: ['Sistema', 'ok'] };
    const actions = { report_done: 'Relatório concluído', report_start: 'Relatório iniciado', report_error: 'Falha no relatório',
      login_success: 'Login concluído', login_error: 'Falha no login', consent_error: 'Falha no consentimento',
      tenant_settings: 'Configurações do tenant', report_discard: 'Relatório descartado' };
    const keys = { report: 'Relatório', rows: 'Linhas', error: 'Erro', status: 'Estado', reason: 'Motivo', roles: 'Funções', cap: 'Operação' };
    const summarize = (details) => Object.entries(details || {}).map(([k, v]) => {
      const val = typeof v === 'object' && v !== null ? (Array.isArray(v) ? v.join(', ') : JSON.stringify(v)) : String(v ?? '—');
      return t(keys[k] || k) + ' · ' + (k === 'report' ? t(SHEET_NAMES[val] || val) : val);
    }).join('  /  ');
    const actor = h('select', { class: 'j365-input' }, h('option', { value: '' }, 'Todos'), ...Object.entries(ACTOR).map(([value, [label]]) => h('option', { value }, label)));
    const action = h('select', { class: 'j365-input' }, h('option', { value: '' }, 'Todas'));
    const from = h('input', { class: 'j365-input', type: 'date' }), to = h('input', { class: 'j365-input', type: 'date' });
    const field = (label, control) => h('label', null, h('span', null, label), control);
    const feedback = h('div', { role: 'status', 'aria-live': 'polite', class: 'muted small' });
    const apply = h('button', { type: 'submit', class: 'btn sm primary' }, 'Aplicar');
    const form = h('form', { class: 'audit-filters', onsubmit: (e) => { e.preventDefault(); load(false); } },
      field('A partir de', from), field('Até', to), field('Ação', action), field('Ator', actor), apply);
    const out = h('div'), rows = [];
    const more = h('button', { type: 'button', class: 'btn sm', hidden: true, onclick: () => load(true) }, 'Carregar eventos anteriores');
    let table, cursor, query = {};
    const midnight = (day, next) => {
      if (!day) return '';
      const date = new Date(day + 'T00:00:00');
      if (next) date.setDate(date.getDate() + 1);
      return Math.floor(date.getTime() / 1000);
    };
    async function load(append) {
      if (apply.disabled) return;
      if (!append && from.value && to.value && from.value > to.value) {
        feedback.textContent = t('A data final deve ser igual ou posterior à inicial.');
        return;
      }
      const filters = append ? { ...query, cursor } : { action: action.value, actor: actor.value, after: midnight(from.value, false), until: midnight(to.value, true) };
      apply.disabled = more.disabled = true;
      feedback.textContent = t('Carregando eventos…');
      try {
        const page = await api('audit', { query: filters, timeout: REPORT_REQUEST_MS });
        if (!append) {
          query = filters;
          rows.length = 0;
          action.replaceChildren(h('option', { value: '' }, 'Todas'), ...(page.actions || []).map((id) => h('option', { value: id }, actions[id] || id)));
          action.value = query.action;
        }
        rows.push(...page.rows.map((r) => ({ ...r, event: t(actions[r.action] || r.action), description: summarize(r.details), actorLabel: t(ACTOR[r.actor]?.[0] || r.actor) })));
        if (append && table) table.redraw();
        else {
          table = DataTable({ rows, name: 'auditoria', columns: [
            { key: 'ts', label: 'Quando', render: (r) => fmtDate(r.ts.replace(' ', 'T') + 'Z'), export: (r) => r.ts.replace(' ', 'T') + 'Z' },
            { key: 'event', label: 'Ação' }, { key: 'action', label: 'Código do evento', exportOnly: true },
            { key: 'actorLabel', label: 'Ator', render: (r) => badge(r.actorLabel, ACTOR[r.actor]?.[1]) },
            { key: 'description', label: 'Detalhes', wrap: true },
          ] });
          out.replaceChildren(table);
        }
        cursor = page.nextCursor;
        more.hidden = !cursor;
        feedback.textContent = rows.length === 1 ? t('1 evento carregado.') : t('{n} eventos carregados.', { n: num(rows.length) });
      } catch (error) {
        feedback.textContent = error.message;
      } finally {
        apply.disabled = more.disabled = false;
      }
    }
    box.append(form, feedback, out, more);
    load(false);
    return box;
  };

  /* ------------------------------------------------------------- boot */
  if (!CFG.authenticated) renderLanding(new URLSearchParams(location.search).get('j365_msg') || '');
  else {
    // core.js calls this after swapping the dictionary: re-render in the new language, keeping data.
    window.J365Relang = () => { if (App.me) renderShell(true); };
    renderShell();
  }
})();
