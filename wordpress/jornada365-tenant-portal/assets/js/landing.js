/* Tenant Jornada365 — public landing + in-portal app registration (operator). */
(function () {
  'use strict';
  const J = window.J365;
  if (!J) return;
  const { CFG, t, h, raw, svg, logo, cloudSvg, MS_LOGO, wpApi, copyText, reveal, spotlight, countUp, magnet, toast, dialog, themeToggle, langSwitch, RM } = J;
  const APP = CFG.appName || 'Tenant Jornada365';
  const ASSETS = CFG.assetsUrl || '';
  const CREATOR = CFG.creator || { name: "Sérgio Sant'Ana Júnior", url: 'https://www.linkedin.com/in/sergiosantanacloud/', photo: '' };
  /** Per-tenant model: every customer tenant gets its own app, created when an admin connects it. */
  const PER = CFG.tenancy === 'per_tenant' && !!window.J365Auth;
  const AUTH = window.J365Auth;
  const COMPANY = PER && CFG.spa ? CFG.spa.companyLogin : null;
  const loginLabel = () => (COMPANY && COMPANY.label) || t('Entrar com Microsoft 365');

  /* UI line icons (controls only) */
  const I = {
    arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
    down: '<path d="M12 5v14M6 13l6 6 6-6"/>',
    chevron: '<path d="m6 9 6 6 6-6"/>',
    copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a1 1 0 0 1 1-1h10"/>',
    ext: '<path d="M14 4h6v6M20 4l-9 9"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>',
    check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    spark: '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M5.6 18.4l2.8-2.8M15.6 8.4l2.8-2.8"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    shield: '<path d="M12 3 4.5 6v6c0 4.4 3.2 8.2 7.5 9 4.3-.8 7.5-4.6 7.5-9V6z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
    lock: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
    cloudoff: '<path d="M7 18h10a4 4 0 0 0 .7-7.9A6 6 0 0 0 6.2 9.2 4.5 4.5 0 0 0 7 18z"/><path d="m10 11 4 4M14 11l-4 4"/>',
  };
  const ic = (name, cls = 'ico') => svg(`<svg class="${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${I[name] || ''}</svg>`);
  /** Official Microsoft Fluent UI System Icons (color), served from the plugin (MIT). */
  const fi = (name, cls = 'fi') => h('img', { class: cls, src: `${ASSETS}icons/${name}.svg`, alt: '', width: 48, height: 48, decoding: 'async', loading: 'lazy', draggable: 'false' });

  /* ------------------------------------------------------------ content */
  const REPORTS = [
    { icon: 'people_team', title: 'Usuários & identidade', text: 'Membros, convidados, desabilitados, origem AD ou cloud-only, licenças e admins.', tag: 'Entra ID' },
    { icon: 'person_key', title: 'MFA', text: 'Sem MFA, admins desprotegidos, métodos fracos e resistentes a phishing.', tag: 'Autenticação' },
    { icon: 'clock', title: 'Contas inativas', text: 'Sem login há X dias, nunca logaram e licenças paradas.', tag: 'Higiene' },
    { icon: 'phone_laptop', title: 'Dispositivos', text: 'Híbridos, cloud-only, obsoletos, órfãos do Intune e conformidade.', tag: 'Intune' },
    { icon: 'apps', title: 'Aplicativos & versões', text: 'Inventário por versão, apps não homologados e onde estão.', tag: 'Intune' },
    { icon: 'shield_checkmark', title: 'Acesso Condicional', text: 'Políticas com nomes resolvidos, exclusões e lacunas Zero Trust.', tag: 'Zero Trust' },
    { icon: 'people_list', title: 'Grupos', text: 'Sincronizados do AD, cloud-only, aninhados, vazios e sem dono.', tag: 'Diretório' },
    { icon: 'lock_shield', title: 'Acessos privilegiados', text: 'Atribuições permanentes vs. elegíveis (PIM) e admins sem MFA.', tag: 'PIM' },
    { icon: 'warning', title: 'Risco', text: 'Usuários e detecções de risco do Identity Protection.', tag: 'P2' },
    { icon: 'certificate', title: 'Apps & consentimentos', text: 'Segredos e certificados expirando e consentimentos arriscados.', tag: 'Apps' },
    { icon: 'gauge', title: 'Secure Score', text: 'Pontuação, tendência e recomendações com maior ganho.', tag: 'Defender' },
    { icon: 'coin_multiple', title: 'Licenças & custo', text: 'Licenças ociosas em contas desabilitadas, inativas e convidados.', tag: 'Custo' },
    { icon: 'data_trending', title: 'Uso do Microsoft 365', text: 'Adoção de Teams, Exchange, SharePoint e OneDrive.', tag: 'Adoção' },
    { icon: 'mail', title: 'Caixas de correio', text: 'Caixas lotadas ou perto do limite, cotas, arquivo morto e elegibilidade à expansão automática.', tag: 'Exchange Online' },
    { icon: 'cloud', title: 'OneDrive & SharePoint', text: 'Contas e sites no limite, Top 10 a 50 por consumo e sites sem atividade.', tag: 'Armazenamento' },
    { icon: 'arrow_sync', title: 'Ações em dispositivos', text: 'Sincronizar, reiniciar e acionar o Microsoft Defender (verificação e atualização) por plataforma, em 1 ou em vários dispositivos.', tag: 'Solução' },
    { icon: 'mail_clock', title: 'Arquivamento em um clique', text: 'Ativar ou desativar o arquivo morto, expansão automática e sincronização imediata da caixa — ou o script PowerShell equivalente.', tag: 'Solução' },
    { icon: 'data_pie', title: 'Excel executivo', text: 'Pasta de trabalho com resumo para a diretoria, gráficos nativos e planilhas com filtros de cada área.', tag: 'Relatório' },
    { icon: 'guest', title: 'Convidados', text: 'Colaboração externa por domínio, convites pendentes e obsoletos.', tag: 'B2B' },
    { icon: 'lock_closed', title: 'Políticas de autenticação', text: 'Security Defaults, legacy auth, SSPR e métodos habilitados.', tag: 'Tenant' },
    { icon: 'globe_shield', title: 'Domínios', text: 'Domínios verificados, federados e padrão.', tag: 'Tenant' },
  ];
  /* Hero orbit: official Microsoft Fluent UI System Icons (MIT), one per capability. Captions name the
     capability and the service in plain text — no product logos. */
  const ORBITS = [
    { r: 30, dur: 64, items: [['people_team', 'Identidades', 'Entra ID'], ['shield_checkmark', 'Acesso Condicional', 'Entra ID'], ['phone_laptop', 'Dispositivos', 'Intune'], ['mail', 'Caixas de correio', 'Exchange Online'], ['cloud', 'Arquivos', 'OneDrive']] },
    { r: 45, dur: 104, rev: true, items: [['person_key', 'MFA', 'Entra ID'], ['lock_shield', 'Acessos privilegiados', 'PIM'], ['arrow_sync', 'Sincronização', 'Intune'], ['gauge', 'Secure Score', 'Defender'], ['chat_multiple', 'Colaboração', 'Teams'], ['document_folder', 'Sites', 'SharePoint'], ['coin_multiple', 'Licenças', 'Microsoft 365'], ['apps', 'Aplicativos', 'Intune']] },
  ];
  const SERVICES = ['Entra ID', 'Intune', 'Exchange Online', 'SharePoint', 'OneDrive', 'Teams', 'Defender'];

  /* ------------------------------------------------------------ pieces */
  function signInButton(size = '') {
    const b = h('a', { class: 'ms-btn ' + size, href: CFG.loginUrl || '#', role: 'button' },
      h('span', { class: 'ms-mark', html: MS_LOGO }), h('span', { class: 'ms-label' }, raw(loginLabel())), ic('arrow', 'ico arrow'));
    b.addEventListener('click', (e) => {
      if (PER) { e.preventDefault(); openSignIn(); return; } // per-tenant: e-mail → the tenant's own app
      if (CFG.configured && !(CFG.setup && CFG.setup.forced)) return; // normal sign-in
      e.preventDefault();
      if (CFG.setup && CFG.setup.can) openSetup();
      else notConfigured();
    });
    return size === 'lg' ? magnet(b, 8) : b;
  }

  function avatar(cls = 'av') {
    return CREATOR.photo ? h('img', { class: cls, src: CREATOR.photo, alt: CREATOR.name, width: 96, height: 96, decoding: 'async' }) : h('span', { class: cls + ' av-fallback', 'aria-hidden': 'true' }, raw('SJ'));
  }

  /** "Criado por …" chip linking to the creator's LinkedIn. */
  function creatorChip(cls = '') {
    return h('a', { class: 'creator-chip ' + cls, href: CREATOR.url, target: '_blank', rel: 'noopener', title: 'LinkedIn' },
      avatar('av'), h('span', null, h('small', null, 'Criado por'), h('b', null, raw(CREATOR.name))), ic('ext', 'ico ext'));
  }

  function scanner() {
    const wrap = h('div', { class: 'scan', 'aria-hidden': 'true' });
    wrap.append(h('div', { class: 'scan-glow' }), h('div', { class: 'scan-sweep' }));
    wrap.append(svg('<svg class="scan-rings" viewBox="0 0 100 100"><defs>'
      + '<linearGradient id="jr-a" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#50d8f8" stop-opacity=".9"/><stop offset=".5" stop-color="#00b0ff" stop-opacity=".25"/><stop offset="1" stop-color="#0068b8" stop-opacity=".8"/></linearGradient>'
      + '<linearGradient id="jr-b" x1="1" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#00b0ff" stop-opacity=".55"/><stop offset=".6" stop-color="#50d8f8" stop-opacity=".12"/><stop offset="1" stop-color="#0068b8" stop-opacity=".5"/></linearGradient></defs>'
      + '<circle cx="50" cy="50" r="30" class="r1"/><circle cx="50" cy="50" r="30" class="r1c"/>'
      + '<circle cx="50" cy="50" r="37.5" class="rd"/>'
      + '<circle cx="50" cy="50" r="45" class="r2"/><circle cx="50" cy="50" r="45" class="r2t"/><circle cx="50" cy="50" r="45" class="r2b"/></svg>'));
    // Data packets travelling on the rings
    for (const [r, dur, rev, d] of [[30, 11, false, 0], [45, 17, true, 3], [45, 23, false, 9], [21, 7, true, 1]]) {
      wrap.append(h('div', { class: 'orbit pk' + (rev ? ' rev' : ''), style: `--dur:${dur}s;animation-delay:-${d}s` }, h('i', { class: 'packet', style: `--r:${r}` })));
    }
    for (const o of ORBITS) {
      const orbit = h('div', { class: 'orbit' + (o.rev ? ' rev' : ''), style: `--dur:${o.dur}s` });
      o.items.forEach(([name, label, svc], i) => {
        const a = (360 / o.items.length) * i + (o.rev ? 22 : -18);
        orbit.append(h('div', { class: 'sat', style: `--a:${a}deg;--r:${o.r};--i:${i}` },
          h('div', { class: 'sat-i', 'data-label': t(label) + ' · ' + svc }, fi(name, 'fi'))));
      });
      wrap.append(orbit);
    }
    const L = CFG.logo || {};
    const core = h('div', { class: 'scan-core' }, h('i', { class: 'ping' }), h('i', { class: 'ping p2' }), h('i', { class: 'core-ring' }), h('i', { class: 'core-shine' }),
      L.light ? h('span', { class: 'core-logo' }, h('img', { class: 'logo-light', src: L.light, alt: 'Jornada 365' }), h('img', { class: 'logo-dark', src: L.dark || L.light, alt: '' })) : svg(cloudSvg('jcore')));
    wrap.append(core);
    const strip = h('div', { class: 'svc-strip', 'aria-label': t('Serviços analisados') }, ...SERVICES.map((x) => h('span', null, raw(x))));
    const visual = h('div', { class: 'hero-visual' }, wrap, strip);
    // Subtle 3D tilt that follows the pointer (desktop only, never with reduced motion).
    try {
      if (window.matchMedia('(pointer: fine)').matches && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        let raf = 0;
        visual.addEventListener('pointermove', (e) => {
          const b = visual.getBoundingClientRect();
          const x = (e.clientX - b.left) / b.width - 0.5, y = (e.clientY - b.top) / b.height - 0.5;
          cancelAnimationFrame(raf);
          raf = requestAnimationFrame(() => { wrap.style.setProperty('--rx', (-y * 9).toFixed(2) + 'deg'); wrap.style.setProperty('--ry', (x * 11).toFixed(2) + 'deg'); });
        });
        visual.addEventListener('pointerleave', () => { cancelAnimationFrame(raf); wrap.style.setProperty('--rx', '0deg'); wrap.style.setProperty('--ry', '0deg'); });
      }
    } catch (e) { /* matchMedia unavailable */ }
    return visual;
  }

  function stat(value, label) {
    return h('div', { class: 'stat' }, h('div', { class: 'stat-v' }, h('b', { 'data-count': value }, raw('0'))), h('span', { class: 'stat-l' }, label));
  }

  function nav() {
    const links = h('nav', { class: 'nav-links', 'aria-label': 'Seções' },
      h('a', { href: '#como-funciona' }, 'Como funciona'), h('a', { href: '#relatorios' }, 'Relatórios'), h('a', { href: '#seguranca' }, 'Segurança'), h('a', { href: '#faq' }, 'Perguntas'));
    const bar = h('header', { class: 'lx-nav' },
      h('a', { class: 'brand', href: '#top', 'aria-label': APP }, logo('nav-logo'), h('span', { class: 'brand-chip' }, raw('Tenant'))),
      links,
      h('div', { class: 'nav-tools' }, langSwitch(), themeToggle(), h('div', { class: 'nav-cta' }, signInButton('sm'))));
    const onScroll = () => bar.classList.toggle('scrolled', window.scrollY > 12);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    return bar;
  }

  function hero(message) {
    const note = !CFG.configured
      ? (CFG.setup && CFG.setup.can
        ? h('p', { class: 'hero-note op' }, ic('spark'), h('span', null, 'Você é administrador deste portal: o primeiro clique registra o ', h('strong', null, raw(APP)), ' no tenant da Jornada 365 e concede as permissões.'))
        : h('p', { class: 'hero-note' }, ic('clock'), 'Portal em configuração. O login estará disponível em breve.'))
      : h('p', { class: 'hero-note' }, ic('cloudoff'), h('span', null, h('strong', null, 'Nada fica salvo no servidor.'), ' ', 'Os relatórios vão direto para o seu navegador e desaparecem quando você sai.'));
    const cta = signInButton('lg');
    return h('section', { class: 'lx-hero', id: 'top' },
      h('div', { class: 'hero-copy' },
        h('div', { class: 'hero-top', 'data-reveal': '' }, h('div', { class: 'eyebrow' }, h('i', { class: 'live' }), raw('Microsoft 365 · Entra ID · Intune')), creatorChip('hero-creator')),
        h('h1', { 'data-reveal': '', style: '--d:1' }, h('span', { class: 'grad' }, 'Raio-X'), ' ', 'completo do seu tenant.'),
        h('p', { class: 'lead', 'data-reveal': '', style: '--d:2' }, 'Identidade, MFA, dispositivos, Acesso Condicional, caixas de correio, OneDrive, licenças e segurança de aplicativos — em minutos, direto do Microsoft Graph, com ações para resolver e Excel executivo pronto.'),
        message ? h('div', { class: 'note warn', role: 'alert' }, message) : null,
        h('div', { class: 'cta-row hero-cta', 'data-reveal': '', style: '--d:3' }, cta, h('a', { class: 'ghost-btn', href: '#relatorios' }, 'Ver relatórios', ic('down'))),
        h('div', { 'data-reveal': '', style: '--d:4' }, note),
        h('div', { class: 'hero-foot', 'data-reveal': '', style: '--d:5' },
          h('div', { class: 'stats' }, stat(CFG.reportCount || 17, 'relatórios'), stat((CFG.permissions || []).length || 11, 'permissões somente leitura'), stat(0, 'dados salvos no servidor')))),
      scanner(),
      h('a', { class: 'scroll-cue', href: '#como-funciona', 'aria-label': 'Mais informações' }, h('span', { class: 'mouse' }, h('i')), ic('chevron', 'ico chev')));
  }

  function steps() {
    const icon = CFG.iconUrl ? h('img', { src: CFG.iconUrl, alt: '' }) : svg(cloudSvg('jmock'));
    const signin = h('div', { class: 'mock-signin', 'aria-hidden': 'true' },
      h('div', { class: 'mc-top' }, h('span', { class: 'mc-ms', html: MS_LOGO }), h('span', null, raw('Microsoft'))),
      h('b', { class: 'ms-title' }, 'Entrar'),
      h('div', { class: 'ms-field' }, raw('admin@contoso.com'), h('i', { class: 'caret' })),
      h('div', { class: 'mc-btns' }, h('span', { class: 'on' }, 'Avançar')));
    const consent = h('div', { class: 'mock-consent', 'aria-hidden': 'true' },
      h('div', { class: 'mc-top' }, h('span', { class: 'mc-ms', html: MS_LOGO }), h('span', null, raw('Microsoft'))),
      h('div', { class: 'mc-app' }, h('span', { class: 'mc-icon' }, icon), h('div', null, h('b', null, raw(APP)), h('small', null, raw('jornada365.cloud')))),
      h('div', { class: 'mc-lines' }, h('i'), h('i'), h('i', { class: 'short' })),
      h('div', { class: 'mc-btns' }, h('span', null, 'Cancelar'), h('span', { class: 'on' }, 'Aceitar')));
    const report = h('div', { class: 'mock-report', 'aria-hidden': 'true' },
      h('div', { class: 'mr-kpis' },
        h('div', null, h('b', null, raw('1.284')), h('span', null, 'Usuários')),
        h('div', { class: 'bad' }, h('b', null, raw('38')), h('span', null, 'Sem MFA')),
        h('div', { class: 'warn' }, h('b', null, raw('41')), h('span', null, 'Obsoletos'))),
      h('div', { class: 'mr-bars' }, ...[42, 58, 50, 72, 64, 80, 70, 88, 76, 94].map((v, i) => h('i', { style: `--v:${v}%;--i:${i}` }))));
    const card = (n, icn, title, text, extra, d) => h('article', { class: 'step spot', 'data-reveal': '', style: `--d:${d}` },
      h('div', { class: 'step-top' }, h('span', { class: 'step-ic' }, fi(icn)), h('span', { class: 'step-n' }, raw(String(n).padStart(2, '0')))),
      h('h3', null, title), h('p', null, text), extra);
    return h('section', { class: 'lx-section', id: 'como-funciona' },
      h('div', { class: 'sec-head', 'data-reveal': '' }, h('span', { class: 'kicker' }, 'Como funciona'), h('h2', null, 'Do login ao relatório em três passos'), h('p', null, 'Sem scripts, sem cadastro manual de aplicativo, sem senhas guardadas.')),
      h('div', { class: 'steps-track' }, h('i', { class: 'track-line' }),
        PER ? card(1, 'contact_card', 'Informe o e-mail corporativo', 'O portal encontra o seu tenant e abre o login da Microsoft com o aplicativo do próprio tenant.', signin, 1)
          : card(1, 'contact_card', 'Entre com sua conta corporativa', 'Use o botão Entrar com Microsoft 365. O login pede apenas openid, profile e email.', signin, 1),
        PER ? card(2, 'shield_checkmark', 'Conecte o seu tenant (uma vez)', 'Um administrador conecta o tenant pelo próprio portal: o aplicativo Tenant Jornada365 é criado dentro do seu tenant, com permissões delegadas somente leitura — sem segredo e sem certificado.', consent, 2)
          : card(2, 'shield_checkmark', 'Aprove o Tenant Jornada365', 'Na primeira vez, o Global Administrator vê a tela de permissões da Microsoft e clica em Aceitar. O aplicativo empresarial é criado no seu tenant.', consent, 2),
        PER ? card(3, 'data_pie', 'Relatórios prontos', 'Os relatórios são consultados com a sua própria conta e entregues direto ao seu navegador, prontos para filtrar e exportar.', report, 3)
          : card(3, 'data_pie', 'Relatórios prontos', 'Os relatórios são gerados pelo aplicativo aprovado e entregues direto ao seu navegador, prontos para filtrar e exportar.', report, 3)));
  }

  function bento() {
    const tiles = REPORTS.map((r, i) => h('article', { class: 'tile spot', 'data-reveal': '', style: `--d:${(i % 8) + 1}`, title: t(r.tag) },
      h('span', { class: 'tile-ic' }, fi(r.icon)),
      h('div', { class: 'tile-txt' }, h('h3', null, r.title), h('p', null, r.text))));
    return h('section', { class: 'lx-section', id: 'relatorios' },
      h('div', { class: 'sec-head', 'data-reveal': '' }, h('span', { class: 'kicker' }, 'Relatórios'), h('h2', null, 'Tudo o que um analista de Modern Workplace precisa ver'), h('p', null, 'Filtros rápidos, detalhes por item, ações para resolver na hora e exportação em Excel com resumo executivo e gráficos.')),
      h('div', { class: 'bento' }, ...tiles));
  }

  function permissionsPanel() {
    const groups = CFG.permissionGroups || [];
    return h('div', { class: 'perm' },
      ...groups.map((g) => h('div', { class: 'perm-group' }, h('div', { class: 'perm-title' }, g.label),
        h('ul', null, ...g.permissions.map((p) => h('li', null, h('code', null, raw(p.name)), h('span', null, p.why)))))));
  }

  function trust() {
    const facts = [
      ['cloud_dismiss', 'Nada fica salvo no servidor', 'Relatórios processados em memória e entregues só ao seu navegador. O que está em andamento fica cifrado com a chave da sua sessão.'],
      PER ? ['document_lock', 'Somente leitura por padrão', 'Os relatórios só leem, com as permissões da sua própria conta. Ações (sincronizar, arquivar) rodam apenas quando você clica.']
        : ['document_lock', 'Somente leitura', 'Todas as permissões são Read. Nada é criado ou alterado no seu tenant.'],
      PER ? ['certificate', 'Sem senhas, sem segredos', 'O aplicativo do seu tenant não tem segredo nem certificado. O login é da Microsoft, com a sua MFA e o seu Acesso Condicional; os tokens ficam só no seu navegador.']
        : ['certificate', 'Sem senhas, sem segredos', 'Autenticação por certificado: a chave privada nunca sai do servidor e sua senha nunca passa por aqui.'],
      ['building', 'Isolado por tenant', 'Cada sessão é amarrada ao seu tenant, com chave própria, e verificada a cada chamada.'],
      ['shield_checkmark', 'Zero Trust contínuo', 'Sua função é revalidada a cada 15 minutos e em toda alteração — perdeu a função, perdeu o acesso.'],
      PER ? ['dismiss_circle', 'Seu tenant, seu aplicativo', 'O aplicativo fica no seu tenant e não depende de nenhum outro. Exclua-o no Entra ID e o acesso para na hora.']
        : ['dismiss_circle', 'Revogue quando quiser', 'Exclua o aplicativo empresarial Tenant Jornada365 no Entra ID e o acesso para na hora.'],
    ];
    return h('section', { class: 'lx-trust', id: 'seguranca' },
      h('div', { class: 'trust-in' },
        h('div', { class: 'sec-head left', 'data-reveal': '' }, h('span', { class: 'kicker' }, 'Zero Trust'), h('h2', null, 'Segurança por padrão'), h('p', null, 'Mínimo privilégio, nenhum dado guardado e transparência total sobre o que é pedido.')),
        h('div', { class: 'facts' }, ...facts.map(([i, title, text], n) => h('div', { class: 'fact spot', 'data-reveal': '', style: `--d:${n + 1}` }, h('span', { class: 'fact-ic' }, fi(i)), h('div', null, h('h3', null, title), h('p', null, text))))),
        h('details', { class: 'perm-box', 'data-reveal': '' }, h('summary', null, ic('lock'), h('span', null, 'Ver as permissões do Microsoft Graph e o fluxo de dados')), h('div', { class: 'perm-body' }, permissionsPanel(),
          h('ol', { class: 'flow' },
            PER ? h('li', null, 'Você informa o e-mail e entra na Microsoft com o aplicativo do seu próprio tenant.') : h('li', null, 'Você entra com a conta corporativa (openid, profile, email).'),
            h('li', null, 'O servidor confirma no seu tenant que você tem uma função de administrador ou leitor.'),
            PER ? h('li', null, 'Os relatórios são consultados no Microsoft Graph com a sua conta (permissões delegadas).') : h('li', null, 'Os relatórios são consultados no Microsoft Graph com o aplicativo aprovado pelo seu administrador.'),
            h('li', null, 'O resultado vai direto para o seu navegador; nada é gravado no servidor.'))))));
  }

  function consentLinkFor(domain) {
    const d = String(domain || '').trim().toLowerCase();
    if (PER) return connectLink('', d);
    if (!d) return CFG.consentUrl;
    return CFG.consentUrl + (CFG.consentUrl.includes('?') ? '&' : '?') + 't=' + encodeURIComponent(d);
  }

  function adminCard() {
    const input = h('input', { type: 'text', placeholder: 'contoso.com (opcional)', 'aria-label': 'Domínio do tenant', autocomplete: 'off', spellcheck: 'false' });
    const out = h('code', { class: 'link-out' }, raw(consentLinkFor('')));
    input.addEventListener('input', () => { out.textContent = consentLinkFor(input.value); });
    const btn = h('button', { class: 'btn-solid', type: 'button', onclick: () => copyText(consentLinkFor(input.value), btn) }, ic('copy'), 'Copiar link');
    return h('div', { class: 'admin-card spot', 'data-reveal': '' },
      PER ? h('div', { class: 'admin-copy' }, h('span', { class: 'kicker' }, 'Não é administrador?'), h('h2', null, 'Envie o link de conexão para o seu admin'),
        h('p', null, 'Um administrador (Global, Cloud Application ou Application Administrator) abre o link e conecta o tenant em 1 minuto. Depois disso, você entra normalmente.'))
        : h('div', { class: 'admin-copy' }, h('span', { class: 'kicker' }, 'Não é administrador?'), h('h2', null, 'Envie o link de aprovação para o seu admin'),
        h('p', null, 'Um Global Administrator ou Privileged Role Administrator abre o link, revisa as permissões na tela da Microsoft e aprova. Depois disso, você entra normalmente.')),
      h('div', { class: 'admin-form' }, h('label', null, h('span', null, 'Domínio do tenant'), input), out, btn));
  }

  function faq() {
    const qa = PER ? [
      ['Preciso instalar alguma coisa?', 'Não. Um administrador conecta o tenant pelo portal e o aplicativo Tenant Jornada365 é criado dentro do próprio tenant, sem script.'],
      ['Quem pode conectar?', 'Global Administrator, Cloud Application Administrator ou Application Administrator do seu tenant, uma única vez.'],
      ['Depende do tenant de outra empresa?', 'Não. O aplicativo é criado e fica no seu tenant; o portal não usa aplicativo de nenhum outro tenant e não guarda credenciais.'],
      ['Quais licenças são necessárias?', 'Atividade de login e Acesso Condicional exigem Entra ID P1; risco exige P2; dispositivos e aplicativos exigem Intune. O portal avisa quando algo não estiver disponível.'],
      ['O que fica salvo no servidor?', 'Nenhum dado do seu tenant e nenhuma credencial. Os relatórios ficam só no seu navegador até você fechar a aba ou sair. O servidor guarda apenas o ID do tenant e o ID do aplicativo do seu tenant (identificadores públicos) e um registro de segurança pseudonimizado, apagado em 30 dias.'],
      ['Como removo o acesso?', 'Exclua o aplicativo Tenant Jornada365 em Entra ID → Registros de aplicativo. O acesso para na hora.'],
    ] : [
      ['Preciso instalar alguma coisa?', 'Não. O administrador aprova o Tenant Jornada365 na tela da Microsoft e o aplicativo empresarial é criado automaticamente no tenant.'],
      ['Quem pode aprovar?', 'Um Global Administrator ou Privileged Role Administrator do seu tenant, uma única vez.'],
      ['Quais licenças são necessárias?', 'Atividade de login e Acesso Condicional exigem Entra ID P1; risco exige P2; dispositivos e aplicativos exigem Intune. O portal avisa quando algo não estiver disponível.'],
      ['O que fica salvo no servidor?', 'Nenhum dado do seu tenant. Os relatórios ficam só no seu navegador até você fechar a aba ou sair. O servidor guarda apenas o ID do tenant (para permitir revogar o acesso) e um registro de segurança pseudonimizado, sem nomes ou e-mails, apagado em 30 dias.'],
      ['Como removo o acesso?', 'Exclua o aplicativo empresarial Tenant Jornada365 em Entra ID → Aplicativos empresariais. Sessões e tokens param imediatamente.'],
    ];
    return h('div', { class: 'faq-wrap' },
      h('div', { class: 'sec-head', 'data-reveal': '' }, h('span', { class: 'kicker' }, 'Perguntas'), h('h2', null, 'Perguntas frequentes')),
      h('div', { class: 'faq' }, ...qa.map(([q, a], i) => h('details', { 'data-reveal': '', style: `--d:${i + 1}` }, h('summary', null, q), h('p', null, a)))));
  }

  function creatorCard() {
    return h('aside', { class: 'creator-card spot', 'data-reveal': '' },
      h('div', { class: 'creator-photo' }, avatar('av-lg')),
      h('div', { class: 'creator-copy' },
        h('span', { class: 'kicker' }, 'Sobre o projeto'),
        h('h3', null, raw(CREATOR.name)),
        h('p', null, 'Projeto de código aberto criado por Sérgio Sant\'Ana Júnior, da Jornada 365, para administrar e analisar o próprio tenant do Microsoft 365.'),
        h('a', { class: 'btn-solid', href: CREATOR.url, target: '_blank', rel: 'noopener' }, 'Conectar no LinkedIn', ic('ext'))));
  }

  function footer() {
    return h('footer', { class: 'lx-foot' },
      h('div', { class: 'foot-cta', 'data-reveal': '' }, h('h2', null, 'Pronto para ver o seu tenant?'), signInButton('lg'), h('span', { class: 'foot-note' }, ic('cloudoff'), 'Nada fica salvo no servidor.')),
      h('div', { class: 'foot-row' },
        h('div', { class: 'foot-brand' }, logo('foot-logo'), h('span', { class: 'tagline' }, 'Inove, conecte-se, evolua.')),
        h('div', { class: 'foot-links' },
          h('a', { href: CREATOR.url, target: '_blank', rel: 'noopener' }, 'Criado por', ' ', raw(CREATOR.name)),
          h('a', { href: 'https://github.com/sesantanajr/jornada365-tenant-reports', target: '_blank', rel: 'noopener' }, 'Código-fonte (GPL)'),
          CFG.privacyUrl ? h('a', { href: CFG.privacyUrl, target: '_blank', rel: 'noopener' }, 'Privacidade') : null,
          CFG.termsUrl ? h('a', { href: CFG.termsUrl, target: '_blank', rel: 'noopener' }, 'Termos') : null,
          CFG.support ? h('a', { href: 'mailto:' + CFG.support }, 'Suporte') : null,
          h('a', { href: 'https://jornada365.cloud', target: '_blank', rel: 'noopener' }, raw('jornada365.cloud')),
          h('span', { class: 'ver' }, raw('v' + (CFG.version || '')))),
        langSwitch()));
  }

  /** Visitors never see the (possibly hidden) WordPress login URL. */
  function notConfigured() {
    const d = dialog({
      title: 'Portal em configuração',
      body: h('div', { class: 'dlg-body' },
        h('p', null, 'O aplicativo ainda está sendo registrado pelo administrador do portal. Tente novamente em alguns minutos.'),
        h('div', { class: 'dlg-actions' }, h('button', { class: 'btn-ghost', type: 'button', onclick: () => d.close() }, 'Fechar'))),
    });
  }

  /* ------------------------------------------------------------ in-portal registration */
  function openSetup() {
    const body = h('div', { class: 'setup' });
    const d = dialog({ title: CFG.configured ? 'Atualizar o aplicativo' : 'Registrar o Tenant Jornada365', body, wide: true, cls: 'setup-dlg', onClose: () => { stopped = true; } });
    let stopped = false;
    let gen = 0; // each sign-in attempt invalidates the previous polling loop

    const intro = () => h('div', { class: 'setup-intro' },
      h('div', { class: 'setup-app' }, CFG.iconUrl ? h('img', { src: CFG.iconUrl, alt: '' }) : svg(cloudSvg('jsetup')), h('div', null, h('b', null, raw(APP)), h('small', null, 'Aplicativo multi-tenant · somente leitura · certificado'))),
      h('p', null, 'Entre com um ', h('strong', null, 'Global Administrator do tenant da Jornada 365'), '. O portal cria o aplicativo, envia o logo e o certificado, concede as permissões e já entra — sem script e sem client secret.'));

    function startDevice() {
      const my = ++gen;
      body.innerHTML = '';
      body.append(intro(), h('div', { class: 'setup-wait' }, h('span', { class: 'spinner' }), 'Gerando o código de entrada…'));
      wpApi('bootstrap/start', { method: 'device' }).then((r) => {
        if (stopped || my !== gen) return;
        const code = h('div', { class: 'devcode', 'aria-label': r.userCode }, ...[...r.userCode].map((c) => h('span', null, raw(c))));
        const copyBtn = h('button', { class: 'btn-ghost', type: 'button', onclick: () => copyText(r.userCode, copyBtn) }, ic('copy'), 'Copiar código');
        const open = h('a', { class: 'btn-solid', href: r.verificationUri, target: '_blank', rel: 'noopener', onclick: (e) => { e.preventDefault(); copyText(r.userCode); window.open(r.verificationUri, 'j365ms', 'noopener,width=520,height=760'); } }, h('span', { class: 'ms-mark', html: MS_LOGO }), 'Abrir a página da Microsoft', ic('ext'));
        const left = h('span', { class: 'muted small' });
        const until = Date.now() + r.expiresIn * 1000;
        const tick = setInterval(() => { const s = Math.max(0, Math.round((until - Date.now()) / 1000)); left.textContent = t('O código expira em {s}', { s: `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}` }); if (!s || stopped || my !== gen) clearInterval(tick); }, 1000);
        body.innerHTML = '';
        body.append(intro(),
          h('ol', { class: 'setup-steps' },
            h('li', null, 'Clique em ', h('strong', null, 'Abrir a página da Microsoft'), ' (o código já vai copiado).'),
            h('li', null, 'Cole o código, entre com a conta de Global Administrator e aceite as permissões do ', h('em', null, raw('Microsoft Graph Command Line Tools')), '.'),
            h('li', null, 'Volte para esta janela — o resto é automático.')),
          code,
          h('div', { class: 'setup-actions' }, open, copyBtn),
          h('div', { class: 'setup-wait' }, h('span', { class: 'radar' }), h('span', null, 'Aguardando você entrar na Microsoft…'), left),
          h('p', { class: 'fine' }, raw('Microsoft Graph Command Line Tools'), ' ', 'é o cliente oficial usado pelo Connect-MgGraph. A autorização vale só para esta configuração e é descartada no final.'),
          h('button', { class: 'linkish', type: 'button', onclick: () => { clearInterval(tick); startBrowser(); } }, 'A entrada por código está bloqueada no seu tenant? Entrar pelo navegador'));
        const poll = (ms) => setTimeout(async () => {
          if (stopped || my !== gen) return;
          try {
            const p = await wpApi('bootstrap/poll');
            if (p.status === 'authorized') { clearInterval(tick); provision(p); return; }
            poll((p.interval || r.interval) * 1000);
          } catch (e) { clearInterval(tick); failBox(e.message, startDevice); }
        }, ms);
        poll(r.interval * 1000);
      }).catch((e) => failBox(e.message, startDevice));
    }

    function startBrowser() {
      ++gen;
      body.innerHTML = '';
      body.append(intro(), h('div', { class: 'setup-wait' }, h('span', { class: 'spinner' }), 'Preparando…'));
      wpApi('bootstrap/start', { method: 'browser' }).then((r) => {
        const field = h('input', { type: 'url', placeholder: 'http://localhost/?code=…', 'aria-label': 'Endereço de retorno', spellcheck: 'false' });
        const go = h('button', { class: 'btn-solid', type: 'button' }, 'Continuar');
        go.addEventListener('click', async () => {
          go.disabled = true;
          try { const p = await wpApi('bootstrap/code', { url: field.value }); provision(p); } catch (e) { toast(e.message, 'danger', 7000); go.disabled = false; }
        });
        body.innerHTML = '';
        body.append(intro(),
          h('ol', { class: 'setup-steps' },
            h('li', null, h('a', { href: r.authorizeUrl, target: '_blank', rel: 'noopener' }, 'Abrir a Microsoft em uma nova aba'), ' e entre com a conta de Global Administrator.'),
            h('li', null, 'No final, a aba mostrará uma página que não abre (http://localhost…). Isso é esperado.'),
            h('li', null, 'Copie o endereço completo da barra do navegador e cole abaixo.')),
          h('div', { class: 'paste-row' }, field, go),
          h('button', { class: 'linkish', type: 'button', onclick: startDevice }, 'Voltar para a entrada por código'));
      }).catch((e) => failBox(e.message, startBrowser));
    }

    function failBox(message, retry) {
      body.innerHTML = '';
      body.append(intro(), h('div', { class: 'note danger', role: 'alert' }, message), h('div', { class: 'setup-actions' }, h('button', { class: 'btn-solid', type: 'button', onclick: retry }, 'Tentar novamente'), h('button', { class: 'btn-ghost', type: 'button', onclick: startBrowser }, 'Entrar pelo navegador')));
    }

    function provision(auth) {
      body.innerHTML = '';
      const list = h('ol', { class: 'prov' });
      const rows = (auth.steps || []).map((s) => { const li = h('li', { class: 'wait' }, h('span', { class: 'st' }), h('div', null, h('b', null, s.label), h('small', null))); list.append(li); return li; });
      const bar = h('div', { class: 'prov-bar' }, h('i'));
      const who = h('div', { class: 'who' }, ic('check'), h('span', null, 'Conectado como ', h('strong', null, raw(auth.upn || '')), ' · ', h('code', null, raw(auth.tenant || ''))));
      body.append(who, bar, list);
      let index = auth.next || 0;
      const mark = (i, cls, detail) => { const li = rows[i]; if (!li) return; li.className = cls; if (detail !== undefined) li.querySelector('small').textContent = t(detail); };
      const run = async () => {
        if (stopped) return;
        mark(index, 'run');
        try {
          const r = await wpApi('bootstrap/step');
          if (r.done && r.step === undefined) { finish(r); return; }
          if (!r.advanced) { mark(r.index, 'run', r.detail); setTimeout(run, r.retryAfter || 3000); return; }
          mark(r.index, r.warning ? 'warn' : 'done', r.warning || r.detail);
          index = r.index + 1;
          bar.firstChild.style.width = Math.round((100 * index) / r.total) + '%';
          if (r.done) finish(r); else setTimeout(run, 250);
        } catch (e) {
          mark(index, 'err', e.message);
          body.append(h('div', { class: 'setup-actions' }, h('button', { class: 'btn-solid', type: 'button', onclick: (ev) => { ev.target.parentNode.remove(); run(); } }, 'Tentar este passo novamente'), h('button', { class: 'btn-ghost', type: 'button', onclick: async () => { await wpApi('bootstrap/cancel').catch(() => {}); d.close(); } }, 'Cancelar')));
        }
      };
      run();
    }

    function finish(r) {
      const login = r.loginUrl || CFG.loginUrl;
      body.innerHTML = '';
      const secs = h('b', null, raw('5'));
      body.append(h('div', { class: 'setup-done' },
        h('div', { class: 'burst' }, ic('check', 'ico big'), ...Array.from({ length: 10 }, (_, i) => h('i', { style: `--i:${i}` }))),
        h('h3', null, 'Tudo pronto!'),
        h('p', null, h('strong', null, raw(APP)), ' ', 'foi registrado e aprovado no seu tenant. Agora qualquer empresa pode entrar e aprovar o aplicativo no próprio tenant.'),
        h('div', { class: 'setup-actions' }, h('a', { class: 'btn-solid', href: login }, 'Entrar agora'), h('span', { class: 'muted small' }, 'Entrando automaticamente em', ' ', secs, raw(' s')))));
      let s = 5;
      const timer = setInterval(() => { s--; secs.textContent = String(s); if (s <= 0) { clearInterval(timer); if (!stopped) location.href = login; } }, 1000);
    }

    startDevice();
  }

  /* ------------------------------------------------------------ per-tenant sign-in */
  const GUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
  /** Link an administrator opens to connect (or update) the tenant's app. */
  function connectLink(tenantId, domain) {
    const link = new URL(CFG.loginUrl || location.href, location.href);
    link.hash = '';
    for (const name of ['signin', 'login_hint', 't', 'd']) link.searchParams.delete(name);
    link.searchParams.set('connect', '1');
    if (tenantId) link.searchParams.set('t', tenantId); else if (domain) link.searchParams.set('d', domain);
    return link.href;
  }
  /** Public auth endpoints answer { ok:false, message } instead of throwing: make failures errors. */
  async function call(path, body) {
    const r = await AUTH.post(path, body);
    if (!r.ok) { const e = new Error(t(r.message || 'Não foi possível concluir. Tente novamente.')); e.data = r; throw e; }
    return r;
  }

  function openSignIn(opts = {}) {
    const mem = AUTH.memory();
    const email = h('input', { type: 'email', class: 'j365-input', name: 'email', autocomplete: 'username', inputmode: 'email', placeholder: 'voce@empresa.com', 'aria-label': 'E-mail corporativo', required: true });
    email.value = opts.email || mem.email || '';
    const go = h('button', { class: 'btn-solid si-go', type: 'submit' }, h('span', { class: 'ms-mark', html: MS_LOGO }), h('span', null, 'Continuar'));
    const note = h('p', { class: 'si-note', role: 'status', 'aria-live': 'polite' });
    const body = h('div', { class: 'si' });
    const form = h('form', { class: 'si-form', novalidate: true }, COMPANY ? null : h('label', { class: 'si-label' }, h('span', null, 'E-mail corporativo'), email), go);
    const d = dialog({ title: loginLabel(), body, cls: 'si-dlg' });
    const show = () => {
      body.innerHTML = '';
      body.append(h('p', { class: 'lead-sm' }, COMPANY ? 'Continue para entrar com a sua conta Microsoft corporativa.' : 'Informe o seu e-mail de trabalho. O portal encontra o seu tenant e abre o login da Microsoft com o aplicativo do próprio tenant.'),
        form, note,
        h('p', { class: 'fine si-fine' }, ic('lock'), h('span', null, 'A senha é digitada só na Microsoft. O aplicativo fica no seu tenant e os tokens, só neste navegador.')));
      requestAnimationFrame(() => (COMPANY ? go : email).focus());
    };
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const q = COMPANY ? COMPANY.tenantId : email.value.trim();
      if (!q) { email.focus(); return; }
      go.disabled = true;
      note.className = 'si-note'; note.textContent = t(COMPANY ? 'Preparando…' : 'Procurando o seu tenant…');
      try {
        const r = await AUTH.post('auth/resolve', { q });
        if (!r.ok) { note.className = 'si-note danger'; note.textContent = t(r.message || 'Não foi possível consultar a Microsoft agora. Tente novamente.'); go.disabled = false; return; }
        if (r.connected) {
          note.textContent = t('Abrindo a Microsoft…');
          AUTH.remember(r.tenantId, r.clientId, r.loginHint);
          await AUTH.signIn({ tenantId: r.tenantId, clientId: r.clientId, loginHint: r.loginHint });
          return;
        }
        body.innerHTML = '';
        body.append(notConnected(r, (mem.tenants || {})[r.tenantId], d, show));
      } catch (err) { note.className = 'si-note danger'; note.textContent = err.message; go.disabled = false; }
    });
    show();
  }

  function notConnected(r, known, d, back) {
    const roles = (CFG.spa && CFG.spa.connectRoles) || [];
    const copyBtn = h('button', { class: 'btn-ghost', type: 'button', onclick: () => copyText(connectLink(r.tenantId), copyBtn) }, ic('copy'), 'Copiar link para o administrador');
    return h('div', { class: 'nc' },
      h('div', { class: 'nc-head' }, h('span', { class: 'nc-ic' }, ic('shield')),
        h('div', null, h('strong', null, t('O tenant {s} ainda não está conectado ao portal.', { s: r.domain || r.tenantId })),
          h('p', null, 'Um administrador conecta o tenant uma única vez, direto daqui: o portal cria o aplicativo Tenant Jornada365 dentro do seu tenant. Sem script, sem segredo, sem depender de outro tenant.'))),
      h('div', { class: 'nc-actions' },
        h('button', { class: 'btn-solid', type: 'button', onclick: () => { d.close(); openConnect({ tenantId: r.tenantId, domain: r.domain, loginHint: r.loginHint }); } }, ic('shield'), 'Sou administrador: conectar o tenant'),
        copyBtn),
      known && known.clientId ? h('button', { class: 'linkish', type: 'button', onclick: () => AUTH.signIn({ tenantId: r.tenantId, clientId: known.clientId, loginHint: r.loginHint }) }, 'Entrar com o aplicativo já usado neste navegador') : null,
      h('div', { class: 'role-chips' }, h('span', { class: 'muted small' }, 'Quem pode conectar:'), ...roles.map((x) => h('span', { class: 'role-chip' }, raw(x)))),
      h('button', { class: 'linkish', type: 'button', onclick: back }, COMPANY ? 'Voltar' : 'Usar outro e-mail'));
  }

  /* ------------------------------------------------------------ connect a tenant (the app is created IN it) */
  function openConnect(opts = {}) {
    const body = h('div', { class: 'setup' });
    let stopped = false;
    let gen = 0;
    let finished = false;
    const d = dialog({
      title: opts.update ? 'Atualizar o aplicativo do seu tenant' : 'Conectar o seu tenant',
      body, wide: true, cls: 'setup-dlg',
      onClose: () => { stopped = true; if (!finished) AUTH.post('onboard/cancel').catch(() => {}); },
    });
    const roles = (CFG.spa && CFG.spa.connectRoles) || [];

    const intro = () => h('div', { class: 'setup-intro' },
      h('div', { class: 'setup-app' }, CFG.iconUrl ? h('img', { src: CFG.iconUrl, alt: '' }) : svg(cloudSvg('jsetup')),
        h('div', null, h('b', null, raw(APP)), h('small', null, 'Criado no seu tenant · permissões delegadas · sem segredo e sem certificado'))),
      h('p', null, opts.update
        ? 'Entre com um administrador do seu tenant. O portal atualiza as permissões do aplicativo Tenant Jornada365 que já está no tenant.'
        : 'Entre com um administrador do seu tenant. O portal cria o aplicativo Tenant Jornada365 dentro do seu tenant, aprova as permissões somente leitura e já entra. Nada é criado fora do seu tenant.'));

    function choose() {
      ++gen;
      body.innerHTML = '';
      body.append(intro(),
        h('ul', { class: 'cx-list' },
          h('li', null, ic('check'), h('span', null, 'Aplicativo de página única com o endereço de retorno deste portal — sem client secret e sem certificado.')),
          h('li', null, ic('check'), h('span', null, 'Permissões delegadas: o portal enxerga só o que a conta de quem entra pode ver, com MFA e Acesso Condicional do seu tenant.')),
          h('li', null, ic('check'), h('span', null, 'Para desconectar: exclua o aplicativo Tenant Jornada365 no Entra ID.'))),
        h('div', { class: 'role-chips' }, h('span', { class: 'muted small' }, 'Quem pode conectar:'), ...roles.map((x) => h('span', { class: 'role-chip' }, raw(x)))),
        h('details', { class: 'perm-box mini' }, h('summary', null, ic('lock'), h('span', null, 'Ver as permissões que serão aprovadas')), h('div', { class: 'perm-body' }, permissionsPanel())),
        h('div', { class: 'setup-actions' },
          h('button', { class: 'btn-solid', type: 'button', onclick: startDevice }, 'Conectar com o código da Microsoft'),
          h('button', { class: 'btn-ghost', type: 'button', onclick: startBrowser }, 'Entrar pelo navegador')),
        h('button', { class: 'linkish', type: 'button', onclick: manual }, 'Prefiro criar o aplicativo manualmente no Entra ID'));
    }

    function startDevice() {
      const my = ++gen;
      body.innerHTML = '';
      body.append(intro(), h('div', { class: 'setup-wait' }, h('span', { class: 'spinner' }), 'Gerando o código de entrada…'));
      call('onboard/start', { method: 'device' }).then((r) => {
        if (stopped || my !== gen) return;
        const code = h('div', { class: 'devcode', 'aria-label': r.userCode }, ...[...r.userCode].map((c) => h('span', null, raw(c))));
        const copyBtn = h('button', { class: 'btn-ghost', type: 'button', onclick: () => copyText(r.userCode, copyBtn) }, ic('copy'), 'Copiar código');
        const open = h('a', { class: 'btn-solid', href: r.verificationUri, target: '_blank', rel: 'noopener', onclick: (e) => { e.preventDefault(); copyText(r.userCode); window.open(r.verificationUri, 'j365ms', 'noopener,width=520,height=760'); } }, h('span', { class: 'ms-mark', html: MS_LOGO }), h('span', null, 'Abrir a página da Microsoft'));
        const left = h('span', { class: 'muted small' });
        const until = Date.now() + r.expiresIn * 1000;
        const tick = setInterval(() => { const sec = Math.max(0, Math.round((until - Date.now()) / 1000)); left.textContent = t('O código expira em {s}', { s: `${Math.floor(sec / 60)}:${String(sec % 60).padStart(2, '0')}` }); if (!sec || stopped || my !== gen) clearInterval(tick); }, 1000);
        body.innerHTML = '';
        body.append(intro(),
          h('ol', { class: 'setup-steps' },
            h('li', null, 'Clique em ', h('strong', null, 'Abrir a página da Microsoft'), ' (o código já vai copiado).'),
            h('li', null, 'Cole o código, entre com a sua conta de administrador e aceite as permissões do ', h('em', null, raw('Microsoft Graph Command Line Tools')), '.'),
            h('li', null, 'Volte para esta janela — o resto é automático.')),
          code,
          h('div', { class: 'setup-actions' }, open, copyBtn),
          h('div', { class: 'setup-wait' }, h('span', { class: 'radar' }), h('span', null, 'Aguardando você entrar na Microsoft…'), left),
          h('p', { class: 'fine' }, raw('Microsoft Graph Command Line Tools'), ' ', 'é o aplicativo oficial da Microsoft usado pelo Connect-MgGraph. A autorização serve só para criar o aplicativo no seu tenant e é descartada no final.'),
          h('button', { class: 'linkish', type: 'button', onclick: () => { clearInterval(tick); startBrowser(); } }, 'A entrada por código está bloqueada no seu tenant? Entrar pelo navegador'));
        const poll = (ms) => setTimeout(async () => {
          if (stopped || my !== gen) return;
          try {
            const p = await call('onboard/poll');
            if (p.status === 'authorized') { clearInterval(tick); provision(p); return; }
            poll((p.interval || r.interval) * 1000);
          } catch (e) { clearInterval(tick); failBox(e.message, startDevice); }
        }, ms);
        poll(r.interval * 1000);
      }).catch((e) => failBox(e.message, startDevice));
    }

    function startBrowser() {
      const my = ++gen;
      body.innerHTML = '';
      body.append(intro(), h('div', { class: 'setup-wait' }, h('span', { class: 'spinner' }), 'Preparando…'));
      call('onboard/start', { method: 'browser' }).then((r) => {
        if (stopped || my !== gen) return;
        const field = h('input', { type: 'url', class: 'j365-input', placeholder: 'http://localhost/?code=…', 'aria-label': 'Endereço de retorno', spellcheck: 'false' });
        const go = h('button', { class: 'btn-solid', type: 'button' }, 'Continuar');
        go.addEventListener('click', async () => {
          go.disabled = true;
          try { const p = await call('onboard/code', { url: field.value }); provision(p); } catch (e) { toast(e.message, 'danger', 7000); go.disabled = false; }
        });
        body.innerHTML = '';
        body.append(intro(),
          h('ol', { class: 'setup-steps' },
            h('li', null, h('a', { href: r.authorizeUrl, target: '_blank', rel: 'noopener' }, 'Abrir a Microsoft em uma nova aba'), ' e entre com a sua conta de administrador.'),
            h('li', null, 'No final, a aba mostrará uma página que não abre (http://localhost…). Isso é esperado.'),
            h('li', null, 'Copie o endereço completo da barra do navegador e cole abaixo.')),
          h('div', { class: 'paste-row' }, field, go),
          h('button', { class: 'linkish', type: 'button', onclick: startDevice }, 'Voltar para a entrada por código'));
      }).catch((e) => failBox(e.message, startBrowser));
    }

    function manual() {
      ++gen;
      body.innerHTML = '';
      const uri = (CFG.spa && CFG.spa.redirectUri) || '';
      const ten = h('input', { type: 'text', class: 'j365-input', placeholder: 'empresa.com ou ID do tenant', 'aria-label': 'Domínio ou ID do tenant', spellcheck: 'false' });
      ten.value = COMPANY ? COMPANY.tenantId : opts.domain || opts.tenantId || '';
      ten.readOnly = !!COMPANY;
      const cid = h('input', { type: 'text', class: 'j365-input mono', placeholder: '00000000-0000-0000-0000-000000000000', 'aria-label': 'ID do aplicativo (cliente)', spellcheck: 'false' });
      const go = h('button', { class: 'btn-solid', type: 'button' }, 'Entrar e aprovar');
      const copyUri = h('button', { class: 'btn-ghost sm', type: 'button', onclick: () => copyText(uri, copyUri) }, ic('copy'), 'Copiar');
      go.addEventListener('click', async () => {
        const c = cid.value.trim().toLowerCase();
        if (!GUID_RE.test(c)) { toast(t('Cole o ID do aplicativo (cliente) — formato 00000000-0000-0000-0000-000000000000.'), 'warn', 6000); cid.focus(); return; }
        go.disabled = true;
        try {
          const r = await call('auth/resolve', { q: ten.value || opts.loginHint || '' });
          AUTH.remember(r.tenantId, c, r.loginHint || opts.loginHint || '');
          await AUTH.signIn({ tenantId: r.tenantId, clientId: c, loginHint: r.loginHint || opts.loginHint || '', prompt: 'consent' });
        } catch (e) { toast(e.message, 'danger', 7000); go.disabled = false; }
      });
      body.append(intro(),
        h('ol', { class: 'setup-steps' },
          h('li', null, 'Entra admin center → Registros de aplicativo → Novo registro. Nome: ', h('strong', null, raw(APP)), '. Tipo de conta: somente este diretório organizacional.'),
          h('li', null, 'URI de redirecionamento: plataforma ', h('strong', null, 'Aplicativo de página única (SPA)'), ': ', h('code', { class: 'uri' }, raw(uri)), ' ', copyUri),
          h('li', null, 'Clique em Registrar e copie o ID do aplicativo (cliente). Não crie segredo nem certificado.'),
          h('li', null, 'Cole abaixo e clique em Entrar e aprovar. Na tela da Microsoft, marque "Consentir em nome da sua organização" e aceite.')),
        h('div', { class: 'paste-row two' }, ten, cid, go),
        h('p', { class: 'fine' }, 'As permissões são pedidas no próprio login (consentimento dinâmico) — não é preciso adicioná-las no registro. Quem aprova precisa de uma destas funções: ', raw(roles.join(', ')), '.'),
        h('button', { class: 'linkish', type: 'button', onclick: choose }, 'Voltar'));
      requestAnimationFrame(() => cid.focus());
    }

    function failBox(message, retry) {
      body.innerHTML = '';
      body.append(intro(), h('div', { class: 'note danger', role: 'alert' }, message),
        h('div', { class: 'setup-actions' }, h('button', { class: 'btn-solid', type: 'button', onclick: retry }, 'Tentar novamente'), h('button', { class: 'btn-ghost', type: 'button', onclick: choose }, 'Outras formas de conectar')));
    }

    function provision(auth) {
      body.innerHTML = '';
      const list = h('ol', { class: 'prov' });
      const rows = (auth.steps || []).map((x) => { const li = h('li', { class: 'wait' }, h('span', { class: 'st' }), h('div', null, h('b', null, x.label), h('small', null))); list.append(li); return li; });
      const bar = h('div', { class: 'prov-bar' }, h('i'));
      const who = h('div', { class: 'who' }, ic('check'), h('span', null, 'Conectado como ', h('strong', null, raw(auth.upn || '')), ' · ', h('code', null, raw(auth.tenant || ''))));
      body.append(who, bar, list);
      let index = auth.next || 0;
      const mark = (i, cls, detail) => { const li = rows[i]; if (!li) return; li.className = cls; if (detail !== undefined) li.querySelector('small').textContent = t(detail); };
      const run = async () => {
        if (stopped) return;
        mark(index, 'run');
        try {
          const r = await call('onboard/step');
          if (r.done && r.step === undefined) { finish(r); return; }
          if (!r.advanced) { mark(r.index, 'run', r.detail); setTimeout(run, r.retryAfter || 3000); return; }
          mark(r.index, r.warning ? 'warn' : 'done', r.warning || r.detail);
          index = r.index + 1;
          bar.firstChild.style.width = Math.round((100 * index) / r.total) + '%';
          if (r.done) finish(r); else setTimeout(run, 250);
        } catch (e) {
          mark(index, 'err', e.message);
          const again = h('div', { class: 'setup-actions' },
            h('button', { class: 'btn-solid', type: 'button', onclick: () => { again.remove(); run(); } }, 'Tentar este passo novamente'),
            h('button', { class: 'btn-ghost', type: 'button', onclick: choose }, 'Recomeçar'));
          body.append(again);
        }
      };
      run();
    }

    function finish(r) {
      finished = true;
      body.innerHTML = '';
      const secs = h('b', null, raw('4'));
      const enter = () => AUTH.signIn({ tenantId: r.tenantId, clientId: r.clientId, loginHint: r.loginHint });
      AUTH.remember(r.tenantId, r.clientId, r.loginHint);
      body.append(h('div', { class: 'setup-done' },
        h('div', { class: 'burst' }, ic('check', 'ico big'), ...Array.from({ length: 10 }, (_, i) => h('i', { style: `--i:${i}` }))),
        h('h3', null, opts.update ? 'Aplicativo atualizado!' : 'Tenant conectado!'),
        h('p', null, h('strong', null, raw(APP)), ' ', opts.update ? 'foi atualizado no seu tenant. Entre de novo para usar as novas permissões.' : 'foi criado no seu tenant, com as permissões aprovadas. Agora é só entrar.'),
        h('div', { class: 'setup-actions' }, h('button', { class: 'btn-solid', type: 'button', onclick: enter }, 'Entrar agora'), h('span', { class: 'muted small' }, 'Entrando automaticamente em', ' ', secs, raw(' s')))));
      let n = 4;
      const timer = setInterval(() => { n--; secs.textContent = String(n); if (n <= 0) { clearInterval(timer); if (!stopped) enter(); } }, 1000);
    }

    choose();
  }

  /* ------------------------------------------------------------ render */
  function render(root, message) {
    root.innerHTML = '';
    root.className = 'j365tp lx-root';
    const bg = h('div', { class: 'lx-bg', 'aria-hidden': 'true' }, h('i', { class: 'blob b1' }), h('i', { class: 'blob b2' }), h('i', { class: 'blob b3' }), h('div', { class: 'gridlines' }), h('div', { class: 'grain' }));
    const navBar = nav();
    const page = h('div', { class: 'lx' }, navBar,
      h('main', null, hero(message), steps(), bento(), trust(),
        h('section', { class: 'lx-section narrow', id: 'faq' }, h('div', { class: 'faq-grid' }, h('div', { class: 'faq-side' }, adminCard(), creatorCard()), faq()))),
      footer());
    root.append(bg, page);
    spotlight(page);
    reveal(page);
    // Only one sign-in button on screen: the header button appears only while neither the hero
    // nor the footer sign-in button is visible.
    const ctas = [...page.querySelectorAll('.hero-cta, .foot-cta')];
    if (ctas.length && 'IntersectionObserver' in window) {
      const seen = new Map(ctas.map((el) => [el, true]));
      const io = new IntersectionObserver((entries) => {
        entries.forEach((e) => seen.set(e.target, e.isIntersecting));
        navBar.classList.toggle('show-cta', ![...seen.values()].some(Boolean));
      }, { rootMargin: '-64px 0px 0px 0px' });
      ctas.forEach((el) => io.observe(el));
    } else navBar.classList.add('show-cta');
    // Scroll cue: visible on the first screen, hidden as soon as the visitor scrolls.
    const onScroll = () => root.classList.toggle('scrolled', window.scrollY > 40);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    page.querySelectorAll('[data-count]').forEach((el) => {
      const go = () => countUp(el, Number(el.dataset.count), 1400);
      if (RM || !('IntersectionObserver' in window)) { go(); return; }
      const ob = new IntersectionObserver((en) => { if (en[0].isIntersecting) { go(); ob.disconnect(); } });
      ob.observe(el);
    });
    if (location.hash && location.hash.length > 1 && location.hash !== '#setup') {
      const target = page.querySelector(location.hash);
      if (target) requestAnimationFrame(() => target.scrollIntoView());
    }
    if (CFG.setup && CFG.setup.can && (CFG.setup.autoOpen || location.hash === '#setup')) setTimeout(openSetup, 400);
    if (PER) {
      const q = new URLSearchParams(location.search);
      const tid = GUID_RE.test(q.get('t') || '') ? q.get('t') : '';
      const dom = /^[a-z0-9.-]{3,253}$/i.test(q.get('d') || '') ? q.get('d') : '';
      if (q.get('connect') === '1') setTimeout(() => openConnect({ tenantId: tid, domain: dom }), 400);
      else if (q.get('signin') === '1') setTimeout(() => openSignIn({ email: q.get('login_hint') || '' }), 400);
    }
  }

  window.J365Landing = { render, openSetup, creatorChip, openSignIn: PER ? openSignIn : null, openConnect: PER ? openConnect : null };
})();
