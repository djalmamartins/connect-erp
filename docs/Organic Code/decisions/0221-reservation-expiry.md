<!-- markdownlint-disable MD024 -->
# 0221 — Baixa em lote das reservas que passaram sem aprovação

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0051 (reservas de áreas comuns);
  - 0056 (aviso da aprovação pela outbox);
  - 0212 (cobrança das taxas das confirmadas);
  - 0217 a 0219 (mesmo padrão: tudo ou nada, prévia e auditoria).

## Contexto

O coordenador sugeriu, como ação depois da conferência das leituras (ADR 0220), cobrar o consumo em lote. Essa ação já
existe: o `MeterReadings::bill` gera, numa transação, uma conta a receber por leitura ainda não cobrada da competência.
Por isso esta ADR fica com a outra ação sugerida.

A reserva de área comum com aprovação nasce "Aguardando aprovação" e aparece:

- em Agendamentos Pendentes;
- no painel operacional;
- no resumo do síndico.

Fica lá até alguém aprovar ou cancelar. Quando a data passa sem decisão, a reserva fica pendente para sempre e polui
essas listas. Hoje, cada uma é cancelada à mão no formulário.

## Decisão

Rota nova: `POST /api/v1/reservations/expire-pending` com `{condominium_id, before?, reservation_ids?, reason,
dry_run?}`. O service é `ReservationExpiry`. A rota fica antes do laço dos cadastros, para não colidir com
`/reservations/{id}`.

### Reuso

- **`RegistryService::save` do cadastro `reservations`:** cada reserva é regravada só com a situação "Cancelada". Valem
  as regras e a auditoria `update` do formulário.
- **Sem aviso:** o aviso da outbox (ADR 0056) é só da aprovação (aguardando → confirmada), então cancelar não envia
  nada.
- **Reserva confirmada não é tocada:** a taxa dela continua na ADR 0212.

### Quais reservas entram

- **Sem `reservation_ids`:** todas as aguardando aprovação do condomínio com data antes de `before`. O padrão é hoje, e
  `before` nunca pode ser depois de hoje.
- **Com `reservation_ids`:** só as informadas. A regra pura `problem()` recusa a reserva:
  - não encontrada no condomínio;
  - que não está aguardando aprovação;
  - cuja data ainda não passou.

### Gravação

- **Tudo ou nada:** numa transação com as reservas travadas (`FOR UPDATE`), uma recusada desfaz todas. O 422 traz
  `_form` e `_reservation`, ou os erros do formulário.
- **Prévia:** `dry_run` passa pelo formulário e desfaz no fim.
- **Auditoria:** a `update` do formulário e um registro `expire` por reserva (data, área e motivo), só quando grava.
- **Motivo:** obrigatório, de 5 a 255 caracteres.
- **Limite:** até 500 reservas por vez.

### Acesso e dados

- **Escopo (ADR-002):** um condomínio do escopo; fora dele, 404.
- **Permissão:** exige `erp.access`, `erp.reservations.view` e `erp.reservations.manage`; sem uma delas, 403
  (fail-closed).
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, área, unidade, data e taxa. Nenhum nome de morador.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\ReservationExpiryTest`:**
  - a regra pura;
  - validação e escopo (404);
  - as recusas antes do formulário, sem transação aberta e sem gravar;
  - contrato de reuso, sem outbox e sem morador;
  - controller e rota.
- **`Integration\ReservationExpiryTest`** (MariaDB, banco `*_test`):
  - a prévia sem gravar e sem morador;
  - só as pendentes vencidas canceladas (a confirmada e a futura continuam);
  - a taxa preservada;
  - auditoria `expire`;
  - nada na outbox;
  - os ids escolhidos com uma confirmada desfazendo o lote;
  - escopo.
- **Sem tela nova neste patch.** Agendamentos Pendentes pode ganhar o botão "Baixar vencidas"; quando ganhar, vem o
  artigo da Central de Ajuda.
