# Device experience score

## English

Open **Devices & apps → Device experience score** to see how Intune rates the Windows endpoint experience on a scale of 0–100. The overview shows the tenant score, the categories Intune measured and available comparison baselines. The difference is expressed in points. [Microsoft's guide to scores and baselines](https://learn.microsoft.com/intune/endpoint-analytics/scores)

Open the device scores to inspect individual machines, filter by health and export the filtered list. Devices can have insufficient data for a measurement. In Excel, an unavailable score has an empty cell and a measured zero has a numeric zero.

The report uses the Microsoft Graph v1.0 read permission `DeviceManagementManagedDevices.Read.All`. Device eligibility, Intune licensing, data collection and the signed-in account's roles affect coverage. [Intune prerequisites](https://learn.microsoft.com/intune/endpoint-analytics/index) · [Configure Endpoint Analytics](https://learn.microsoft.com/intune/endpoint-analytics/configure)

![Device experience score, category scores and comparison baselines for a fictional tenant.](assets/device-score-en.webp)

## Português do Brasil

Em **Dispositivos & apps → Score dos dispositivos**, o portal exibe a experiência Windows medida pelo **Microsoft Intune Endpoint Analytics**. A pontuação oficial do tenant e as categorias variam de 0 a 100 pontos. Valores maiores indicam melhor experiência. A conformidade com políticas é consultada separadamente em **Dispositivos**. [Como a Microsoft calcula e compara pontuações](https://learn.microsoft.com/intune/endpoint-analytics/scores)

O resumo mostra as categorias que o Intune retornou. A mediana é a referência interna identificada por `isBuiltIn`. Referências personalizadas aparecem na mesma tabela. A diferença corresponde ao score atual do tenant menos a pontuação da referência, em pontos. A data das referências personalizadas indica a criação daquela referência. [Resumo do tenant](https://learn.microsoft.com/graph/api/intune-devices-userexperienceanalyticsoverview-get?view=graph-rest-1.0) · [Referências do Intune](https://learn.microsoft.com/graph/api/intune-devices-userexperienceanalyticsbaseline-list?view=graph-rest-1.0)

Use **Consultar pontuações por dispositivo** para buscar nome, fabricante, modelo, estado e pontuações de experiência, inicialização, aplicativos, trabalho remoto e bateria. A lista carrega sob demanda, é paginada e pode ter cobertura diferente do inventário. A seleção deste relatório no Excel executivo inclui a lista de dispositivos. As pontuações e diferenças são células numéricas. [Pontuações por dispositivo](https://learn.microsoft.com/graph/api/intune-devices-userexperienceanalyticsdevicescores-list?view=graph-rest-1.0)

## Acesso e cobertura

![Score dos dispositivos, categorias medidas e referências de comparação em um tenant fictício.](assets/device-score-pt-br.webp)

As três consultas usam o Microsoft Graph v1.0 e a permissão de leitura `DeviceManagementManagedDevices.Read.All`, já usada pelo inventário. Ela se aplica ao modelo delegado e ao de aplicativo. No modelo delegado, a Microsoft documenta o papel **Reports Reader** do Entra e **Read Only Operator** do Intune, entre outras funções. O tenant precisa de uma licença Intune ativa, dispositivos Windows elegíveis e coleta do Endpoint Analytics configurada. A Microsoft exige pelo menos cinco dispositivos reportando para uma pontuação significativa. [Pré-requisitos e funções](https://learn.microsoft.com/intune/endpoint-analytics/index) · [Configurar a coleta](https://learn.microsoft.com/intune/endpoint-analytics/configure)

Um traço indica uma pontuação ausente, fora da escala ou acompanhada por um estado desconhecido ou de dados insuficientes. Um zero retornado com estado avaliável continua sendo zero. Uma resposta parcial conserva os registros já recebidos e identifica a seção afetada. Em caso de HTTP 403, confira consentimento, funções e acesso ao Intune com o administrador.

Os relatórios de desempenho de recursos e integridade da bateria fazem parte do Intune Advanced Analytics. A disponibilidade dessas categorias depende dos recursos habilitados e dos dados dos dispositivos. [Intune Advanced Analytics](https://learn.microsoft.com/intune/advanced-analytics/)
