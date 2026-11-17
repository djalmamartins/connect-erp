# ADR 0355 — Cabeçalhos de segurança em toda resposta

- Status: Aceito
- Data: 17/11/2026
- Relacionados: ADR 0337 (CSRF), ADR 0338 (pastas internas), ADR 0344 a 0347 (XSS), ROADMAP §3.7

## Contexto

Só algumas telas do ERP (impressões e downloads) mandavam `X-Frame-Options` ou `Content-Security-Policy`. O resto do
sistema (site, Central de Ajuda, login, ERP, Studio, app do morador, Talk e API) podia ser aberto escondido dentro de outro
site (clickjacking: o visitante clica no que não vê), o navegador podia "adivinhar" o tipo de um arquivo, o endereço
completo (com ids) ia para outros sites no Referer e não havia HSTS. A CSP completa (que limita scripts) estava marcada
para depois da hospedagem.

## Decisão

`Source\Support\SecurityHeaders::send()` no começo do `index.php`, antes do Talk e do roteador, em todos os hosts:

- `X-Content-Type-Options: nosniff`;
- `X-Frame-Options: SAMEORIGIN` e `Content-Security-Policy: frame-ancestors 'self'`;
- `Referrer-Policy: strict-origin-when-cross-origin`;
- `Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=(), usb=()` (a vistoria usa a câmera);
- `Strict-Transport-Security: max-age=31536000` fora do laboratório (`.lab` e localhost não recebem), sem
  `includeSubDomains` para não prender subdomínios ainda não usados. O navegador só guarda HSTS recebido por HTTPS.

As telas que já mandavam cabeçalhos próprios continuam valendo (o `header()` delas troca o padrão). O widget do Help Desk
(`/suporte/widget`), feito para abrir dentro de outros sites, já mandava `frame-ancestors *` e agora também remove o
`X-Frame-Options`.

A CSP que limita scripts e estilos fica para depois do piloto: as telas ainda têm scripts e estilos no próprio HTML, e
trocar isso sem medir em produção pode quebrar telas.

## Testes

- `SecurityHeadersTest`: os cabeçalhos, HSTS só fora do lab, chamada antes do Talk no `index.php`, widget liberado.
- Conferido com o servidor embutido do PHP: site, login do ERP e do Studio, Central de Ajuda, `/api/v1/health` e o host de
  produção (com HSTS) recebem os cabeçalhos; o widget sai só com `frame-ancestors *`.
