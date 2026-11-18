/* Tenant Jornada365 — public landing + in-portal app registration (operator). */
(function () {
  'use strict';
  const J = window.J365;
  if (!J) return;
  const { CFG, t, h, raw, svg, logo, cloudSvg, MS_LOGO, wpApi, copyText, toast, dialog, themeToggle, langSwitch } = J;
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
    { icon: 'warning', title: 'Risco', text: 'Usuários e detecções de risco do Identity Protection.', tag: 'P1 / P2' },
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
  const SOURCE = 'https://github.com/sesantanajr/jornada365-tenant-reports';
  const BRAND = CFG.brand || APP;
  let entryFocus;
  let headerObserver;

  /* ------------------------------------------------------------ pieces */
  function signInButton(size = '') {
    const navigates = !PER && CFG.configured && !CFG.setup?.forced;
    const props = navigates ? { href: CFG.loginUrl } : { type: 'button', onclick: () => {
      if (PER) openSignIn();
      else if (CFG.setup?.can) openSetup();
      else notConfigured();
    } };
    return h(navigates ? 'a' : 'button', { ...props, class: 'ms-btn ' + size, 'aria-label': loginLabel() },
      h('span', { class: 'ms-mark', html: MS_LOGO }), h('span', { class: 'ms-label' }, raw(loginLabel())), ic('arrow', 'ico arrow'));
  }

  function avatar(cls = 'av') {
    return CREATOR.photo ? h('img', { class: cls, src: CREATOR.photo, alt: CREATOR.name, width: 96, height: 96, decoding: 'async' }) : h('span', { class: cls + ' av-fallback', 'aria-hidden': 'true' }, raw('SJ'));
  }

  /** "Criado por …" chip linking to the creator's LinkedIn. */
  function creatorChip(cls = '') {
    return h('a', { class: 'creator-chip ' + cls, href: CREATOR.url, target: '_blank', rel: 'noopener', title: 'LinkedIn' },
      avatar('av'), h('span', null, h('small', null, 'Criado por'), h('b', null, raw(CREATOR.name))), ic('ext', 'ico ext'));
  }

  function nav() {
    const links = h('nav', { class: 'nav-links', 'aria-label': 'Seções' },
      h('a', { href: '#como-funciona' }, 'Como funciona'), h('a', { href: '#relatorios' }, 'Relatórios'), h('a', { href: '#seguranca' }, 'Segurança'), h('a', { href: '#faq' }, 'Perguntas'));
    const mark = CFG.logo?.light || BRAND === APP ? logo('nav-logo') : h('b', { class: 'entry-brand-name' }, raw(BRAND));
    return h('header', { class: 'lx-nav' },
      h('a', { class: 'brand', href: '#top', 'aria-label': BRAND }, mark),
      links, h('div', { class: 'nav-tools' }, langSwitch(), themeToggle(), h('div', { class: 'nav-cta' }, signInButton('sm'))));
  }

  function hero(message) {
    const title = t('Relatórios do Microsoft 365').replace('Microsoft 365', 'Microsoft\u00a0365');
    const entry = h('section', { class: 'entry-card hero-cta', id: 'entrada', 'aria-labelledby': 'entry-title' },
      h('div', { class: 'entry-heading' }, h('span', { class: 'entry-lock', 'aria-hidden': 'true' }, ic('lock')), h('h2', { id: 'entry-title' }, 'Acessar o portal')));
    if (PER) {
      const body = h('div', { class: 'si' });
      entry.append(body);
      entryFocus = signInForm(body);
    } else {
      entry.append(h('p', { class: 'lead-sm' }, CFG.configured ? 'Continue com a conta corporativa da sua organização.' :
        CFG.setup?.can ? 'Configure o aplicativo desta instalação para liberar o acesso ao portal.' : 'Portal em configuração. O login estará disponível em breve.'),
        signInButton('lg'));
    }
    return h('section', { class: 'lx-hero', id: 'top', 'aria-labelledby': 'public-title' },
      h('div', { class: 'hero-copy' },
        h('p', { class: 'entry-brand' }, raw(BRAND)),
        h('h1', { id: 'public-title' }, raw(title)),
        h('p', { class: 'lead' }, 'Consulte identidades, licenças e dispositivos com dados do Microsoft Graph. Exporte para Excel ou CSV.'),
        message ? h('div', { class: 'entry-message note warn', role: 'alert' }, message) : null),
      entry, h('div', { class: 'entry-links' }, h('a', { href: '#relatorios' }, 'Explorar relatórios', ic('down')),
        h('a', { href: SOURCE, target: '_blank', rel: 'noopener' }, 'Código aberto no GitHub', ic('ext'))));
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
    const card = (n, icn, title, text, extra, d) => h('article', { class: 'step spot', style: `--d:${d}` },
      h('div', { class: 'step-top' }, h('span', { class: 'step-ic' }, fi(icn)), h('span', { class: 'step-n' }, raw(String(n).padStart(2, '0')))),
      h('h3', null, title), h('p', null, text), extra);
    return h('section', { class: 'lx-section', id: 'como-funciona' },
      h('div', { class: 'sec-head' }, h('span', { class: 'kicker' }, 'Como funciona'), h('h2', null, 'Do login ao relatório em três passos'), h('p', null, 'Sem scripts, sem cadastro manual de aplicativo, sem senhas guardadas.')),
      h('div', { class: 'steps-track' }, h('i', { class: 'track-line' }),
        COMPANY ? card(1, 'contact_card', 'Entre com sua conta corporativa', 'Continue com a conta corporativa da sua organização.', signin, 1)
          : PER ? card(1, 'contact_card', 'Informe o e-mail corporativo', 'O portal encontra o seu tenant e abre o login da Microsoft com o aplicativo do próprio tenant.', signin, 1)
          : card(1, 'contact_card', 'Entre com sua conta corporativa', 'Use o botão Entrar com Microsoft 365. O login pede apenas openid, profile e email.', signin, 1),
        PER ? card(2, 'shield_checkmark', 'Conecte o seu tenant (uma vez)', 'Um administrador registra o aplicativo no diretório da empresa e aprova as permissões dos módulos habilitados.', consent, 2)
          : card(2, 'shield_checkmark', 'Aprove o Tenant Jornada365', 'Na primeira vez, o Global Administrator vê a tela de permissões da Microsoft e clica em Aceitar. O aplicativo empresarial é criado no seu tenant.', consent, 2),
        PER ? card(3, 'data_pie', 'Relatórios prontos', 'Os relatórios são consultados com a sua própria conta e entregues direto ao seu navegador, prontos para filtrar e exportar.', report, 3)
          : card(3, 'data_pie', 'Relatórios prontos', 'Os relatórios são gerados pelo aplicativo aprovado e entregues direto ao seu navegador, prontos para filtrar e exportar.', report, 3)));
  }

  function bento() {
    const tiles = REPORTS.map((r, i) => h('article', { class: 'tile spot', style: `--d:${(i % 8) + 1}`, title: t(r.tag) },
      h('span', { class: 'tile-ic' }, fi(r.icon)),
      h('div', { class: 'tile-txt' }, h('h3', null, r.title), h('p', null, r.text))));
    return h('section', { class: 'lx-section', id: 'relatorios' },
      h('div', { class: 'sec-head' }, h('span', { class: 'kicker' }, 'Relatórios'), h('h2', null, 'Catálogo de relatórios'), h('p', null, 'Filtros rápidos, detalhes por item, ações para resolver na hora e exportação em Excel com resumo executivo e gráficos.')),
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
      ['document_lock', 'Relatórios e ações', 'Os relatórios consultam dados. Quando habilitadas, as ações exigem permissões adicionais e uma conta autorizada para executar a operação.'],
      PER ? ['certificate', 'Login pela Microsoft', 'A Microsoft aplica as políticas de acesso da empresa. O navegador envia os tokens ao portal nas chamadas autenticadas.']
        : ['certificate', 'Login pela Microsoft', 'O portal usa a credencial de aplicativo configurada pelo operador para consultar relatórios. Você entra pela Microsoft.'],
      ['building', 'Isolado por tenant', 'Cada sessão é amarrada ao seu tenant, com chave própria, e verificada a cada chamada.'],
      ['shield_checkmark', 'Zero Trust contínuo', 'O portal revalida periodicamente sua função e verifica a autorização antes das ações administrativas.'],
      PER ? ['dismiss_circle', 'Seu tenant, seu aplicativo', 'O administrador gerencia o aplicativo no Microsoft Entra ID. Para bloquear o vínculo no portal, solicite a revogação ao operador.']
        : ['dismiss_circle', 'Revogue quando quiser', 'O administrador gerencia o aplicativo no Microsoft Entra ID. Para bloquear o vínculo no portal, solicite a revogação ao operador.'],
    ];
    return h('section', { class: 'lx-trust', id: 'seguranca' },
      h('div', { class: 'trust-in' },
        h('div', { class: 'sec-head left' }, h('span', { class: 'kicker' }, 'Zero Trust'), h('h2', null, 'Segurança por padrão'), h('p', null, 'Confira os dados que o portal processa e as permissões dos módulos habilitados.')),
        h('div', { class: 'facts' }, ...facts.map(([i, title, text], n) => h('div', { class: 'fact spot', style: `--d:${n + 1}` }, h('span', { class: 'fact-ic' }, fi(i)), h('div', null, h('h3', null, title), h('p', null, text))))),
        h('details', { class: 'perm-box' }, h('summary', null, ic('lock'), h('span', null, 'Ver permissões dos módulos e fluxo de dados')), h('div', { class: 'perm-body' }, permissionsPanel(),
          h('ol', { class: 'flow' },
            PER ? h('li', null, 'Você informa o e-mail e entra na Microsoft com o aplicativo do seu próprio tenant.') : h('li', null, 'Você entra com a conta corporativa (openid, profile, email).'),
            h('li', null, 'O servidor confirma no seu tenant que você tem uma função de administrador ou leitor.'),
            PER ? h('li', null, 'Os relatórios são consultados no Microsoft Graph com a sua conta (permissões delegadas).') : h('li', null, 'Os relatórios são consultados no Microsoft Graph com o aplicativo aprovado pelo seu administrador.'),
            h('li', null, 'Depois de receber o resultado, você pode consultá-lo na aba ou exportá-lo para um arquivo.'))))));
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
    return h('div', { class: 'admin-card spot' },
      PER ? h('div', { class: 'admin-copy' }, h('span', { class: 'kicker' }, 'Não é administrador?'), h('h2', null, 'Envie o link de conexão para o seu admin'),
        h('p', null, 'Um administrador (Global, Cloud Application ou Application Administrator) abre o link e conecta o tenant em 1 minuto. Depois disso, você entra normalmente.'))
        : h('div', { class: 'admin-copy' }, h('span', { class: 'kicker' }, 'Não é administrador?'), h('h2', null, 'Envie o link de aprovação para o seu admin'),
        h('p', null, 'Um Global Administrator ou Privileged Role Administrator abre o link, revisa as permissões na tela da Microsoft e aprova. Depois disso, você entra normalmente.')),
      h('div', { class: 'admin-form' }, h('label', null, h('span', null, 'Domínio do tenant'), input), out, btn));
  }

  function faq() {
    const storageAnswer = t('O WordPress guarda o vínculo do tenant e as configurações. A auditoria pseudonimizada segue a retenção definida pelo operador. Durante a geração, o portal guarda o progresso e o resultado cifrados. Ele remove o resultado ao entregá-lo ao navegador e limpa trabalhos inativos periodicamente.') + ' ' + t('O histórico opcional do Identity Score mantém até 24 fechamentos mensais por tenant.');
    const qa = PER ? [
      ['Preciso instalar alguma coisa?', 'Não. Um administrador conecta o tenant pelo portal e o aplicativo Tenant Jornada365 é criado dentro do próprio tenant, sem script.'],
      ['Quem pode conectar?', 'Global Administrator, Cloud Application Administrator ou Application Administrator do seu tenant, uma única vez.'],
      ['Depende do tenant de outra empresa?', 'O aplicativo usado para entrar pertence ao diretório da sua empresa. O WordPress mantém o vínculo entre o tenant e esse aplicativo.'],
      ['Quais licenças são necessárias?', 'Atividade de login e Acesso Condicional exigem Entra ID P1; risco exige P2; dispositivos e aplicativos exigem Intune. O portal avisa quando algo não estiver disponível.'],
      ['O que fica salvo no servidor?', storageAnswer],
      ['Como removo o acesso?', 'O administrador gerencia o aplicativo no Microsoft Entra ID. Para bloquear o vínculo no portal, solicite a revogação ao operador.'],
    ] : [
      ['Preciso instalar alguma coisa?', 'Não. O administrador aprova o Tenant Jornada365 na tela da Microsoft e o aplicativo empresarial é criado automaticamente no tenant.'],
      ['Quem pode aprovar?', 'Um Global Administrator ou Privileged Role Administrator do seu tenant, uma única vez.'],
      ['Quais licenças são necessárias?', 'Atividade de login e Acesso Condicional exigem Entra ID P1; risco exige P2; dispositivos e aplicativos exigem Intune. O portal avisa quando algo não estiver disponível.'],
      ['O que fica salvo no servidor?', storageAnswer],
      ['Como removo o acesso?', 'O administrador gerencia o aplicativo no Microsoft Entra ID. Para bloquear o vínculo no portal, solicite a revogação ao operador.'],
    ];
    return h('div', { class: 'faq-wrap' },
      h('div', { class: 'sec-head' }, h('span', { class: 'kicker' }, 'Perguntas'), h('h2', null, 'Perguntas frequentes')),
      h('div', { class: 'faq' }, ...qa.map(([q, a], i) => h('details', { style: `--d:${i + 1}` }, h('summary', null, q), h('p', null, a)))));
  }

  function creatorCard() {
    return h('aside', { class: 'creator-card spot' },
      h('div', { class: 'creator-photo' }, avatar('av-lg')),
      h('div', { class: 'creator-copy' },
        h('span', { class: 'kicker' }, 'Sobre o projeto'),
        h('h3', null, raw(CREATOR.name)),
        h('p', null, 'Projeto de código aberto criado por Sérgio Sant\'Ana Júnior, da Jornada 365, para administrar e analisar o próprio tenant do Microsoft 365.'),
        h('a', { class: 'btn-solid', href: CREATOR.url, target: '_blank', rel: 'noopener' }, 'Conectar no LinkedIn', ic('ext'))));
  }

  function footer() {
    return h('footer', { class: 'lx-foot' },
      h('div', { class: 'foot-cta' }, h('h2', null, 'Pronto para ver o seu tenant?'), signInButton('lg'), h('span', { class: 'foot-note' }, ic('cloudoff'), 'Relatórios na sua aba.')),
      h('div', { class: 'foot-row' },
        h('div', { class: 'foot-brand' }, logo('foot-logo'), h('span', { class: 'tagline' }, raw(BRAND))),
        h('div', { class: 'foot-links' },
          h('a', { href: CREATOR.url, target: '_blank', rel: 'noopener' }, 'Criado por', ' ', raw(CREATOR.name)),
          h('a', { href: SOURCE, target: '_blank', rel: 'noopener' }, 'Código-fonte (GPL)'),
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
      h('div', { class: 'setup-app' }, CFG.iconUrl ? h('img', { src: CFG.iconUrl, alt: '' }) : svg(cloudSvg('jsetup')), h('div', null, h('b', null, raw(APP)), h('small', null, 'Aplicativo multi-tenant com certificado'))),
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

  function openSignIn(opts = {}) { entryFocus?.(opts); }

  function signInForm(body) {
    const mem = AUTH.memory();
    const email = h('input', { type: 'email', class: 'j365-input', name: 'email', autocomplete: 'username', inputmode: 'email', autocapitalize: 'none', spellcheck: 'false', required: true, 'aria-describedby': 'entry-feedback' });
    email.value = mem.email || '';
    const go = h('button', { class: 'btn-solid si-go', type: 'submit' },
      h('span', { class: 'ms-mark', html: MS_LOGO }), h('span', null, raw(COMPANY?.label || t('Continuar com a Microsoft'))));
    const note = h('p', { class: 'si-note', id: 'entry-feedback', role: 'status', 'aria-live': 'polite' });
    const form = h('form', { class: 'si-form', novalidate: true }, COMPANY ? null : h('label', { class: 'si-label' }, 'E-mail corporativo', email), go);
    const focusEntry = (opts = {}) => {
      if (!form.isConnected) { body.querySelector('button')?.focus(); return; }
      if (!COMPANY && opts.email) email.value = opts.email;
      (COMPANY ? go : email).focus();
    };
    const show = () => {
      go.disabled = false; email.readOnly = false;
      email.removeAttribute('aria-invalid');
      note.className = 'si-note'; note.textContent = '';
      body.replaceChildren(h('p', { class: 'lead-sm' }, COMPANY ? 'Continue com a conta corporativa da sua organização.' : 'Informe seu e-mail de trabalho para localizar a sua organização.'),
        form, note, h('p', { class: 'fine si-fine' }, ic('shield'), 'Sua senha é solicitada apenas na página da Microsoft.'));
    };
    function showUnconnected(r) {
      const roles = CFG.spa?.connectRoles || [];
      const copy = h('button', { class: 'btn-ghost', type: 'button', onclick: () => copyText(connectLink(r.tenantId), copy) }, ic('copy'), 'Copiar link para o administrador');
      const heading = h('strong', { tabindex: '-1' }, t('O tenant {s} ainda não está conectado ao portal.', { s: r.domain || r.tenantId }));
      const previous = mem.tenants?.[r.tenantId]?.clientId;
      body.replaceChildren(h('div', { class: 'nc' },
        h('div', { class: 'nc-head' }, h('span', { class: 'nc-ic' }, ic('shield')), h('div', null, heading,
          h('p', null, 'Um administrador precisa conectar esta organização ao portal. Você pode enviar o link abaixo.'))),
        h('div', { class: 'nc-actions' }, copy, h('button', { class: 'btn-ghost', type: 'button', onclick: () => openConnect({ tenantId: r.tenantId, domain: r.domain, loginHint: r.loginHint }) }, 'Conectar como administrador')),
        previous ? h('button', { class: 'linkish', type: 'button', onclick: () => { show(); void submit({ ...r, ok: true, connected: true, clientId: previous }); } }, 'Entrar com o aplicativo já usado neste navegador') : null,
        roles.length ? h('div', { class: 'role-chips' }, h('span', { class: 'muted small' }, 'Quem pode conectar:'), ...roles.map(x => h('span', { class: 'role-chip' }, raw(x)))) : null,
        h('button', { class: 'linkish', type: 'button', onclick: () => { show(); focusEntry(); } }, COMPANY ? 'Voltar' : 'Usar outro e-mail')));
      heading.focus();
    }
    async function submit(known) {
      if (go.disabled) return;
      go.disabled = true; email.readOnly = true;
      note.className = 'si-note'; note.textContent = t(COMPANY ? 'Preparando…' : 'Procurando o seu tenant…');
      try {
        const r = known || await AUTH.post('auth/resolve', { q: COMPANY ? COMPANY.tenantId : email.value.trim() });
        if (!body.isConnected) return;
        if (!r.ok) throw new Error(t(r.message || 'Não foi possível consultar a Microsoft agora. Tente novamente.'));
        if (!r.connected) { showUnconnected(r); return; }
        note.textContent = t('Abrindo a Microsoft…');
        AUTH.remember(r.tenantId, r.clientId, r.loginHint);
        await AUTH.signIn({ tenantId: r.tenantId, clientId: r.clientId, loginHint: r.loginHint });
      } catch (e) {
        if (!body.isConnected) return;
        email.readOnly = false; go.disabled = false;
        note.className = 'si-note danger';
        note.textContent = e instanceof TypeError ? t('Não foi possível falar com o portal. Tente novamente.') : e.message;
      }
    }
    form.addEventListener('submit', e => {
      e.preventDefault();
      if (!COMPANY && (!email.value.trim() || !email.validity.valid)) {
        email.setAttribute('aria-invalid', 'true');
        note.className = 'si-note danger'; note.textContent = t('Informe um e-mail corporativo válido.');
        email.focus(); return;
      }
      email.removeAttribute('aria-invalid');
      void submit();
    });
    email.addEventListener('input', () => {
      email.removeAttribute('aria-invalid');
      if (note.classList.contains('danger')) { note.textContent = ''; note.className = 'si-note'; }
    });
    show();
    return focusEntry;
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
        : 'Entre com um administrador do tenant para criar o aplicativo Tenant Jornada365 e revisar as permissões dos módulos habilitados.'));

    function choose() {
      ++gen;
      body.innerHTML = '';
      body.append(intro(),
        h('ul', { class: 'cx-list' },
          h('li', null, ic('check'), h('span', null, 'Aplicativo de página única com o endereço de retorno deste portal — sem client secret e sem certificado.')),
          h('li', null, ic('check'), h('span', null, 'O acesso depende dos escopos aprovados, da função da conta e das políticas do tenant.')),
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
    headerObserver?.disconnect();
    entryFocus = null;
    const navBar = nav();
    const page = h('div', { class: 'lx' }, h('a', { class: 'entry-skip', href: '#main-public' }, 'Pular para o conteúdo'), navBar,
      h('main', { id: 'main-public', tabindex: '-1' }, hero(message), steps(), bento(), trust(),
        h('section', { class: 'lx-section narrow', id: 'faq' }, h('div', { class: 'faq-grid' }, h('div', { class: 'faq-side' }, adminCard(), creatorCard()), faq()))), footer());
    root.append(page);
    if ('IntersectionObserver' in window) {
      const ctas = [...page.querySelectorAll('.hero-cta, .foot-cta')];
      const seen = new Set(ctas);
      headerObserver = new IntersectionObserver(entries => {
        for (const e of entries) { if (e.isIntersecting) seen.add(e.target); else seen.delete(e.target); }
        navBar.classList.toggle('show-cta', !seen.size);
      }, { rootMargin: '-64px 0px 0px 0px' });
      ctas.forEach(el => headerObserver.observe(el));
    } else navBar.classList.add('show-cta');
    const target = document.getElementById(location.hash.slice(1));
    if (target && page.contains(target)) requestAnimationFrame(() => target.scrollIntoView());
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
