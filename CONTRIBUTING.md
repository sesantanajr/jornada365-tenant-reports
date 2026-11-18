# Contributing

To report an issue, include the WordPress, PHP and plugin versions, the report name, expected result and what happened. Use fictional data in examples and screenshots. Report security issues through the private channel described in the [security policy](SECURITY.md).

Work in a branch of your fork. The plugin source is in `wordpress/jornada365-tenant-portal/`. To build an installable archive, run `python scripts/package.py` at the repository root. Install the ZIP from `dist/` on a staging WordPress site. In your pull request, describe the change and how you verified it. Preserve third-party notices when adding assets. [Project license](LICENSE)

To publish a new version, update the plugin version header, the runtime version constant, the WordPress readme's stable tag and its changelog. On the default branch, GitHub Actions checks the archive and publishes the versioned release.

## Português do Brasil

### Como contribuir

Para comunicar um erro, abra uma issue informando as versões do plugin, do WordPress e do PHP, o que você fez, o resultado esperado e o que apareceu na tela. Em falhas de relatório, inclua o nome do relatório, a etapa em que parou, a duração aproximada e o código HTTP, caso esteja visível. Use exemplos fictícios nas capturas de tela e nos arquivos anexos. Para questões de segurança, siga o [canal de relato privado](SECURITY.md).

## Preparar uma alteração

1. Crie uma branch em seu fork. O plugin fica em `wordpress/jornada365-tenant-portal/`.
2. Use WordPress 6.4 ou superior e PHP 8.1 ou superior no ambiente de desenvolvimento. Para empacotar a alteração, execute o comando abaixo na raiz do repositório com Python 3.

```bash
python3 scripts/package.py
```

3. Instale o ZIP de `dist/` pelo painel do WordPress em um ambiente de homologação. Para mudanças de interface, verifique o relatório em tela pequena e em tela grande. Para mudanças no Microsoft Graph, confira o resultado com dados conhecidos de um tenant de homologação.
4. No pull request, explique o problema observado, a mudança e como você verificou o resultado. Inclua uma captura com dados de exemplo se houver mudança visual.

O código do projeto é distribuído sob a GPL-2.0-or-later. Ao incluir recursos de terceiros, preserve os créditos e a licença correspondente. [Licença do projeto](LICENSE)

## Publicar uma versão

O mantenedor atualiza o cabeçalho `Version`, a constante `J365TP_VERSION`, o campo `Stable tag` e o changelog do `readme.txt`. Quando essa alteração chega à branch padrão, o GitHub Actions confere a versão do plugin, verifica a integridade do ZIP e publica a tag `vX.Y.Z` e o arquivo `jornada365-tenant-portal.zip` em Releases. O texto da versão vem do changelog.

Para retomar uma publicação, use **Actions → CI → Run workflow** na branch padrão. O fluxo preserva as versões já publicadas. Um fork pode usar o mesmo processo habilitando o GitHub Actions e permitindo a criação de releases no próprio repositório.
