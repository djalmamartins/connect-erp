# 0343 — Imobiliária parceira não vê os chamados do condomínio

- Status: Aceito
- Data: 17/11/2026
- Pedido: `docs/ROADMAP_V1.md` §3.7; continuação dos ADRs 0340–0342; Djalma, 16/11/2026: "tome as melhores decisões"
- Relacionadas: ADR 0090 (imobiliárias parceiras, só leitura), ADR 0142 (conversa do chamado), ADR 0339 (anexos)

## Contexto

O papel `erp_partner` só tem `erp.access` e `erp.partner.view`, e a consulta da imobiliária é a tela própria, com e-mail,
telefone e documento mascarados (ADR 0090). O teste de escopo do ADR 0340/0341, rodado com uma imobiliária com acesso
concedido ao condomínio, mostrou que ela chegava a três rotas: `GET /tickets/{id}`, `GET /tickets/{id}/messages` e
`POST /tickets/{id}/messages`. Os chamados só exigem `erp.access` (todo usuário do ERP pode abrir chamado) e mostravam a quem
tem o condomínio no escopo todos os chamados dele, com nomes, conversa e anexos (ADR 0339 usa a mesma regra).

## Decisão

`TicketService::seesCondominium()`: quem tem o papel `erp_partner` vê só os chamados que abriu ou pediu, não os do
condomínio. Vale para a lista e para a busca por id (`listFor` e `findFor`), e com isso para a API, a tela de chamados do ERP,
a resposta e o download de anexos. A equipe continua como antes.

`ApiCrossCondominiumIntegrationTest::testPartnerAgencyReachesNoRecordEvenInGrantedCondominium` chama as 79 rotas com id dos
ADRs 0340 e 0341 como imobiliária com o condomínio concedido: nenhuma pode responder 2xx. E confere que o chamado que a
própria imobiliária abriu continua visível para ela.

## Fora desta fatia

`GET /ticket-service-times` (tempos médios de atendimento por condomínio) também só exige `erp.access`; devolve números
agregados, sem nome nem conversa. Fica registrado para a revisão das permissões com o Djalma.
