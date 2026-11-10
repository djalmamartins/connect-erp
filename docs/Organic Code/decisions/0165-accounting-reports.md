# 0165 — Relatórios contábeis: Livro Razão, Saldos de Contas e DRE (o Balanço Patrimonial fica pendente)

Data: 2026-11-10 · Status: proposto para revisão · Continuação do inventário do ADR 0164

## Contexto

O inventário (`docs/process/PAGINAS-EXEMPLO.md`) mostrou quatro páginas do bloco contábil sem backend: AP-083
Livro Razão, AP-073 Saldos de Contas, AP-082 Demonstração do Resultado e AP-081 Balanço Patrimonial.

## Decisão

- **`Services/Erp/AccountingReports`:** um service com regra pura e uma rota por relatório.
  - Permissão: `erp.entries.view` e `erp.chart.view`, as mesmas do Balancete Anual (ADR 0164).
  - Escopo: `condominium_id` vazio traz todos do escopo; fora do escopo, 404.
  - Período: `from` e `to` em AAAA-MM-DD, até 366 dias.
  - Base: `basis=cash` usa a data do pagamento; `basis=competence`, o padrão, usa as competências cobertas
    pelas datas.
  - Erros: parâmetro inválido responde 422.
  - LIMIT de 5000 linhas, com o campo `truncated`.
  - CSV opcional (`format=csv`), com a fórmula neutralizada, o limite de 30 por hora e a auditoria `export`.
- **AP-083 Livro Razão** (`/reports/ledger`):
  - Por conta, o saldo anterior (tudo antes do período), as linhas do período e o saldo final.
  - Cada linha traz débito ou crédito e o saldo acumulado.
  - Conta de despesa movimenta a débito; conta de receita, a crédito. O saldo cresce na natureza da conta.
  - O filtro opcional `account_code` pega a conta e as subcontas.
- **AP-073 Saldos de Contas** (`/reports/account-balances`):
  - Cada conta bancária numa data (`as_of`) ou num período (`from`/`to`): saldo anterior, entradas, saídas
    e saldo final.
  - "Conta" aqui é a conta bancária, que é o que tem saldo. As contas do plano de contas aparecem no Razão e
    na DRE.
  - O cálculo é o mesmo da conciliação do AP-051 (`FinancialReports::statement`), com saldo inicial e
    transferências, sem SQL duplicado.
- **AP-082 Demonstração do Resultado** (`/reports/income-statement`):
  - Receitas e despesas do período somadas no plano de contas, com as analíticas nas sintéticas como no
    AP-079 (`FinancialReports::rollup`).
  - Traz o resultado e a margem (resultado sobre receita; null sem receita).
- **AP-081 Balanço Patrimonial: não feito.**
  - O plano de contas (`erp_chart_accounts.nature`) só tem receita e despesa, sem ativo, passivo nem
    patrimônio líquido. Um balanço feito disso seria inventado.
  - Para fazer o balanço, falta:
    - natureza patrimonial no plano de contas, com migration e o plano padrão;
    - lançamentos ou saldos de ativo e passivo (por exemplo, fundo de reserva como passivo e contas a
      receber como ativo);
    - a regra de fechamento do resultado no PL.
  - Fica como pendente, para decisão do Djalma.

## Consequências

- `AccountingReportsTest` cobre:
  - a regra pura do Razão (débito, crédito e saldo acumulado);
  - as bases caixa e competência e o filtro por conta;
  - a DRE (sintéticas, resultado e margem, inclusive sem receita);
  - os Saldos de Contas numa data e num período;
  - o escopo e o 404 nas três rotas;
  - a validação 422;
  - o CSV com a fórmula neutralizada;
  - a permissão e o limite no controller.
- Sem migration e sem página nova no catálogo.
