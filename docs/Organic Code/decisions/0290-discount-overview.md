<!-- markdownlint-disable MD024 -->
# 0290 — Descontos concedidos em contas a receber

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0057 (atalhos de lançamento: o desconto numa conta a receber em aberto, AP-026, com a trilha em
    `erp_entry_adjustments`).

## Contexto

O desconto baixa o valor de uma conta a receber em aberto. Ele deixa uma trilha com o valor antes, o desconto e o valor
depois. A trilha só aparece conta por conta (`GET /entries/{id}/adjustments`).

A administradora não vê, de uma vez:

- quanto foi dado de desconto por mês;
- se os descontos são pequenos ou levam a conta quase a zero;
- quais contas foram zeradas por desconto.

Busquei `erp_entry_adjustments` em `source/` (só o `EntryShortcuts` grava e lê), as rotas `/reports/*` e o README das
ADRs 0200 a 0289. Nenhum relatório resume os descontos. O assunto não é a cobrança extra: o desconto vale para qualquer
conta a receber.

## Decisão

Rota nova: `GET /api/v1/reports/discounts?condominium_id=&months=`, logo depois de `POST /extra-charges/cancel-batch`.
O service é `DiscountOverview`, em `Source\Services\Erp`.

### Reuso

- **Trilha:** `erp_entry_adjustments` (tipo `discount`, com `before_cents` e `after_cents`), gravada pelo
  `EntryShortcuts::discount`. O teste unit confere que a enum só tem `discount` e que o `EntryShortcuts` grava a trilha.
- **Permissão:** a de ver a trilha de uma conta (`EntryShortcuts::ENTRIES`, `erp.entries.view`).
- **Relógio:** os meses do banco (`SELECT NOW()`), porque `created_at` foi gravado por ele.

### Regra pura

- **`months()`:** os últimos N meses (padrão 12, de 1 a 36), do mais antigo ao atual.
- **`band()`:** a faixa do desconto em relação ao valor antes dele, pelos limites de `BANDS` (até 10%, de 10% a 50%, mais
  de 50%). O limite entra na faixa.
- **`summarize()`:**
  - `rows`: uma linha por mês, sempre todos, com os descontos, as contas distintas no mês, o valor descontado e o valor
    antes; `totals` soma as linhas;
  - `by_band`: os descontos por faixa, somando `totals.discounts`;
  - `zeroed`: os descontos que levaram a conta a zero;
  - `zeroed_entry_ids` e `largest_entry_ids` (do maior desconto para o menor), até 200 cada, com `truncated`.
- **Ficam de fora:** desconto fora da janela, de conta apagada e de conta de outro condomínio.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.entries.view`. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** meses, contagens, valores em centavos e ids de contas. Não saem motivo, descrição, unidade nem quem
  deu o desconto.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\DiscountOverviewTest`:**
  - a janela de meses (com a virada do ano) e as faixas nos limites, vindas de `BANDS`;
  - linhas por mês com totais iguais à soma, e faixas somando os descontos;
  - desconto fora da janela, de conta apagada, de outro condomínio e de conta de outro condomínio de fora;
  - as contas zeradas, os maiores descontos e o corte em 200;
  - nenhum motivo, descrição, unidade nem quem deu;
  - a validação, o escopo, o contrato, o controller, a rota e a OpenAPI.
- **`Integration\DiscountOverviewTest`** (MariaDB, banco `*_test`):
  - descontos em `erp_entry_adjustments`, com os meses pelo relógio do banco;
  - somente leitura, sem motivo;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
