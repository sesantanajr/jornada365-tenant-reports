=== Tenant Jornada365 ===
Contributors: jornada365
Tags: microsoft 365, entra id, intune, microsoft graph, reports
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Portal open source de relatórios Microsoft 365, Entra ID e Intune. Cada organização conecta seu próprio tenant e pode hospedar o portal no WordPress.

== Description ==
O serviço da Jornada 365 está disponível gratuitamente para todos em [tenant.jornada365.cloud](https://tenant.jornada365.cloud/). Sua empresa também pode modificar o código aberto, personalizar a marca e o login e hospedar o portal no próprio WordPress.

Um administrador com função de registro e consentimento conecta a empresa pelo portal. O aplicativo de página única é registrado no diretório da empresa. Os relatórios usam as permissões delegadas de leitura da conta que entra. Os módulos de ação habilitados requerem permissões adicionais. O portal oferece relatórios de identidade, MFA, contas inativas, dispositivos (híbridos, cloud-only, obsoletos, suporte do SO, filtro por plataforma), aplicativos e versões (com homologação), Acesso Condicional, grupos, licenciamento & custo, colaboração externa (convidados), domínios, políticas de autenticação, acessos privilegiados (PIM), risco (Identity Protection), consentimentos/credenciais de apps, Secure Score, uso do M365, caixas de correio (cotas e arquivamento) e OneDrive/SharePoint (limites e Top N).

Soluções com a conta de quem clica (permissões delegadas, RBAC da Microsoft): ações remotas do Intune (sincronizar, reiniciar, verificação e atualização do Microsoft Defender), arquivamento de caixas (ativar ou desativar o arquivo morto, expansão automática e sincronização imediata) e exibição dos nomes nos relatórios de uso. Exportação Excel nativa com resumo executivo, gráficos e tabelas filtráveis, gerada no navegador. O WordPress guarda configurações, auditoria limitada e estado temporário dos relatórios em processamento.

== Installation ==
1. Baixe jornada365-tenant-portal.zip nos [lançamentos do projeto](https://github.com/sesantanajr/jornada365-tenant-reports/releases). No WordPress, abra Plugins → Adicionar plugin → Enviar plugin, selecione o ZIP e ative.
2. Nada a registrar no seu próprio tenant: em wp-admin → Tenant Jornada365 → Assistente, confira o retorno do login (SPA) em HTTPS e clique em "Testar conexão com a Microsoft".
3. O administrador de cada empresa abre o portal → "Entrar com Microsoft 365" → informa o e-mail → "Sou administrador: conectar o tenant". Com um Global Administrator, Cloud Application Administrator ou Application Administrator, o portal cria o app no tenant dele, aprova as permissões delegadas e entra (código da Microsoft, navegador ou criação manual).
4. Depois disso, quem tem uma função autorizada no tenant entra com o app do próprio tenant.

== External services ==
O login usa o Microsoft Entra ID. Ao entrar, a organização fornece sua identidade à Microsoft e aprova as permissões do aplicativo do seu diretório. Ao gerar um relatório, o WordPress envia a consulta ao Microsoft Graph com a credencial delegada do usuário. Os dados consultados dependem do relatório escolhido. As ações de Intune e Exchange enviam à Microsoft a operação e os recursos selecionados quando o usuário a confirma. O catálogo de nomes de licenças pode consultar periodicamente a lista pública da Microsoft. Consulte a [política de privacidade da Microsoft](https://privacy.microsoft.com/pt-br/privacystatement) e os [termos dos produtos](https://www.microsoft.com/licensing/terms/).

== Changelog ==
= 1.4.0 =
English

* Inspect Intune application assignments, failure counts and affected devices, and open policy details for a noncompliant device. Filter the device inventory by OS release and build.
* Identity Score shows the dated Microsoft Entra score and recommendations. This optional module requires DirectoryRecommendations.Read.All and uses Microsoft Graph beta.
* Filter the portal audit by date, actor and action, load earlier events and export the selection.
* Collapse or expand the navigation, reach the last columns of wide reports, and compare charts using consistent percentages.

Português do Brasil

* Consulte atribuições de aplicativos, falhas e dispositivos afetados no Intune. Abra os detalhes de um dispositivo não conforme e filtre o inventário por versão e build do sistema.
* O Identity Score reúne a pontuação por data e as recomendações do Microsoft Entra. O módulo opcional usa DirectoryRecommendations.Read.All e o Microsoft Graph beta.
* Filtre a auditoria do portal por data, ator e ação, carregue eventos anteriores e exporte a seleção.
* Recolha ou expanda a navegação, acesse as últimas colunas de relatórios largos e compare gráficos com percentuais consistentes.

= 1.3.0 =
English

* Resume, open or discard your own report jobs from My reports.
* Review application secret and certificate renewals in 7-, 30- and 90-day windows. Credentials without an expiration date are shown separately.
* Executive Excel workbooks include linked summaries and data sheets, a filterable findings and warnings register and native charts. CSV uses the selected locale.
* The GitHub README includes English and Brazilian Portuguese instructions, screenshots and example workbooks. The hosted portal is free and companies can customize and host the open-source plugin.

Português do Brasil

* Em Meus relatórios, retome consultas da sua sessão, abra resultados prontos ou descarte trabalhos de leitura.
* Revise vencimentos de segredos e certificados por aplicação e por objeto em janelas de 7, 30 e 90 dias. Credenciais sem data de validade têm uma categoria própria.
* O Excel executivo reúne as observações e falhas de coleta em uma aba filtrável, com links entre capa, resumo e detalhes. O CSV usa os separadores do idioma escolhido.
* Documentação em inglês e português brasileiro, com capturas da mesma tela nos dois idiomas e instruções para usar o serviço gratuito ou personalizar uma instalação própria.

= 1.2.3 =
* Recupera falhas temporárias ao iniciar um relatório e retoma leituras paginadas do Graph a partir do progresso salvo.
* Mostra quando os dados foram gerados, preserva a tabela em caso de falha na atualização e mantém o botão de limpar filtros visível no celular.
* Permite ordenar pelo teclado e informa a direção da ordenação aos leitores de tela.
* Usa os salts do WordPress em hospedagens que os mantêm no banco. Nessas instalações, pode ser necessário entrar novamente após atualizar.
* A distribuição inclui um instalador do portal publicado nos lançamentos do GitHub.

= 1.2.2 =
* Relatórios com chamadas de leitura limitadas por etapa, retomada após limites da Microsoft e consultas complementares mais seletivas.
* Tempo de espera e recuperação de erro na interface, com cancelamento da espera e melhorias para tabelas em telas menores.
* Login corporativo opcional, associado ao diretório Microsoft Entra da empresa, com texto de botão e atalho de entrada ajustáveis.
* Página inicial identifica o projeto como código aberto e inclui o link para o repositório.

= 1.2.1 =
* Distribuição pública baseada no Tenant Jornada365 1.2.0, com login pelo aplicativo de cada tenant, relatórios, ações delegadas e Excel executivo.
* Verifica o emissor da chave de assinatura de tokens Entra ID.
* Evita dependências de branding do site de origem em novas instalações.
* Reforça o escape de texto semelhante a fórmulas em CSV, inclusive com prefixos Unicode e caracteres de controle.

= 1.2.0 =
* Novo modelo por tenant: o app "Tenant Jornada365" é criado dentro do tenant de cada cliente (single-tenant, aplicativo de página única, sem segredo e sem certificado). O portal não usa mais nenhum aplicativo no tenant da Jornada 365.
* Assistente "Conectar o tenant" no próprio portal: código da Microsoft (Microsoft Graph Command Line Tools, só Application.ReadWrite.All + DelegatedPermissionGrant.ReadWrite.All), entrada pelo navegador ou criação manual; cria o registro, o aplicativo empresarial e a aprovação delegada para a organização, espera a Microsoft publicar o app e entra.
* Login com código de autorização + PKCE resgatado no navegador; tokens só na aba (sessionStorage), enviados a cada chamada e conferidos no servidor (audiência, tenant, usuário, app, escopos, validade) — nunca armazenados. Renovação silenciosa quando o token expira, inclusive no meio de um relatório.
* Descoberta do tenant pelo e-mail (metadados OpenID públicos); contas pessoais e domínios sem tenant explicados na hora.
* Proteções: nonce do ID token amarrado a um cookie de uso único (também no servidor), app de outro diretório nunca é vinculado, troca do app registrado só por quem gerencia aplicativos no tenant.
* Aviso "Atualizar o aplicativo" quando uma versão ou módulo pede permissões novas; páginas de diagnóstico para tenant não conectado, app diferente, app de outro diretório e app excluído.
* wp-admin: Assistente do modelo por tenant (link de conexão, retorno SPA, escopos, tenants conectados, teste de conexão com a Microsoft) e "Esquecer o aplicativo anterior" (remove Client ID, certificado e secret do modelo antigo).

= 1.1.0 =
* Console executivo: cabeçalho com indicador de saúde em anel em cada relatório, gráficos de rosca e barras que filtram a tabela, indicadores com % do total, Visão geral por área e botão "Excel executivo" no topo.
* Contas inativas: quem nunca fez login só conta se a conta foi criada antes do período; faixa de inatividade, logins interativo/não interativo, dias desde a criação, licença paga, último sync do AD e ação recomendada por conta. Sem Entra ID P1/P2 nenhuma conta é marcada como inativa (o dado de login não existe) e o relatório explica o motivo.
* Exportação completa: todos os campos lidos pelo servidor vão para o Excel/CSV em todos os relatórios (inclusive "Dispositivos com o app", agora com conformidade, propriedade e tipo de registro legíveis e filtros).
* Excel com o logo da Jornada 365, capa do relatório executivo (indicadores por área, pontos de atenção, índice), destaques por gravidade, distribuições automáticas com gráficos e uma planilha por tabela.

= 1.0.0 =
Primeira versão de produção. Consolida as versões de pré-produção e acrescenta:
* Soluções, não só relatórios: Ações em dispositivos (sincronizar, reiniciar, verificação rápida/completa e atualização do Microsoft Defender) por plataforma, em 1 ou vários dispositivos, com elegibilidade conforme a documentação da Microsoft (reinício: Windows, macOS, iOS/iPadOS supervisionado e Android corporativo; Defender: Windows) e ações direto no detalhe do dispositivo.
* Plataformas sempre visíveis (Windows, macOS, iOS/iPadOS, Android, Linux) nos filtros de Dispositivos e de Ações; dispositivos do Intune sem ID do Entra (Android dedicado/AOSP) passam a aparecer; diagnóstico claro quando o Intune não devolve dispositivos (licença, permissão ou registro).
* Arquivamento: ativar ou desativar o arquivo morto (confirmação digitada, exigida também pelo servidor), expansão automática, sincronização imediata (Managed Folder Assistant), correção automática de arquivo desativado há mais de 30 dias, "Resolver agora" nas caixas lotadas e scripts PowerShell por operação.
* Nomes reais nos relatórios de uso: um clique (Global Administrator) altera a configuração de privacidade dos relatórios do tenant pela Microsoft Graph; reversível em Configurações → Privacidade dos relatórios.
* Excel nativo (sem CDN): resumo com indicadores e gráficos, tabela do Excel com filtros, cores por status e barras de dados; Relatório executivo com várias áreas em uma pasta de trabalho. CSP restrita ao próprio site.
* Interface refinada (painéis, indicadores, seletor de ações, estados vazios, banners) em claro/escuro e celular; PT-BR, ES e EN.
* Segurança: cada capacidade de ação pede só o próprio escopo delegado (Intune, Exchange, relatórios); nova permissão delegada ReportSettings.ReadWrite.All (aprovação única pelo administrador).
* Ações destrutivas ou irreversíveis (reiniciar, verificação completa, desativar arquivo, expansão automática) exigem no servidor a confirmação vinculada à seleção; a licença da expansão automática é conferida pelo servidor antes de cada caixa; o claim scp do token delegado é verificado ao autorizar; limites por execução (1.000 dispositivos, 200 caixas) recusados em vez de truncados.

== Upgrade Notice ==
= 1.2.0 =
Modelo por tenant: cada cliente conecta o próprio tenant e o app é criado no tenant dele. Envie o zip e escolha "Substituir a atual pela enviada". Depois: Assistente → "Esquecer o aplicativo anterior" e exclua o registro antigo no Entra ID do tenant da Jornada 365. Tenants que aprovaram o app antigo conectam de novo pelo portal (1 minuto).

= 1.1.0 =
Interface executiva e Excel com logo. Envie o zip e escolha "Substituir a atual pela enviada"; não há nova permissão.

= 1.0.0 =
Primeira versão de produção. Ao enviar sobre uma versão 2.x, escolha "Substituir a atual pela enviada"; depois, wp-admin → Tenant Jornada365 → Assistente → Atualizar o aplicativo (nova permissão delegada ReportSettings.ReadWrite.All).
