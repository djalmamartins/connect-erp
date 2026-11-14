<!-- markdownlint-disable MD024 -->
# 0296 — Situação dos tipos de faturas

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0020 (tipos de faturas, AP-168: o que se cobra e a conta de receita);
  - 0163 (o tipo do condomínio com o mesmo nome prevalece sobre o padrão);
  - 0033 (faturas, AP-032: rascunho, emitida e cancelada);
  - 0019 (plano de contas: conta analítica de receita).

## Contexto

O cadastro de tipos de faturas junta os tipos padrão da Connect (sem condomínio) e os do próprio condomínio. O tipo do
condomínio com o mesmo nome encobre o padrão (ADR 0163). Cada tipo aponta uma conta analítica de receita, e as faturas são
preparadas a partir dele.

A administradora não vê, por recorrência:

- quantos tipos o condomínio tem à mão, e quantos são próprios ou padrão;
- quantos padrões ficaram encobertos por um tipo do condomínio;
- quais tipos à mão não geraram fatura no período;
- quais apontam uma conta que deixou de valer (inativa, excluída, de despesa ou de outro condomínio), e quais estão sem
  valor padrão.

Busquei `erp_invoice_types` em `source/` (só a definição do cadastro, as faturas e a amostra o usam), as rotas
`/reports/*` e o README das ADRs 0200 a 0295. Nenhum relatório mostra a situação dos tipos de faturas.

## Decisão

Rota nova: `GET /api/v1/reports/invoice-types?condominium_id=&months=`, logo depois de `POST /gatehouses/deactivate-idle`.
O service é `InvoiceTypeOverview`, em `Source\Services\Erp`.

### Reuso

- **Cadastros do registro** (`RegistryService::definition`):
  - `invoice_types`: a tabela, a permissão, as recorrências do campo `recurrence` e a coluna do `override`;
  - `chart`: a tabela e a permissão.
- **Override da ADR 0163:** um tipo não excluído do condomínio com o mesmo valor da coluna encobre o padrão, como na
  listagem da API.
- **Conta que vale:** a regra do cadastro (analítica, de receita, ativa, não excluída, do padrão ou do condomínio).
- **Faturas:** as não canceladas (ADR 0033). O teste unit confere a enum da migration.
- **Período:** `DiscountOverview::months()`, pela competência, no mês do banco (`SELECT NOW()`).

### Regra pura `summarize()`

- **`rows`:** uma linha por recorrência do cadastro, sempre todas, com:
  - `available` (soma de `own` e `standard`): `own` são os tipos ativos do condomínio, `standard` os padrões ativos não
    encobertos;
  - `inactive_own` (tipos inativos do condomínio) e `overridden_standard` (padrões ativos encobertos);
  - dos tipos à mão: `unused` (sem fatura no período), `account_issue` (conta que não vale) e `without_amount` (sem valor
    padrão);
  - `invoices` e `amount_cents`: as faturas não canceladas dos últimos `months` meses (padrão 12, de 1 a 60).
  `totals` soma as linhas.
- **`elsewhere`:** as faturas do período de tipos fora de uso (inativos, encobertos ou excluídos), à parte.
  `all_invoices` e `all_amount_cents` somam as linhas e o `elsewhere`.
- **`unused_ids` e `account_issue_ids`:** até 200, com `truncated`.
- **Ficam de fora:** padrão inativo, tipo excluído, tipo de outro condomínio, fatura cancelada ou fora do período, e
  faturas de outro condomínio.

### Acesso e dados

- **Permissão:** exige `erp.access`, a permissão de ver de cada cadastro que entra na conta (`erp.invoice_types.view` e
  `erp.chart.view`) e `erp.invoices.view`. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** recorrências, contagens, valores e ids de tipos. Não saem nome de tipo, conta, observação nem
  unidade.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\InvoiceTypeOverviewTest`:**
  - linhas por recorrência com totais iguais à soma, e o `elsewhere` à parte somando com as linhas;
  - o padrão encoberto pelo tipo do condomínio e o não encoberto por um tipo excluído;
  - o período nos limites, a fatura cancelada, o tipo excluído, o padrão inativo e outro condomínio de fora;
  - a conta inativa, de despesa ou de outro condomínio, e o corte em 200;
  - nenhum nome de tipo, conta, observação nem unidade;
  - recorrências vindas do cadastro, sem quantidade nem lista fixa;
  - a validação, o escopo, o contrato, o controller, a rota e a OpenAPI.
- **`Integration\InvoiceTypeOverviewTest`** (MariaDB, banco `*_test`):
  - tipos, conta e faturas de verdade, com a competência pelo mês do banco;
  - somente leitura, sem nome;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
- **Ação ligada possível depois:** inativar em lote tipos do condomínio sem fatura há muito tempo, no mesmo desenho das
  ADRs 0283–0295.
