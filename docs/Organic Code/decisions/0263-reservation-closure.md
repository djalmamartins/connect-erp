<!-- markdownlint-disable MD024 -->
# 0263 — Cancelamento em lote das reservas de uma área comum interditada

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0221 (baixa das reservas pendentes vencidas, mesmo desenho);
  - 0212 (cobrança das taxas de reserva);
  - 0229 (bloqueio e liberação em lote das áreas comuns);
  - 0237 (baixa da lista de espera);
  - cadastro de reservas (AP-113), só como cadastro reusado.

## Contexto

Quando a piscina entra em reforma, o salão de festas é pintado ou a quadra fica fechada por um período, as reservas
futuras da área precisam ser canceladas, tanto as aguardando aprovação quanto as confirmadas. Hoje cada uma é cancelada
à mão no formulário. Uma esquecida vira o morador chegando com os convidados na área fechada.

Os lotes existentes não cobrem isso:

- a baixa das pendentes (0221) só trata as que já passaram;
- o bloqueio das áreas (0229) só impede reservas novas.

Busquei `erp_reservations` em `source/`, no README das ADRs e nos handoffs 02xx. Os lotes existentes cobram a taxa
(0212), baixam as pendentes vencidas (0221) e chamam a lista de espera. Nenhum cancela as reservas futuras de uma área.

## Decisão

Rota nova: `POST /api/v1/reservations/cancel-for-closure {condominium_id, common_area_id, from, to, reservation_ids?,
reason, dry_run?}`, antes do laço dos cadastros (`/reservations/{id}`). O service é `ReservationClosure`, em
`Source\Services\Erp`.

### Reuso

- **Gravação:** cada reserva é regravada pelo `RegistryService::save` do cadastro "reservations", só com a situação
  "Cancelada", como na baixa das pendentes (0221). Valem as regras e a auditoria update do formulário.
- **Sem aviso:** o aviso da outbox (ADR 0056) é só da aprovação, então cancelar não envia nada. O aviso aos moradores fica
  com o comunicado.

### Quais entram

- **Área:** precisa ser do condomínio, estar ativa e aceitar reservas. O formulário não regrava reserva de área inativa
  ou bloqueada (`RegistryService::reservationRules`), então é preciso cancelar antes de inativar ou bloquear a área.
- **Período:** `from` a `to`, de hoje em diante e com até 366 dias. As que já passaram seguem pela baixa das pendentes.
- **Reservas:** as aguardando aprovação ou confirmadas da área no período, ou só as dos `reservation_ids` informados.

### Regra pura `problem()`

Recusa a reserva:

- não encontrada no condomínio ou de outra área;
- já cancelada;
- fora do período;
- com a taxa já cobrada, isto é, com cobrança extra ativa da reserva (ADR 0212): cancele a cobrança antes;
- de unidade inativa: o formulário não regrava.

Sem ids, as cobradas ficam de fora e a contagem sai em `skipped_billed`; as de unidade inativa, em
`skipped_inactive_unit`. Com ids, uma recusada desfaz todas (`_form` e `_reservation`).

### Execução

- **`waitlist_to_review`:** conta as inscrições aguardando na lista de espera da área nas datas do período. Elas não são
  tocadas.
- **`confirmed`:** quantas das canceladas estavam confirmadas.
- **Tudo ou nada:** uma transação com as reservas travadas (FOR UPDATE); `dry_run` passa por tudo e desfaz.
- **Auditoria:** a update do formulário e um registro `closure_cancel` por reserva (situação anterior, data, área, período
  e motivo), só quando grava. O motivo é obrigatório.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.reservations.view` e `erp.reservations.manage`, as do cadastro. Sem uma delas,
  403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, área inativa ou bloqueada, ou reserva recusada, 422, e nada
  é gravado.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, data, situação anterior e taxa. Não saem morador, unidade ou observação. Nenhum aviso é enviado.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI, e da correção de
  número abaixo.

### Correção no ADR 0260

O ADR 0260, o `WaitlistDemand` e o seu handoff citavam o ADR 0229 para a baixa da lista de espera. O certo é o 0237; o
0229 é o bloqueio das áreas. Este patch corrige as três referências. É só texto: nenhuma regra muda.

## Consequências

- **`Unit\ReservationClosureTest`:**
  - a regra pura;
  - a validação (período, área inativa, bloqueada ou de outro condomínio, motivo, ids) e o escopo;
  - as recusas antes do formulário, uma por caso;
  - as contagens das que ficam de fora e a lista de espera a rever;
  - o contrato de reuso, o controller e a rota.
- **`Integration\ReservationClosureTest`** (MariaDB, banco `*_test`):
  - a prévia e a gravação pelo cadastro, pendentes e confirmadas;
  - a fora do período e a de outra área ficam como estão;
  - a taxa preservada;
  - as auditorias `closure_cancel` e update;
  - nenhum aviso e nenhum morador na resposta;
  - tudo ou nada com ids e o escopo.
- **Sem tela nova neste patch.** A tela de reservas pode ganhar o botão "Interditar a área"; quando ganhar, vem o artigo
  da Central de Ajuda.
