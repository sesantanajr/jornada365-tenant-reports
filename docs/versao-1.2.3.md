# Tenant Jornada365 1.2.3

## Quando o relatório falhava na primeira tentativa

Havia mais de uma causa. Uma interrupção entre o navegador e o WordPress podia esconder a resposta que identificava um relatório recém-criado. Em outros casos, a execução já existia, mas um erro temporário da Microsoft encerrava a leitura ou fazia o portal recomeçar páginas que já havia recebido.

| Situação | Comportamento da versão 1.2.3 |
| --- | --- |
| O WordPress inicia o relatório, mas a resposta se perde | O navegador envia novamente o identificador daquela solicitação. A mesma sessão recupera a execução já criada. |
| O Graph retorna 408, 429, 500, 502, 503 ou 504 durante a leitura | O portal aguarda e tenta novamente dentro de limites. Quando disponível, leva em conta o intervalo indicado pela Microsoft. |
| Um intermediário entrega HTML ou JSON incompleto no lugar dos dados | O portal trata a resposta como uma interrupção. Um CSV válido contendo apenas o cabeçalho continua representando um relatório com zero registros. |
| Uma consulta é interrompida após várias páginas | Acesso Condicional, Secure Score, Licenciamento, Políticas de Autenticação e Domínios preservam o progresso das etapas revisadas. |
| Uma credencial expira durante a leitura complementar de Intune, risco ou consentimentos | O relatório pede a renovação da credencial. Isso evita mostrar campos incompletos como se a leitura tivesse terminado. |

A recuperação do início de um relatório usa um identificador gerado pelo navegador em HTTPS. Ações administrativas, como reiniciar um dispositivo, têm um fluxo próprio. Erros definitivos de consentimento, licença ou função continuam exigindo intervenção de um administrador.

## Instalação e privacidade

Use o ZIP disponível nos lançamentos do GitHub e atualize pelo painel do WordPress. O WordPress mantém o estado cifrado de um relatório enquanto o processa. A coleta do resultado remove esse trabalho. A rotina de limpeza também recolhe trabalhos abandonados. Configurações, vínculos de tenant e registros de auditoria têm ciclos de retenção próprios.

Em hospedagens que delegam ao WordPress a geração dos salts no banco, a atualização passa a usar a chave de autenticação específica da instalação. Nesses ambientes, os usuários podem precisar entrar de novo. Instalações antigas que ainda usavam uma credencial cifrada pelo método anterior podem precisar reconfigurá-la. Instalações com chaves próprias configuradas mantêm a derivação existente.

## Validação

Em WordPress 7.1.4 com PHP 8.5.12 e SQLite, iniciamos o relatório de Domínios duas vezes com o mesmo identificador para simular a perda da resposta inicial. O WordPress criou um único trabalho. O serviço de teste entregou a primeira página e devolveu HTTP 502 na segunda. O portal respeitou a espera, buscou a segunda página novamente e entregou os dois domínios distintos. A primeira página foi consultada uma vez e o trabalho foi removido após a entrega.

No Chrome, testamos o relatório de Grupos em larguras de 320, 390 e 1440 pixels. Depois de uma busca sem correspondências, o botão **Limpar filtros** permanece visível e devolve o foco à busca. A ordenação pode ser operada pelo teclado. Se uma atualização falha, a tabela mantém os dados que o usuário já estava consultando.

Os tempos reais dependem da hospedagem, do volume de dados e das respostas do tenant Microsoft. [Guia de desempenho](desempenho.md)
