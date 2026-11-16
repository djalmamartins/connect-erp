# 0308 — Integração com o Connect Talk (WhatsApp): token de serviço, pessoa pelo telefone e chamado do Help Desk

- **Status:** aceito
- **Data:** 2026-11-15
- **Relacionados:** ADR-002 (administradora única), 0034 (todo chamado no Help Desk), 0303 (token Bearer do app do
  morador), 0307 (atendimento do chamado); issue #400; contrato `docs/api-erp.md` do repositório `djalmamartins/talk`
  (ADR 0003 do Talk: hospedagens separadas, só API REST).
- **Numeração:** faixa avulsa 0300–0319.

## Contexto

Pedido do Djalma (15/11/2026): "integrar o Talk ao ERP, tudo integrado para pegar o nome das pessoas, e abrir o
chamado direto no Help Desk". O Connect Talk é o atendimento por WhatsApp da Connect, hospedado fora do ERP. Ele já
chama `ERP_API_URL` com `ERP_API_TOKEN`; faltava o lado do ERP. A API `/api/v1` só aceitava sessão + CSRF (navegador)
ou o token do app do morador, e os chamados só aceitavam solicitante com e-mail.

## Decisão

- **Token de serviço, sem usuário paralelo.** Tabela `erp_integration_tokens` (cliente `talk`): o token
  (`ctk_` + 64 hexadecimais) pertence a um usuário comum de `users`, o "usuário de integração". Valem as permissões
  (`support.manage`) e o escopo de condomínios (`CondominiumScope`: todos com `erp.condominiums.all`, ou os liberados
  um a um) desse usuário. Só o sha256 fica no banco; o token aparece uma vez, no terminal
  (`php tools/talk-token.php criar --usuario=...`). Não vence; `revogar` derruba na hora, e ele também para de valer se
  o usuário sair de `confirmed` ou perder `support.manage`.
- **Rotas `/api/v1/talk/*` só com esse token** (middleware `TalkApiAuthenticated`): sem sessão, cookie ou CSRF, e a
  sessão do navegador não vale nelas. Falha de token conta no limite por IP (20 em 15 minutos → 429, tabela
  `auth_throttle_events`). Lista opcional de IPs em `TALK_ALLOWED_IPS`. O usuário do token fica só na requisição
  (`Auth::actingAs`), então a auditoria registra quem fez.
- **`GET /talk/people?phones=`**: procura em `erp_residents` (vínculo ativo, vigente e não apagado), só nos condomínios
  do escopo. O servidor gera as variantes com e sem 55 e com e sem o nono dígito. Devolve até 10 cadastros com nome,
  vínculo, e-mail, condomínio e unidade; sem CPF e sem financeiro (LGPD). A consulta vai para o log com o telefone
  mascarado.
- **`POST /talk/tickets`**: nova origem `talk` no `TicketService` (ENUM + `ORIGINS`, rótulo "WhatsApp (Connect Talk)").
  A conversa vira chamado pelo mesmo serviço de todos os outros e cai na fila do Help Desk. Idempotente pelo
  protocolo do Talk em `external_ref` (índice único com a origem): repetir devolve 200 com o mesmo chamado, e dois
  POST simultâneos não duplicam. Com `resident_id`, o ERP confere escopo, condomínio, unidade e telefone (8 últimos
  dígitos) e usa o nome do cadastro. Sem morador, abre como contato não identificado. Na origem `talk`, nome +
  telefone bastam (a conversa não traz e-mail) e o chamado não grava e-mail, para o Help Desk não mandar e-mail
  paralelo ao WhatsApp. O link da conversa no Talk vai no texto do chamado. Devolve o protocolo `CH...`, que é o
  número que o cliente vê.
- **`POST /talk/tickets/{id}/transfer`**: muda a área (`general`, `technical`, `financial`), grava o evento "Área" e uma
  nota interna com o setor e a nota do atendente do Talk.
- **`POST /talk/tickets/{id}/close`**: resolve pelo `TicketDesk::reply` (ADR 0307) com a resolução como resposta.
  Repetir num chamado resolvido ou fechado não muda nada.
- O Talk só mexe em chamado de origem `talk` dentro do escopo: os outros são 404.

## Fora desta decisão (PENDENTE)

- `GET /talk/charges` (2ª via de boleto pelo WhatsApp, seção 6 do contrato): fica para depois, porque ainda falta
  decidir se inquilino vê boleto e como entregar o PDF (link assinado de validade curta).
- Ligar o chamado à conta do morador (`requester_id`) para ele aparecer também no app do morador: não feito agora,
  para não disparar avisos e e-mails do Help Desk para quem está sendo atendido pelo WhatsApp.
- Card do Talk em Configurações › Integrações (situação do token e último uso).

## Consequências

- Migration `20261115_1100_talk_integration` (com `.down.sql`): origem `talk`, `external_ref`, `erp_integration_tokens`.
- OpenAPI: tag `Talk`, esquema de segurança `talkIntegration`, `TalkTicket`.
- Central de Ajuda: artigo "Chamados que chegam pelo WhatsApp" e a menção no artigo de Chamados.
- Testes: `tests/legacy/Unit/TalkIntegrationTest.php` (regras puras, rotas, OpenAPI, migration) e
  `tests/legacy/Integration/TalkIntegrationTest.php` (token, middleware, limite, escopo, idempotência, transferência,
  encerramento).
