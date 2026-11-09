# 0064 — Índices por data de pagamento e EXPLAIN das consultas quentes

- Status: aceito (09/11/2026)
- Contexto: revisão de desempenho do banco (dashboard, painéis, relatórios e conciliação), ADR 0024, 0037, 0053, 0054, 0057 e 0058

## Contexto

A revisão cruzou as consultas quentes com os índices criados pelas migrations. Ficaram de fora o login, os cadastros e as telas sem soma.

| Consulta | Filtro | Índice que já existia |
|---|---|---|
| Balancete (`FinancialReports::trialBalance`) | condomínio + competência | `idx_erp_entries_competence` |
| Dashboard: a receber vencido e contas da semana | condomínio + status + vencimento | `idx_erp_entries_condominium` |
| Painel da unidade: contas a vencer, acordos, entregas, reservas, ocorrências | `unit_id` | `idx_*_unit` |
| Manutenções, ocorrências, entregas, seguros e contratos do dashboard | condomínio + status/data | `idx_*_list` |
| Fila de avisos | status + `available_at` | `idx_erp_notification_outbox_queue` |
| Divergências do retorno e linhas pendentes do extrato | condomínio + situação | `idx_*_review` |
| **Prestação de contas por caixa** (`FinancialReports::statement`) | condomínio + **`paid_on`** | nenhum. O índice do condomínio só filtra condomínio, `deleted_at` e status, e depois lê todos os lançamentos do condomínio |
| **Saldos e conciliação por conta bancária** (`BankBalances`, a conciliação da prestação de contas, `Imports::systemBalance` e os candidatos do OFX) | `bank_account_id` + **`paid_on`** | só `idx_erp_entries_bank_account`, de uma coluna |

`erp_entries` é a tabela que mais cresce: uma conta por unidade por mês, mais parcelas, avulsos e importações. As duas consultas sem índice composto rodam em toda abertura da prestação de contas, dos saldos e da importação de extrato.

## Decisão

- **Migration `20261109_0701_performance_indexes`**, que só acrescenta índices e não muda dados:
  - `idx_erp_entries_paid` (`condominium_id`, `paid_on`);
  - `idx_erp_entries_bank_paid` (`bank_account_id`, `paid_on`).

  A reversão tira os dois. A FK de `bank_account_id` continua coberta pelo índice antigo.
- **`QueryPlanIntegrationTest`**, na suíte de integração que roda no lab, faz `EXPLAIN` de 11 consultas quentes e confere que o índice esperado está em `possible_keys`. Não confere o índice escolhido (`key`), porque com poucas linhas o otimizador pode ler a tabela inteira, e o teste ficaria instável. As consultas repetem o formato das usadas nos serviços. Se um serviço mudar o filtro, o teste precisa ser atualizado junto.
- **O smoke test** (`tests/smoke.php`) continua sem banco, como define a ADR 0006. O EXPLAIN fica na integração, que tem o banco `_test`.

## Consequências

- **Gravação.** Cada lançamento passa a ter dois índices a mais para manter. O custo é pequeno perto da leitura dos relatórios.
- **Antes de aplicar no lab:**
  - fazer backup (`tools/backup-database.sh` e `tools/backup-files.sh`);
  - aplicar com `composer db:migrate`.

  Em produção, só com aprovação, como toda migration.
- **Fora desta decisão:**
  - paginação com `OFFSET` alto nas listagens: o limite atual é de 200 linhas;
  - as subconsultas de transferência por conta, que usam os índices de uma coluna de `erp_bank_transfers` e são poucas linhas por conta.
