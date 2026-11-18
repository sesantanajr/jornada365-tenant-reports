# Análise da versão

## Base da evolução

Na análise inicial, o portal público apresentava **Tenant Jornada365 1.2.0** e carregava a aplicação da pasta `jornada365-tenant-portal`. O índice REST do WordPress anunciava o namespace `j365/v1`. Os arquivos públicos `core.js`, `auth.js` e `portal.js` tinham o mesmo SHA-256 que os arquivos do instalador 1.2.0. Essa correspondência permitiu identificar o código de origem da distribuição pública. [Portal Jornada365](https://tenant.jornada365.cloud/)

| Área | Implementação inspecionada |
| --- | --- |
| Login e conexão | Assistente por tenant, fluxo SPA com PKCE e aplicativo registrado no diretório do cliente |
| Relatórios | Graph para identidade, segurança, dispositivos, apps, uso e armazenamento. Trabalhos de relatório vinculados à sessão e ao tenant. |
| Ações | Intune e Exchange delegados. As verificações de função, escopo e confirmação fazem parte das rotas de ação do portal. |
| Exportações | Excel executivo em JavaScript no navegador. CSV com separadores e aspas escapados. |
| Administração WordPress | Assistente do portal, diagnóstico, marca e configuração. |

Foram preservadas as funcionalidades presentes nessa linha. A numeração maior de outros pacotes não representa uma atualização direta da mesma linha.

## Alterações da distribuição

- O portal 1.2.1 valida o emissor associado à chave de assinatura de cada ID token Entra, inclusive quando a Microsoft usa metadados comuns e chaves que variam por tenant. [Validação do emissor da chave](https://learn.microsoft.com/en-us/entra/identity-platform/access-tokens#validate-the-signing-key-issuer)
- Novas instalações usam configurações locais para marca e links administrativos. Os administradores podem configurar sua própria marca pelo painel.
- A exportação CSV protege também entradas iniciadas por quebras de linha, espaço seguido de operadores e variantes Unicode de símbolos de fórmula. Prefira Excel para compartilhar planilhas com campos controlados pelo tenant. Diferentes programas podem reinterpretar CSVs depois de editá-los e salvá-los. [Referência sobre fórmulas em CSV](https://community.owasp.org/attacks/CSV_Injection)

## Dados e permissões

Relatórios circulam entre a sessão, o WordPress e os serviços Microsoft. O portal armazena configurações do site e do tenant, informações de vínculo, operações de trabalho de curta duração e eventos de auditoria pseudonimizados. O Excel final é gerado no navegador. Revise a configuração de retenção e as políticas de cache da sua hospedagem antes de iniciar um piloto com dados reais.

Conceda permissões delegadas e funções Microsoft conforme os relatórios e operações que a organização deseja usar. O consentimento e os papéis do Microsoft Entra fazem parte da implantação de cada tenant.

## Validação

A suíte cobre assinatura e rotação de chaves Entra, escape de CSV e sintaxe PHP/JavaScript. A validação local usa uma instalação descartável de WordPress. O uso autenticado de Graph, Intune e Exchange deve ser validado com um tenant e recursos de homologação.
