# ADR 0674 — Fotos da vistoria no app Meu Dia

- **Status:** Aceito
- **Data:** 27/11/2026

## Contexto
A vistoria pelo app (ADR 0663) envia fotos, mas só mostrava "2 fotos": o `url`
de cada foto é a rota da tela do ERP (`/operacional/vistorias/{id}/fotos/{photo}`),
que pede a sessão do navegador e não aceita o token do app (ADR 0660).

## Decisão
- Nova rota `GET /api/v1/inspections/{id}/photos/{photo}`, atrás do
  `ApiAuthenticated` (sessão ou token da equipe). Usa o mesmo
  `InspectionService::photoFile` da tela: permissão de ver vistorias e condomínio
  no escopo; fora disso, 404 (não revela se a foto existe).
- Cabeçalhos em `Inspections::photoHeaders`: só `image/jpeg|png|webp` (outro tipo
  vira `application/octet-stream`), `nosniff`, `Content-Security-Policy:
  default-src 'none'`, `Cache-Control: private` e ETag só se for um sha256 válido.
- Cada foto da resposta da vistoria ganha `api_path`. O app monta o mesmo caminho
  se o servidor ainda não mandar o campo.
- No app, miniaturas embaixo do item; tocar abre a foto na tela inteira. A imagem
  é pedida com o token no cabeçalho, não na URL.

## Consequências
- Nada muda na tela do ERP nem no banco; nenhuma migration.
- Testes: `InspectionApiPhotoHeadersTest` (cabeçalhos e rota protegida); escopo já
  coberto por `InspectionServiceTest` (404 para quem está fora); `npx jest` e
  `npx tsc --noEmit` no app.
