# Desempenho e implantação

O Tenant Jornada365 é um portal de código aberto para relatórios de Microsoft 365 no WordPress. O inventário e os relatórios do Intune usam etapas curtas, compatíveis com hospedagem compartilhada. [Portal gratuito](https://tenant.jornada365.cloud/)

## Consultas ao Intune na versão 1.4

- **Apps descobertos** agrupa o inventário a cada página. A consulta adicional ao catálogo de aplicativos publicados ocorre quando a homologação automática pelo Intune está ativa.
- **Apps gerenciados** combina o catálogo e o relatório agregado de falhas. Abra um aplicativo para consultar os dispositivos afetados e o código informado pelo Intune. Uma contagem ausente aparece como indisponível.
- **Atribuições do catálogo** consulta os aplicativos em lotes. Use os filtros de intenção para localizar instalações obrigatórias, instalações opcionais e desinstalações. A lista distingue grupos incluídos, excluídos e alvos que abrangem todo o tenant.
- **Dispositivos** carrega os detalhes de política quando você seleciona um dispositivo com problema de conformidade. Assim, abrir o inventário dispensa uma consulta de políticas para cada dispositivo.

Na validação local com 2.400 versões distribuídas por 100 aplicativos fictícios, as linhas brutas da coleta anterior ocupavam 443.001 bytes em JSON. O estado completo da coleta agrupada ocupou 198.627 bytes, uma redução de 55,2%. Com a homologação automática pelo Intune desabilitada, a consulta ao catálogo foi dispensada. O tempo em um tenant real depende do volume de dados, do tempo de resposta do Graph e dos intervalos de espera indicados pela Microsoft. [Relatórios do Intune](https://learn.microsoft.com/intune/device-management/reports/ref-graph-available-reports) · [Limites do Microsoft Graph](https://learn.microsoft.com/graph/throttling)

## O que causava a espera

- O tempo anunciado para uma etapa do relatório podia ser menor que o tempo de uma chamada ou de uma espera por limite do Microsoft Graph.
- Algumas consultas complementares percorriam muitas páginas antes de devolver progresso, mesmo quando a parte principal do relatório já tinha avançado.
- O navegador podia consultar indefinidamente um trabalho em execução. Uma falha no carregamento inicial também podia deixar apenas o indicador de carregamento visível.

## Melhorias da versão 1.2.2

- Cada etapa de leitura tem um orçamento de 20 segundos no servidor. O estado acompanha a leitura em etapas e a próxima consulta respeita o tempo informado pelo Microsoft Graph quando há limite.
- A busca de registro MFA para o relatório de privilégios parte das contas encontradas no relatório. Isso evita percorrer todo o diretório para esse complemento.
- O navegador limita o tempo de cada chamada de relatório e a duração da espera. O painel informa falhas e permite cancelar a espera ou tentar novamente.
- A ordenação calcula uma chave por linha, e a tabela mantém sua rolagem ao ordenar. Em telas estreitas, pesquisa, exportação e paginação podem se reorganizar sem cortar a tabela.

## Acompanhamento em homologação

Abra um relatório com volume representativo de dados, por exemplo o de privilégios e MFA. Registre o tempo total, as solicitações que retornam HTTP 429 e se o progresso volta a avançar depois da espera indicada. Repita com a mesma seleção e o mesmo usuário para comparar alterações. Em uma tela estreita, valide a rolagem da tabela até a última coluna e os controles de pesquisa, filtro e exportação.

Se os relatórios demoram porque a Microsoft limita a taxa, registre o relatório afetado, a hora do teste, a quantidade aproximada de objetos e o código HTTP. Isso permite diferenciar limite remoto de interrupção na hospedagem. Remova tokens, nomes, identificadores de tenant e dados de relatórios ao compartilhar os registros. [Orientações de throttling do Microsoft Graph](https://learn.microsoft.com/en-us/graph/throttling)

A duração em uso real depende da hospedagem e do tenant Microsoft Entra. Para comparar duas versões, registre o tempo de geração com o mesmo relatório, a mesma conta e volumes de dados semelhantes.
