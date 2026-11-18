# 0395 — Logo SVG do Studio volta a abrir, com sandbox

- Status: Aceito
- Data: 18/11/2026
- Contexto: conferência dos envios de arquivo depois do ADR 0388 (rodada do plano v1.0).

## Contexto
O ADR 0388 passou a barrar (403) qualquer `.svg` dentro de `storage/`, porque um SVG aberto direto no navegador pode
rodar script. Mas o Studio aceita um logo SVG em Configurações (`safeSvgUpload`) e o grava em
`storage/images/AAAA/MM/logo-svg-AAAAMMDDhhmmss-xxxxxx.svg`; `site_logo_url()` aponta para esse endereço. Desde o ADR 0388,
quem enviasse um logo SVG ficaria com a imagem quebrada no site, no ERP e nos e-mails.

## Decisão
- O `.htaccess` abre uma exceção só para o nome exato que o Studio gera, na pasta `storage/images/AAAA/MM/`. Qualquer
  outro SVG em `storage/` continua 403.
- Esse arquivo sai com `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox` e `nosniff`:
  se alguém abrir o endereço direto, nenhum script roda. Dentro de `<img>` o navegador já não roda script de SVG.
- O filtro do Studio (sem `script`, `foreignObject`, `iframe`, `on...=`, `javascript:`) continua.

## Consequências
- Conferido num Apache 2.4 real em 18/11/2026: o logo do Studio abre (200, `image/svg+xml`, com a CSP de sandbox);
  `logo.svg` e `logo-svg-1.svg` na mesma pasta dão 403.
- `HtaccessExposureTest` confere a exceção e falha se o Studio mudar o formato do nome ou se o sandbox sumir.
- Na hospedagem (LiteSpeed), `<FilesMatch>` com `Header` é suportado; se o `mod_headers` faltar, o logo ainda abre e
  o filtro do Studio segue como defesa.
