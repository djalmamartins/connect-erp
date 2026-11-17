# ADR 0365 — Telas internas fora da busca do Google

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0302 (app do morador em host próprio), ADR 0355 (cabeçalhos de segurança)

## Contexto

Em produção, `erp.`, `app.`, `studio.` e `talk.connectcondominios.com.br` respondem com telas de login e páginas
internas. Não havia `/robots.txt` nem `X-Robots-Tag`: um buscador que achasse um link (num e-mail encaminhado, num
documento público) indexaria a tela de login do ERP, do Studio ou do app, e endereços com ids. Só alguns links públicos
(convite do morador, prestação de contas pública) já mandavam `noindex`.

## Decisão

- `SecurityHeaders` (ADR 0355) manda `X-Robots-Tag: noindex, nofollow` em toda resposta dos hosts `erp.`, `app.`,
  `studio.` e `talk.`, e nos caminhos `/api/...`, `/app` e `/app/...` (app do morador no host do site) e `/ops/...`.
- `/robots.txt` na raiz (arquivo estático, servido para todos os hosts): `Disallow` em `/api/`, `/app/` e `/ops/`. O
  site institucional e a Central de Ajuda (`/suporte`) continuam indexados.
- Arquivos estáticos (CSS, imagens) não levam o cabeçalho; não há o que indexar neles.

## Testes

`SecurityHeadersTest`: hosts internos e caminhos privados com `noindex` (inclui maiúsculas no host); site, Central de
Ajuda, `/apple` e `/blog/application` sem; `robots.txt` com as linhas e sem `Disallow: /`.
