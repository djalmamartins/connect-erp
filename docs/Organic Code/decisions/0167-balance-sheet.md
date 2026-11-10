# 0167 — Balanço Patrimonial e o saldo inicial do plano como abertura do Livro Razão

Data: 2026-11-10 · Status: proposto para revisão · O Djalma autorizou decidir a modelagem · Continua o ADR 0165 (AP-081 pendente) e o 0166 (AP-161)

## Contexto

O ADR 0165 deixou o AP-081 Balanço Patrimonial pendente, porque o plano de contas só tinha receita e
despesa. O ADR 0166 criou o saldo inicial do plano (AP-161), mas ainda sem efeito no Livro Razão.

## Decisão

- **Naturezas patrimoniais** (migration 20261109_0704, com down):
  - `erp_chart_accounts.nature` ganha `asset`, `liability` e `equity`. O cadastro do plano (AP-160) passa a
    oferecer Ativo, Passivo e Patrimônio líquido.
  - O lançamento continua só em receita ou despesa: o cadastro de lançamentos exige que a natureza seja a
    mesma da conta.
- **Contas no plano padrão**, em 7, 8 e 9, porque 1 e 2 já são Receitas e Despesas:
  - 7 Ativo:
    - 7.1 Disponível (sintética que puxa os saldos bancários);
    - 7.2 Contas a receber.
  - 8 Passivo:
    - 8.1 Contas a pagar;
    - 8.2 Fundo de reserva;
    - 8.3 Outros fundos.
  - 9 Patrimônio Líquido:
    - 9.1 Resultados acumulados;
    - 9.2 Resultado do exercício.
  - A migration é idempotente: só cria um código que ainda não existe no padrão, e só cria a filha se a mãe
    for patrimonial da mesma natureza.
  - O down só remove as contas criadas que não têm saldo inicial. Se ainda houver conta patrimonial, o down
    falha de propósito, para não apagar dado do usuário.
- **`Services/Erp/BalanceSheet`**: o balanço de um condomínio numa data, calculado na leitura, sem lançamento
  físico. A regra pura é `compose()`.
  - **Contas calculadas**, pelo código do padrão:
    - 7.1 Disponível = os saldos bancários na data (`AccountingReports::accountBalances`), com uma linha
      por conta bancária.
    - 7.2 Contas a receber = as receitas com competência até o mês da data e não pagas até a data, com o
      valor vencido à parte.
    - 8.1 Contas a pagar = as despesas na mesma regra.
    - 9.2 Resultado do exercício = a DRE por competência de 1º de janeiro até a data
      (`AccountingReports::incomeStatement`). Se o saldo inicial do PL tiver data dentro do ano, o
      exercício começa no mês dele.
    - 9.1 Resultados acumulados = o saldo inicial da 9.1 (AP-161) mais o resultado dos anos anteriores
      desde a data desse saldo.
  - **Virada do ano**: é só virtual. Em 1º de janeiro, o resultado do ano anterior passa a contar nos
    acumulados.
  - **Demais contas patrimoniais analíticas** (fundos 8.2 e 8.3 e contas próprias do condomínio): valem o
    saldo inicial (AP-161) com data até a do balanço. Saldo inicial em conta calculada fica de fora, com o
    aviso `opening_ignored`.
  - **Diferença**: o balanço não força o fechamento. O campo `diferenca` = ativo − (passivo + PL), com
    `balanced` e o aviso `unbalanced` quando é diferente de zero. A causa mais comum é o saldo inicial das
    contas bancárias, que não tem contrapartida no PL até alguém registrar o saldo inicial da 9.1.
  - **`limitations`** (devolvidas no relatório):
    - a contas a receber e a pagar seguem a competência;
    - os fundos só mudam pelo saldo inicial;
    - um pagamento sem conta bancária entra no resultado, mas não no Disponível.
- **`GET /api/v1/reports/balance-sheet?condominium_id=&date=`**:
  - **Permissão:** a mesma do Razão e da DRE (`erp.entries.view` e `erp.chart.view`).
  - **Parâmetros:** o condomínio é obrigatório, porque somar balanços de condomínios diferentes não tem
    sentido. Sem ele, ou com data inválida, a resposta é 422.
  - **Escopo:** fora do escopo, 404.
  - **Limite:** LIMIT de 2000 contas, com `truncated`.
  - **CSV:** `format=csv`, com a fórmula neutralizada, o limite de 30 por hora e a auditoria `export`.
- **Livro Razão** (`AccountingReports::ledger`):
  - O saldo inicial do AP-161 com data até o início do período é a abertura da conta e substitui os
    lançamentos anteriores à data dele, na mesma base do relatório: data de pagamento no caixa, competência
    na competência.
  - Os lançamentos depois da data somam normalmente.
  - Um saldo com data depois do início do período fica de fora e aparece em `openings_ignored`.
- **`ChartOpenings::tree`**:
  - traz o total por natureza patrimonial (`asset_cents`, `liability_cents`, `equity_cents`);
  - `total_cents` continua sendo receita + despesa;
  - a ordem segue receita, despesa, ativo, passivo e PL.

## Consequências

- `BalanceSheetTest` (4 testes) cobre:
  - o balanço com diferença e depois fechado;
  - os fundos e o saldo ignorado;
  - a virada do ano e o saldo do PL no meio do ano;
  - o escopo, o 404 e a validação 422;
  - o CSV com a fórmula neutralizada;
  - a permissão no controller;
  - a migration sem duplicar;
  - a abertura no Razão (competência, caixa, antes e depois da data, saldo dentro do período e todos do
    escopo).
- `AccountingReportsTest` passa a criar `erp_chart_account_openings` na fixture.
- Ainda não feito: o lançamento direto em conta patrimonial (por exemplo, o aporte no fundo de reserva como
  passivo). Para isso, seria preciso o lançamento de partida dobrada, e o ERP de hoje lança por natureza.
