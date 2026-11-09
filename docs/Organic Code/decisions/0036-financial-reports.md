# 0036 — Relatórios financeiros: balancete e prestação de contas com conciliação

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O passo 6 do `docs/process/IMPLEMENTATION_ORDER.md` pede relatórios financeiros e prestação de
contas com reconciliação. Os dados já existem: plano de contas hierárquico (ADR 0019), lançamentos
(ADR 0022), contas bancárias com saldo inicial (ADR 0021 e 0024), faturas (ADR 0033) e baixa pelo
retorno (ADR 0035). As fichas AP-079 e AP-051 ainda são scaffold; as regras abaixo são PROPOSTAS.

## Decisão

- **Sem tabela nova e sem migration.** Os relatórios são calculados a cada pedido, a partir dos
  lançamentos válidos (`status active`, não excluídos), em `Source\Services\Erp\FinancialReports`.
- **Balancete de verificação (AP-079)**, por intervalo de competências (até 24):
  - cada conta do plano mostra o previsto (competência), o realizado (pago) e o que está em aberto;
  - as analíticas somam nas sintéticas pelo código (`rollup`, regra pura, com ordem numérica por
    nível: 1.10 vem depois de 1.9);
  - o nome da sintética vem da conta própria do condomínio ou, se não houver, do padrão da Connect.
  - Em "Todos", as contas com o mesmo código se somam.
- **Prestação de contas por caixa (AP-051, Demonstrativo)**, por intervalo de datas (até 366 dias):
  - recebido e pago por conta do plano, pela data do pagamento;
  - por conta bancária: saldo anterior (saldo inicial + movimento pago antes do período), entradas,
    saídas e saldo final;
  - **conciliação** (`reconcile`): o caixa pelo plano tem de ser igual ao caixa pelos bancos. A
    diferença (lançamento pago sem conta bancária, ou numa conta excluída) aparece em
    `difference_cents`, com `reconciled = false`; nunca é escondida.
- **Escopo:** um condomínio ou todos os do escopo do usuário. A tela usa o contexto da barra
  superior (ADR 0030 e 0032). Condomínio fora do escopo = 404.
- **Permissão:** reaproveita `erp.entries.view` e `erp.chart.view`; quem lê lançamentos e plano já
  pode ver os totais. Não há permissão nova.
- **API:** `GET /api/v1/reports/trial-balance` e `GET /api/v1/reports/statement`. Nas telas do
  catálogo, AP-079 recebe `$trialBalance` (`?de=AAAA-MM&ate=AAAA-MM`) e AP-051 recebe `$statement`
  (`?de=AAAA-MM-DD&ate=AAAA-MM-DD`), mais `$reportPeriod` e `$reportError`.

## Consequências

- A frente de layout troca a demonstração das telas AP-079 e AP-051 por esses dados (impressão e
  PDF ficam para depois).
- Ficam para os próximos ciclos: balancete anual (AP-080, previsto × realizado depende de
  orçamento), balanço patrimonial e DRE (AP-081 e AP-082) e livro razão (AP-083).
- Para muitos anos de dados pode valer um cache por competência fechada (a foto do ADR 0031 já
  existe); por ora o cálculo direto atende.
