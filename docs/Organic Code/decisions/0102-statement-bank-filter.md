# 0102 — Prestação de contas: conciliação bancária só com o próprio condomínio e até o fim do período

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão: `docs/security/REVIEW_API_FINANCEIRO.md` (11ª rodada)

## Contexto

Na prestação de contas (`FinancialReports::statement`, ADR 0037), a conciliação por conta bancária juntava
todos os lançamentos pagos que apontavam para a conta. Dois problemas apareceram:

- não conferia o condomínio do lançamento, como já tinha acontecido nos saldos (ADR 0101) e nos gráficos (ADR 0069);
- não tinha limite superior de data, então juntava também os pagamentos posteriores ao período. Eles não
  entram em nenhuma soma, mas pesavam na consulta e cresciam com o tempo.

## Decisão

A junção passa a exigir `e.condominium_id = b.condominium_id` e `e.paid_on <= fim do período`. As
transferências já vêm filtradas pelo `BankBalances::transferSql` (ADR 0101).

## Consequências

- O resultado só muda com dado inconsistente, por exemplo um lançamento de outro condomínio que aponte
  para a conta.
- `FinancialReportsIntegrationTest::testStatementBankUsesOnlyOwnCondominiumUpToThePeriodEnd` cobre os
  dois filtros.
- `LateCharges` (multa, juros e correção) foi revisado sem mudança: é regra pura, o laço de índices para
  em 600 meses, e os valores vêm da regra validada do condomínio.
