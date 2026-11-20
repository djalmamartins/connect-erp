# ADR 0368 — Extensões do PHP no composer.json e no deploy-check

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0325 (hospedagem), ADR 0354 (deploy-check), ADR 0362 (limites do PHP)

## Contexto

O `composer.json` só declarava `ext-pdo` e `ext-intl`, e o `deploy-check` conferia sete extensões. O código usa, sem
alternativa: `curl` (Inter, Cora e conferência por HTTP), `dom` (limpeza do HTML do Studio), `simplexml` (NF-e e
planilhas), `gd` (fotos de vistoria e do perfil, imagens do livro-caixa) e `zip` (planilhas .xlsx, lista e livro em
pacote). Na hospedagem, com uma delas desligada no hPanel, o `composer install` e o `deploy-check` passavam e o erro só
aparecia quando alguém usasse a tela.

## Decisão

- `composer.json` exige `ext-curl`, `ext-dom`, `ext-fileinfo`, `ext-gd`, `ext-mbstring`, `ext-openssl`, `ext-pdo_mysql`,
  `ext-simplexml` e `ext-zip` (além de `ext-pdo` e `ext-intl`). O `composer install` para com a lista do que falta, e
  `composer check-platform-reqs` confere sem instalar. `composer.lock` atualizado só no hash e na plataforma
  (`composer update --lock`, nenhum pacote mudou).
- `deploy-check` confere as mesmas extensões.
- CI (`quality.yml`) pede as extensões ao `setup-php`. O laboratório (imagem `php:8.2-apache` + gd, intl, mbstring,
  pdo_mysql, zip) já tem todas.
- `sodium` (só o Talk dentro do ERP, pausado pelo ADR 0359) e `exif` (opcional, com `function_exists`) ficam de fora.

## Testes

`DeployExtensionsTest`: toda `ext-*` do `composer.json` está no `deploy-check`; as cinco usadas sem alternativa estão no
`composer.json` e os usos ainda existem; a plataforma do `composer.lock` bate com o `composer.json`.
