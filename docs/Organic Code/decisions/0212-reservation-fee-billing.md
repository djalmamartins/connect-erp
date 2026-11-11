<!-- markdownlint-disable MD024 -->
# 0212 — Cobrança das taxas de reserva em lote

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0051 (reservas, AP-113), 0057 (cobrança extra e multa avulsa, AP-027/028), 0080 (origem da
  multa), 0031/0100 (fechamento)

## Contexto

A reserva confirmada do salão de festas ou da churrasqueira tem taxa: `fee_cents`, a da área quando o formulário não
informa (`RegistryService::reservationRules`). Nada transforma essa taxa em conta a receber da unidade.

No fim do mês, a administradora abre o relatório de reservas e lança uma cobrança extra por reserva, à mão. Às vezes
esquece uma; às vezes cobra a mesma duas vezes, porque nada liga a cobrança à reserva. O vínculo `reservation_id`
existe na cobrança extra (ADR 0080), mas só a multa avulsa podia usá-lo.

## Decisão

Rota nova: `POST /api/v1/reservations/bill-fees` com `{condominium_id, from, to, due_on, chart_account_id, dry_run?}`.
O service é `ReservationFeeBilling`.

### Reuso, sem lançamento paralelo

- **`CollectionReports::period`:** o período das reservas (datas válidas, até o máximo de dias do relatório).
- **`EntryShortcuts::createCharge`:** cada taxa vira uma cobrança extra de uma unidade, valor fixo, com:
  - a conta de receita informada;
  - a competência do mês da reserva;
  - o vencimento informado;
  - o vínculo `reservation_id`.

  Valem as mesmas regras do `createCharge`:
  - conta de receita analítica válida;
  - competência fechada recusada (ADR 0031/0100);
  - vencimento não passado;
  - unidade ativa.

  A conta gerada fica com a trava de origem da cobrança extra (`origin_lock`): cancela-se pela cobrança. A auditoria é a
  da cobrança.

### Mudança mínima no `EntryShortcuts::createCharge`

1. Aceita `reservation_id` também na cobrança extra, não só na multa. A reserva tem de ser da unidade, e uma cobrança
   com reserva é de uma unidade só. A mensagem da multa continua a mesma.
2. Grava na transação de quem chamou, quando já existe uma, como o `MonthClosing::close` (ADR 0205). Chamado sozinho
   (tela e API da cobrança extra), continua igual.

### Quem entra

Reservas confirmadas do condomínio no período, não excluídas, com taxa maior que zero e sem cobrança extra ativa da
própria reserva. As já cobradas vêm em `already_billed`. Cancelada a cobrança (`cancelCharge`), a reserva volta a
entrar.

### Gravação

- **Tudo ou nada:** numa transação com as reservas travadas (`FOR UPDATE`), uma recusada pelo `createCharge` (por
  exemplo, mês fechado) desfaz todas. O 422 traz `_form` e `_reservation`.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **Auditoria:** a do `createCharge` e um registro `bill_fee` por reserva (cobrança gerada), só quando grava.
- **Limite:** até 500 reservas por vez.
- **Nada é enviado:** a cobrança extra não manda aviso.

### Acesso

- **Escopo (ADR-002):** um condomínio do escopo; fora dele, 404.
- **Permissão:** exige `erp.access` e `erp.reservations.view`, mais as permissões da cobrança extra
  (`erp.invoices.view` e `erp.invoices.manage`). Sem uma delas, 403 (fail-closed).
- **CSRF:** pelo `ApiAuthenticated`.

### Dados expostos

Saem reserva, área, data, taxa e cobrança. Nenhum nome de morador.

## Consequências

- **Testes.**
  - `Unit\ReservationFeeBillingTest`:
    - regra pura da descrição (até 120 caracteres, a data nunca cortada);
    - validação (condomínio, período, vencimento passado ou inválido) e escopo (404);
    - contrato: usa o `createCharge` e o `period`, sem `INSERT` ou `UPDATE` próprio, e com as duas mudanças do
      `EntryShortcuts`;
    - controller e rota.
  - `Integration\ReservationFeeBillingTest` (MariaDB, banco `*_test`):
    - só a reserva confirmada com taxa, no período, ainda não cobrada (sem taxa, pendente, fora do período, excluída e
      já cobrada à mão ficam de fora);
    - prévia sem gravar;
    - cobrança extra e conta a receber conferidas (unidade, valor, competência, vencimento, vínculo, descrição);
    - repetir não cobra de novo; cancelada a cobrança, volta a entrar;
    - mês fechado recusa tudo;
    - regras novas do `createCharge` (uma unidade só, reserva de outra unidade na multa e na cobrança);
    - escopo.
  - O `CollectionsCycleIntegrationTest` continua cobrindo a multa com reserva de outra unidade.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration e sem tabela nova:** `reservation_id` já existe (0538).
- **Sem tela nova neste patch.** O relatório de reservas pode ganhar o botão "Cobrar taxas"; quando ganhar, vem o
  artigo da Central de Ajuda.
