<!-- markdownlint-disable MD024 -->
# 0400 — Conferência entre as faturas e as contas a receber

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0033 (faturas, AP-032: preparação, emissão em contas a receber e cancelamento);
  - 0039 (acordo: a conta renegociada);
  - 0198 (conferência do faturamento por unidade e competência);
  - 0296 (situação dos tipos de faturas).

## Contexto

Primeira ADR da faixa 0400–0599. Não sobrou cadastro sem relatório de situação fora das áreas proibidas: áreas comuns e
vinculáveis (AP-007, AP-008), patrimônio (AP-019) e a integração bancária. Por isso este é um relatório que cruza dois
módulos que já existem: as faturas e as contas a receber.

A fatura cobra um tipo de fatura de todas as unidades numa competência:

- **preparada**, guarda um item por unidade;
- **emitida**, cada item vira uma conta a receber (`erp_entries.invoice_item_id`, chave única);
- **cancelada**, as contas da fatura ficam inativas (`Invoices::cancel`).

Depois disso as contas seguem a vida própria: baixa, acordo, edição e exclusão. Ninguém confere, fatura por fatura, se o que
foi faturado continua batendo com o que está lançado. A conferência do faturamento (ADR 0198) olha a unidade na competência,
não a fatura.

Busquei `erp_invoice_items` e `invoice_item_id` em `source/`: só `Invoices` e a amostra usam. Nenhum relatório confere as
faturas contra as contas.

## Decisão

Rota nova: `GET /api/v1/reports/invoice-reconciliation?condominium_id=&months=`, logo depois de `POST /meters/deactivate-idle`.
O service é `InvoiceReconciliation`, em `Source\Services\Erp`.

### Reuso

- **Situações das faturas:** `Invoices::STATUSES`.
- **Ligação item → conta:** `invoice_item_id`, chave única (o teste unit confere a migration).
- **Regras do cancelamento:** `Invoices::cancel` inativa as contas da fatura emitida (o teste unit confere o código). Conta
  paga ou em acordo impede o cancelamento.
- **Conta renegociada:** `agreement_id` (ADR 0039).
- **Cadastro de lançamentos** (`RegistryService::definition('entries')`): a tabela e a permissão.
- **Período:** `DiscountOverview::months()` pela competência, no mês do banco (`SELECT NOW()`).

### Regra pura `classify()`, por item

- **Fatura emitida:**
  - `missing`: sem conta, ou a conta foi excluída;
  - `renegotiated`: conta em acordo;
  - `inactive`: conta inativa fora de acordo;
  - `amount_mismatch`: conta ativa com valor diferente do item;
  - `paid` ou `open`: conta ativa com o mesmo valor, paga ou em aberto.
- **Fatura cancelada:** `still_active` quando a conta segue ativa.
- **Preparação:** nenhuma classe, porque ainda não há conta.

### Regra pura `summarize()`

- **`rows`:** uma linha por situação de fatura, sempre todas, com:
  - `invoices`, `items` e `amount_cents` (soma dos itens);
  - `total_mismatch`: faturas cujo total ou quantidade de unidades gravados não batem com os itens;
  - `overdue_drafts`: preparações com vencimento já passado;
  - as classes dos itens.
  `totals` soma as linhas.
- **`attention_ids`:** as faturas com total que não bate, preparação vencida ou algum item `missing`, `inactive`,
  `amount_mismatch` ou `still_active`, até 200, com `truncated`. Pago, em aberto e renegociado não pedem atenção.
- **Período:** as faturas das competências dos últimos `months` meses (padrão 12, de 1 a 60).

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.invoices.view` e `erp.entries.view`. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** situações, contagens, valores e ids de faturas. Não saem unidade, morador, descrição nem conta
  bancária.
- **Não toca** a integração bancária, o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\InvoiceReconciliationTest`:**
  - as classes do item por situação da fatura (o acordo vem antes da situação e do valor);
  - o total gravado que não bate e a preparação vencida;
  - totais iguais à soma das linhas e as faturas que pedem atenção, com o corte em 200;
  - a conta excluída como sem conta, o período nos limites e outro condomínio de fora;
  - nenhuma unidade, descrição nem morador;
  - situações vindas de `Invoices::STATUSES`, sem quantidade nem lista fixa;
  - a validação, o escopo, o contrato, o controller, a rota e a OpenAPI.
- **`Integration\InvoiceReconciliationTest`** (MariaDB, banco `*_test`): faturas, itens e contas de verdade ligados por
  `invoice_item_id`, com a competência pelo mês do banco; somente leitura, sem unidade nem descrição; o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
- **Numeração:** a próxima do Avançar é a 0401 (faixa 0400–0599).
