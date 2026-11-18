=== Jornada 365 · Bridge ===
Contributors: jornada365
Requires at least: 6.4
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Diagnóstico HTTPS do Tenant Jornada365 para hospedagem WordPress.

== Description ==

Em J365 Bridge, um administrador gera um token temporário, exibido uma única vez.
O WordPress armazena o hash e o horário de expiração. Cada site tem um token; a
geração de outro token substitui o anterior. O administrador também pode revogá-lo.

Use Authorization: Bearer <token> com HTTPS. As rotas de leitura são:

* GET /wp-json/j365-bridge/v1/ping: disponibilidade e versão da ponte.
* GET /wp-json/j365-bridge/v1/env: versão da ponte e estado/versão do plugin Tenant Jornada365.

Em configurações sem links permanentes, copie as URLs exatas exibidas no painel.
As rotas não retornam configurações do Microsoft Graph, identificadores de
tenants, tokens, caminhos de arquivos ou logs. O Bridge não é um servidor MCP.

== Installation ==

Instale e ative o ZIP em Plugins. A página J365 Bridge mostra o estado do Tenant
Jornada365 e links para a instalação nativa pelo WordPress. Administre a
configuração Microsoft no próprio Tenant Portal e no Microsoft Entra.

== Changelog ==

= 1.1.0 =
* Limita a API a /ping e /env, ambos com HTTPS e autenticação de diagnóstico.
* Substitui tokens de pareamento 1.0.0 por token exclusivo revogável de até 24h.
* Remove da API instalação/ativação remota, configuração, leitura de logs e
  operações de rewrite. Use o painel e as permissões nativas do WordPress.
* Remove o armazenamento temporário da credencial em texto simples.
* Scripts da versão anterior precisam de um novo token e do contrato de leitura.

= 1.0.0 =
* Versão inicial de Sérgio Sant'Ana Júnior – Jornada 365.
