# Scheduled Identity Score collection

## English

Use this optional collector when the company needs a measurement even in months when nobody opens the WordPress portal. Run it weekly from a company-managed machine with Python 3.11+ and Azure CLI. Keep the resulting JSON on private persistent storage outside your web root and Git checkout. WordPress and the shared hosting provider need no additional service.

1. An Entra administrator grants the collector's own workload identity the **DirectoryRecommendations.Read.All** Microsoft Graph application permission. On an Azure host with a system-assigned managed identity, run `az login --identity --allow-no-subscriptions` as the account that will execute the scheduled job. A machine with workload identity federation can use its existing Azure CLI sign-in instead. [Graph permissions for this endpoint](https://learn.microsoft.com/en-us/graph/api/recommendation-tenantsecurescores?view=graph-rest-beta) · [Managed identity sign-in](https://learn.microsoft.com/en-us/cli/azure/authenticate-azure-cli-managed-identity)
2. Create a private directory for the archive. From the project checkout, run the command below, replacing the GUID and path with the company's values. Configure the scheduler to run the same command weekly with an absolute script path. Monitor its exit status. A nonzero exit leaves the existing archive intact.

```sh
python scripts/collect_identity_score.py --tenant TENANT_GUID --output /private/reports/identity-score.json
```

3. Open **Identity Score** for the same tenant. Under **Coverage by source**, expand the local history section and import the JSON. The portal merges those observations in the current tab. The collector keeps the latest genuine UTC measurement in each of the last 24 calendar months. Data gaps remain visible. Existing daily exports can be used as the initial input. Use a separate output file for each tenant.

The collector checks the tenant returned by Azure CLI, follows Graph pagination and replaces the archive only after a successful read. It runs against the Microsoft public cloud. A scheduled collection requires the company host to be online and able to reach Azure and Graph.

## Português do Brasil

Use o coletor opcional para acompanhar o score inclusive nos meses em que ninguém abre o WordPress. Ele pode rodar semanalmente em um computador da empresa, com Python 3.11 ou posterior e Azure CLI. Guarde o JSON em uma pasta privada persistente, fora do site e do repositório Git.

1. O administrador do Entra concede à identidade de carga de trabalho do coletor a permissão de aplicativo **DirectoryRecommendations.Read.All** no Microsoft Graph. Em um recurso Azure com identidade gerenciada atribuída pelo sistema, execute `az login --identity --allow-no-subscriptions` com a conta que rodará a tarefa. Um ambiente com federação de identidade pode usar sua sessão existente da Azure CLI. [Permissões do Microsoft Graph](https://learn.microsoft.com/pt-br/graph/api/recommendation-tenantsecurescores?view=graph-rest-beta) · [Login com identidade gerenciada](https://learn.microsoft.com/pt-br/cli/azure/authenticate-azure-cli-managed-identity)
2. Crie uma pasta privada e execute o comando abaixo a partir do repositório, substituindo o GUID e o caminho. No Agendador de Tarefas do Windows ou no cron, agende a execução semanal com caminhos absolutos. Acompanhe o código de saída da tarefa. Em caso de falha, o arquivo anterior é preservado.

```powershell
python scripts/collect_identity_score.py --tenant GUID_DO_TENANT --output "C:\RelatoriosPrivados\identity-score.json"
```

3. Em **Identity Score**, no mesmo tenant, em **Cobertura por origem**, expanda **Histórico em arquivo local** e importe o JSON. O portal combina as medições na aba atual. Para cada um dos últimos 24 meses, o arquivo mantém os pontos e a data da última medição real em UTC. Meses sem medição ficam em branco. Um JSON diário já exportado pelo portal pode servir de ponto de partida. Use um arquivo por empresa.

O coletor verifica o tenant da sessão, percorre as páginas do Graph e atualiza o arquivo após uma leitura bem-sucedida. A hospedagem compartilhada continua executando somente o plugin. A máquina agendada precisa estar ligada e ter acesso à nuvem pública da Microsoft.
