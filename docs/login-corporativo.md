# Configure o login corporativo

Uma empresa pode instalar o Tenant Jornada365 no próprio WordPress e usar seu diretório Microsoft Entra. O login corporativo é opcional. A configuração padrão continua usando o e-mail para localizar o tenant de cada organização.

1. No painel do WordPress, abra **Tenant Jornada365 → Configurações**. Selecione a **Página do portal** do próprio site e confirme que ela abre por HTTPS.
2. Em **Login corporativo**, informe o **ID do diretório (tenant)** da empresa, disponível na visão geral do Microsoft Entra ID. Se quiser, ajuste o **Texto do botão** para identificar o login da empresa. [Onde encontrar o ID do tenant no Microsoft Entra](https://learn.microsoft.com/pt-br/entra/fundamentals/how-to-find-tenant)
3. Salve e abra o **Link de conexão** exibido em **Configurações**. Conecte o diretório com uma conta autorizada pelo assistente. O fluxo aceita o aplicativo de página única criado pelo assistente ou um aplicativo já registrado nesse mesmo diretório, configurado com as permissões delegadas e o **Retorno do login (SPA)** exibido pelo plugin. Use exatamente aquele endereço no registro do aplicativo. [Fluxo OAuth da Microsoft com PKCE](https://learn.microsoft.com/pt-br/entra/identity-platform/v2-oauth2-auth-code-flow)
4. Divulgue o **Link para o login corporativo** na intranet. Ele abre a entrada para o diretório configurado e dispensa a pesquisa de tenant por e-mail. As funções do Entra e as permissões delegadas dos módulos continuam aplicadas.
5. Valide em homologação com uma conta que tenha uma função autorizada no diretório e com uma conta de outro diretório. A política é aplicada ao ID do tenant verificado na autenticação, às sessões existentes e às etapas de conexão do tenant. [Claims do ID token no Microsoft Entra](https://learn.microsoft.com/pt-br/entra/identity-platform/id-token-claims-reference)

Para voltar ao login por e-mail, remova o ID em **Login corporativo** e salve. A lista **Restringir a tenants**, quando preenchida, volta a reger o acesso. Registros dos tenants mantêm suas configurações ao alternar o modo.
