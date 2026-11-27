# 0663 — Fotos e anexos no app do SAC

- Status: Aceito
- Data: 27/11/2026
- Pedido: Djalma, 26/11/2026 22:57: continuar melhorando a cada 30 minutos. Primeiro ciclo do app do SAC
  (continua as ADRs 0660 e 0661).

## Decisão

- **Resposta com fotos:** `POST /api/v1/sac/tickets/{id}/answers` aceita também `multipart/form-data` com
  `attachments[]` (campos `message`, `internal`, `resolve` como texto). Usa `TicketDesk::reply`, que já gravava
  anexos do ERP.
- **Novo chamado com fotos:** `POST /api/v1/sac/tickets` em multipart; as fotos entram na mesma transação do
  `open()` (anexo inválido = nenhum chamado criado), com `message_id` nulo, como o balão do site.
  `GET /sac/tickets/{id}` traz `opening_attachments`.
- **Até 5 arquivos por vez** (`TicketDesk::MAX_FILES`), conferido no servidor e no app.
- **Foto vira imagem tratada:** em `TicketDesk::attach`, o tipo vem do conteúdo (finfo). Imagem vai por
  `Upload::image` (reduzida a 1600 px, sem EXIF, ADR 0389); documento segue por `Upload::file`. Antes, foto
  mandada como anexo era recusada porque `Upload::file` só aceita documentos; isso vale também para o ERP.
- **Abrir anexo no celular:** baixa com o token no cabeçalho para o cache do app e abre a folha do sistema
  (ver, salvar, mandar). Acesso pelas regras de `TicketAttachmentAccess` (ADR 0339).
- **Resposta da equipe continua da equipe:** em `TicketService::conversation`, no chamado de origem `helpdesk`
  aberto pela equipe em nome de outra pessoa, quem registrou não conta como quem pediu. Sem isso, as respostas
  de quem abriu pelo app apareciam como do solicitante.

## Testes

- `SacDeskIntegrationTest::testPhotosOnReplyAndOnOpeningAndTeamRepliesStayFromTeam` e `testFlagReadsFormAndJson`.
- `apps/sac/__tests__/sac-api.test.ts`: campos do multipart, tipo e nome seguro do anexo.
