# Desempenho e implantação

O Tenant Jornada365 é um portal de código aberto para relatórios de Microsoft 365 no WordPress. O inventário e os relatórios do Intune usam etapas curtas, compatíveis com hospedagem compartilhada. [Portal gratuito](https://tenant.jornada365.cloud/)

## Consultas ao Intune na versão 1.4

- **Apps descobertos** agrupa o inventário a cada página. A consulta adicional ao catálogo de aplicativos publicados ocorre quando a homologação automática pelo Intune está ativa.
- **Grupos** abre o inventário com até 999 grupos por página do Graph. Para consultar os membros diretos e os proprietários de um grupo, selecione sua linha e escolha **Consultar contagens deste grupo**. Para filtrar ou exportar as contagens de todos os grupos, escolha **Contar membros e proprietários de todos os grupos**. Essa consulta pode levar mais tempo em diretórios grandes. Um travessão indica contagem indisponível. Os grupos sincronizados e os gerenciados pelo Exchange têm restrições de leitura de proprietários no Graph. O serviço também documenta limitações para listar entidades de serviço em membros e proprietários na v1.0. [Inventário de grupos](https://learn.microsoft.com/graph/api/group-list?view=graph-rest-1.0) · [Membros diretos](https://learn.microsoft.com/graph/api/group-list-members?view=graph-rest-1.0) · [Proprietários](https://learn.microsoft.com/graph/api/group-list-owners?view=graph-rest-1.0)
- **Usuários** salva o cursor e as linhas já lidas a cada duas páginas ou antes, se o tempo da etapa terminar. Se a conexão continuar falhando após as tentativas automáticas, uma consulta com progresso pode aparecer como **Pausado** em **Meus relatórios**. Escolha **Retomar relatório** na mesma sessão enquanto ele estiver disponível. A retomada respeita o intervalo que o Graph indicou.
- **Apps gerenciados** combina o catálogo e o relatório agregado de falhas. Abra um aplicativo para consultar os dispositivos afetados e o código informado pelo Intune. Uma contagem ausente aparece como indisponível.
- **Atribuições do catálogo** combina plataforma, aplicativo, intenção, tipo de destino, destino e cobertura. Por exemplo, escolha *iOS/iPadOS*, *Obrigatória* e *Grupo incluído* para exportar somente as atribuições correspondentes. O tipo de destino diferencia os grupos excluídos e os destinos de todo o tenant. Se o diretório não permite ler o nome do grupo, o relatório conserva o ID. A cobertura distingue aplicativos sem atribuições de consultas incompletas.
- **Plataforma do catálogo** vem do tipo de aplicativo informado pelo Intune. Aplicativos web genéricos aparecem como *Web*. Um tipo sem plataforma identificável aparece como *Indeterminado*. O relatório busca atribuições em lotes de até 20 aplicativos, acompanha as páginas adicionais e retoma as leituras temporariamente limitadas. [Tipo mobileApp no Graph](https://learn.microsoft.com/graph/api/resources/intune-apps-mobileapp?view=graph-rest-1.0) · [Lotes JSON](https://learn.microsoft.com/graph/json-batching)
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

Em tabelas com pelo menos 2.000 linhas, a busca prepara um índice em memória quando o leitor entra no campo. O navegador trabalha em pequenos intervalos e reaproveita os resultados quando a palavra é refinada. O índice pertence à tabela da aba e é descartado ao atualizar seus dados. Isso acelera a busca local depois que o relatório chega do Graph.

Quando o Graph informa uma espera, o portal exibe quantas páginas já recebeu e o horário previsto para retomar. O próximo pedido respeita o intervalo indicado pelo serviço. Uma etapa que avançou a paginação pode continuar imediatamente, mesmo quando o contador de linhas exibido ainda é o mesmo.

Abra um relatório com volume representativo de dados, por exemplo o de privilégios e MFA. Registre o tempo total, as solicitações que retornam HTTP 429 e se o progresso volta a avançar depois da espera indicada. Repita com a mesma seleção e o mesmo usuário para comparar alterações. Em uma tela estreita, valide a rolagem da tabela até a última coluna e os controles de pesquisa, filtro e exportação.

Se os relatórios demoram porque a Microsoft limita a taxa, registre o relatório afetado, a hora do teste, a quantidade aproximada de objetos e o código HTTP. Isso permite diferenciar limite remoto de interrupção na hospedagem. Remova tokens, nomes, identificadores de tenant e dados de relatórios ao compartilhar os registros. [Orientações de throttling do Microsoft Graph](https://learn.microsoft.com/en-us/graph/throttling)

A duração em uso real depende da hospedagem e do tenant Microsoft Entra. Para comparar duas versões, registre o tempo de geração com o mesmo relatório, a mesma conta e volumes de dados semelhantes.
