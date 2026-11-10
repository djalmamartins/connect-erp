# 0169 — Lançamentos patrimoniais com partida dobrada

Data: 2026-11-10 · Status: proposto para revisão · Modelagem decidida com a autorização do Djalma · Continua o ADR 0167

## Contexto

O ADR 0167 deixou os fundos valendo só o saldo inicial (AP-161), porque o lançamento do ERP é por natureza
(receita ou despesa, em `erp_entries`). Faltava como apropriar e usar o fundo de reserva, transferir entre
fundos e ajustar o PL, sem mexer no lançamento por natureza e sem contar duas vezes na DRE.

## Decisão

- **Tabela nova `erp_patrimonial_entries`** (migration 20261109_0706, com down):
  - cada linha tem data, descrição, valor positivo, conta de débito e conta de crédito;
  - as duas contas são patrimoniais analíticas (asset, liability ou equity), ativas, do plano padrão ou do
    próprio condomínio, e diferentes entre si;
  - a conta bancária e a receita/despesa ligadas são opcionais e servem só de referência: onde o dinheiro do
    fundo está e qual lançamento originou o movimento.
- **Não passa pela DRE nem pelo banco**:
  - a receita da taxa de fundo continua em `erp_entries`, entra na DRE e no Disponível;
  - o lançamento patrimonial só reclassifica dentro do patrimônio. Por exemplo, a apropriação debita 9.2
    Resultado do exercício e credita 8.2 Fundo de reserva; o uso numa despesa debita 8.2 e credita 9.2.
  - por isso não há dupla contagem: o resultado da DRE é o mesmo, e o PL cai na mesma medida em que o fundo
    sobe.
- **Contas calculadas não recebem lançamento**: 7.1 Disponível, 7.2 Contas a receber, 8.1 Contas a pagar e as
  subcontas delas respondem 422. Um "aporte" que debitasse o Disponível sem uma receita deixaria o banco
  diferente do extrato. O dinheiro entra pela receita e o lançamento patrimonial aponta para ela.
- **Sem exclusão**:
  - o estorno (`POST /patrimonial-entries/{id}/reverse`) cria o lançamento invertido, com `reversal_of_id` e
    o motivo, na data do estorno (padrão hoje, nunca antes do original);
  - o original fica com `status='reversed'`, e os dois contam e se anulam;
  - um estorno de estorno, ou um segundo estorno, responde 409. O UPDATE é condicional, e o
    `reversal_of_id` é único.
- **Competência fechada** (ADR 0031): não recebe lançamento nem estorno (409).
- **No balanço**:
  - fundos e demais contas patrimoniais = saldo inicial + movimentos até a data;
  - movimentos na 9.1 entram nos acumulados;
  - movimentos na 9.2 entram no exercício do ano e, na virada, nos acumulados (virtual, como no ADR 0167);
  - um movimento com data anterior ao saldo inicial da conta fica de fora. O saldo inicial do PL (9.1) também
    substitui os movimentos de PL anteriores a ele.
- **No Livro Razão**:
  - cada lado é uma linha da sua conta (`source: "patrimonial"`), pela data do lançamento nas duas bases;
  - no ativo o débito soma; no passivo e no PL, o crédito soma;
  - o saldo anterior segue a mesma regra do saldo inicial.
- **API**:
  - `GET /api/v1/patrimonial-entries` (período até 366 dias, LIMIT de 5000 com `truncated`);
  - `POST /api/v1/patrimonial-entries` e `POST /api/v1/patrimonial-entries/{id}/reverse`;
  - permissões existentes: ver exige `erp.entries.view` e `erp.chart.view`; lançar e estornar, também
    `erp.entries.manage`;
  - escopo por condomínio (fora, 404), 422 para inválido, 409 para conflito;
  - CSRF no `ApiAuthenticated` e auditoria em `erp_patrimonial_entries`.

## Consequências

- `PatrimonialEntriesTest` (4 testes) cobre:
  - apropriação, uso e transferência com o balanço fechado e a DRE igual;
  - a virada do ano;
  - o estorno sem exclusão, 409 e 422;
  - a validação de contas calculadas, de receita, de outro condomínio e iguais, além de valor, data,
    referências, escopo e competência fechada;
  - o Razão com os dois lados e o saldo anterior.
- As fixtures do `AccountingReportsTest` e do `BalanceSheetTest` criam a tabela nova.
- Ainda não feito: aplicação financeira como ativo separado do banco (exigiria movimentar o Disponível com
  partida dobrada) e lançamento patrimonial pela importação.
