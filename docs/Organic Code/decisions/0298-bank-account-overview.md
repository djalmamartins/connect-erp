<!-- markdownlint-disable MD024 -->
# 0298 — Situação das contas bancárias

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0024 (contas bancárias, CN-003, e o saldo calculado);
  - 0057 (transferências entre contas);
  - 0170 (aplicação ligada a uma conta corrente).

## Contexto

O cadastro de contas bancárias guarda o tipo (corrente, poupança ou aplicação), a conta principal, a conta corrente de
cada aplicação (ADR 0170) e a situação. Os lançamentos pagos apontam a conta, e as transferências ligam duas contas. O saldo
das contas (`BankBalances`) mostra o dinheiro.

A administradora não vê, por tipo de conta:

- quantas contas estão ativas e quantas inativas;
- se o condomínio tem uma conta principal ativa, e só uma;
- quais contas ficaram paradas no período (sem lançamento pago nem transferência);
- quais aplicações perderam a conta corrente (sem conta, conta inativa, removida, que não é corrente ou de outro
  condomínio).

Busquei `erp_bank_accounts` em `source/` (saldo, extratos, conciliação, fechamento e relatórios contábeis), as rotas
`/reports/*` e o README das ADRs 0200 a 0297. Os relatórios existentes mostram saldo e movimento, mas nenhum mostra a
situação do cadastro.

## Decisão

Rota nova: `GET /api/v1/reports/bank-accounts?condominium_id=&days=`, logo depois de `POST /invoice-types/deactivate-unused`.
O service é `BankAccountOverview`, em `Source\Services\Erp`.

### Reuso

- **Cadastros do registro** (`RegistryService::definition`):
  - `bank_accounts`: a tabela, a permissão e os tipos do campo `kind`;
  - `entries`: a tabela e a permissão.
- **Regras do saldo:** lançamento válido = ativo, não excluído, com "Pago em"; transferência válida = ativa e do
  condomínio, como em `BankBalances::transferSql`. O teste unit confere a enum da migration.
- **Conta principal:** uma ativa por condomínio. **Aplicação:** ligada a uma conta corrente ativa do mesmo condomínio
  (ADR 0170).
- **Relógio:** o dia do banco (`SELECT NOW()`).

### Regra pura `summarize()`

- **`rows`:** uma linha por tipo do cadastro, sempre todos, com:
  - `accounts` (ativas) e `inactive`;
  - `main`: as ativas marcadas como principal (a principal inativa não conta);
  - `idle`: as ativas sem lançamento pago nem transferência nos últimos `days` dias (padrão 90, de 1 a 3650);
  - `orphan`: as aplicações ativas sem conta corrente válida;
  - `paid_entries`: os lançamentos pagos no período nas ativas;
  - `transfer_legs`: as pontas de transferência no período nas ativas (a transferência entre duas contas conta uma vez
    em cada).
  `totals` soma as linhas.
- **`main_issue`:** `none` sem conta principal ativa, `multiple` com mais de uma, `null` com exatamente uma.
- **`elsewhere`:** os lançamentos pagos no período sem conta ou numa conta inativa ou removida, à parte.
  `all_paid_entries` soma as linhas e o `elsewhere`.
- **`idle_ids` e `orphan_ids`:** até 200, com `truncated`.
- **Ficam de fora:** lançamento cancelado, excluído, sem pagamento ou fora do período; transferência cancelada ou fora do
  período; conta excluída; outro condomínio.

### Acesso e dados

- **Permissão:** exige `erp.access` e a permissão de ver de cada cadastro que entra na conta (`erp.bank_accounts.view` e
  `erp.entries.view`, esta também a das transferências). Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** tipos, contagens, datas e ids de contas. Não saem apelido, banco, agência, número de conta,
  carteira, saldo nem valor.
- **Não toca** credenciais bancárias, chamadas a banco, o login, o 2FA, o Studio, o portal do morador, o e-mail, o app
  nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\BankAccountOverviewTest`:**
  - linhas por tipo com totais iguais à soma, e o `elsewhere` à parte somando com as linhas;
  - a conta principal (`none`, `multiple` e exatamente uma) e a principal inativa fora da conta;
  - o período nos limites, e lançamento cancelado, excluído ou não pago, transferência cancelada, conta excluída e outro
    condomínio de fora;
  - as contas paradas, as aplicações órfãs e o corte em 200;
  - nenhum apelido, banco, agência, número nem valor;
  - tipos vindos do cadastro, sem quantidade nem lista fixa;
  - a validação, o escopo, o contrato, o controller, a rota e a OpenAPI.
- **`Integration\BankAccountOverviewTest`** (MariaDB, banco `*_test`): contas, lançamentos e uma transferência de
  verdade, com as datas pelo dia do banco; somente leitura, sem apelido nem número; o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
- **Ação ligada possível depois:** inativar em lote contas paradas sem saldo, no mesmo desenho das ADRs 0283–0297.
