# Desempenho e implantação

O [Tenant Jornada365](https://tenant.jornada365.cloud/) é uma solução open source para relatórios e operações delegadas de Microsoft 365 no WordPress. Este documento registra os ajustes da versão 1.2.2 e como verificar o comportamento em uma instalação própria.

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

A aplicação precisa de um tenant Microsoft Entra para medir a duração em uso real. Os testes da distribuição exercitam a lógica de relatórios e as respostas simuladas, conforme a suíte, e validam instalação e rotas em WordPress descartável.
