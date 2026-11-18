# Como contribuir

Para comunicar um erro, abra uma issue informando as versões do plugin, do WordPress e do PHP, o que você fez, o resultado esperado e o que apareceu na tela. Em falhas de relatório, inclua o nome do relatório, a etapa em que parou, a duração aproximada e o código HTTP, caso esteja visível. Use exemplos fictícios nas capturas de tela e nos arquivos anexos. Para questões de segurança, siga o [canal de relato privado](SECURITY.md).

## Preparar e testar uma alteração

1. Crie uma branch em seu fork. O plugin fica em `wordpress/jornada365-tenant-portal/` e os testes ficam em `tests/`.
2. Use PHP 8.1 ou superior, Node.js e Python 3 no ambiente de desenvolvimento. A instalação do usuário final usa o ZIP diretamente no painel do WordPress.
3. Rode as suítes e gere o instalador a partir da raiz do repositório. Estes comandos usam Bash.

```bash
set -e
for suite in tests/*.php; do php "$suite"; done
for suite in tests/*.cjs; do node "$suite"; done
python3 scripts/package.py
```

4. Instale o ZIP de `dist/` em um WordPress de teste. Para mudanças de interface, verifique o relatório em tela pequena e em tela grande. Para mudanças no Microsoft Graph, simule na suíte a resposta que provocava o erro e valide com dados de homologação quando disponíveis.
5. No pull request, explique o problema observado, a mudança e os testes realizados. Inclua uma captura com dados de exemplo se houver mudança visual.

O código do projeto é distribuído sob a GPL-2.0-or-later. Ao incluir recursos de terceiros, preserve os créditos e a licença correspondente. [Licença do projeto](LICENSE)

## Publicar uma versão

O mantenedor atualiza o cabeçalho `Version`, a constante `J365TP_VERSION`, o campo `Stable tag` e o changelog do `readme.txt`. Quando essa alteração chega à branch padrão, o GitHub Actions executa os testes, gera o instalador e publica a tag `vX.Y.Z` e o arquivo `jornada365-tenant-portal.zip` em Releases. O texto da versão vem do changelog.

Para retomar uma publicação, use **Actions → CI → Run workflow** na branch padrão. O fluxo preserva as versões já publicadas. Um fork pode usar o mesmo processo habilitando o GitHub Actions e permitindo a criação de releases no próprio repositório.
