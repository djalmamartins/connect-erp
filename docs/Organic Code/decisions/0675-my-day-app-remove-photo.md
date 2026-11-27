# ADR 0675 — Remover foto da vistoria pelo app Meu Dia

- **Status:** Aceito
- **Data:** 27/11/2026

## Contexto
Com as fotos visíveis no app (ADR 0674), quem fazia a vistoria pelo celular via
a foto errada, mas só conseguia removê-la na tela do ERP
(`POST /operacional/vistorias/{id}/fotos/{photo}/excluir`, que pede sessão e CSRF).

## Decisão
- Nova rota `DELETE /api/v1/inspections/{id}/photos/{photo}`, atrás do
  `ApiAuthenticated` (sessão com CSRF, ou token da equipe). Ela chama o mesmo
  `InspectionService::deletePhoto` da tela:
  - permissão de fazer vistoria;
  - condomínio no escopo;
  - vistoria em andamento (concluída ou cancelada responde 409).
- O serviço apaga o arquivo e registra a remoção na auditoria. A resposta traz a
  vistoria atualizada.
- No app, a foto aberta na tela inteira tem o botão **Remover foto**, mas só com a
  vistoria em andamento. Antes de remover, o app pede confirmação com o aviso de
  que não dá para desfazer.

## Consequências
- Sem migration; a tela do ERP não muda.
- Testes:
  - `InspectionServiceTest` ganhou três casos para a remoção: quem só vê recebe
    403, uma foto inexistente dá 404 e uma vistoria cancelada dá 409.
  - `InspectionApiPhotoHeadersTest` confere que a rota está protegida.
  - No app, `npx jest` e `npx tsc --noEmit` passaram.
