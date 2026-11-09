# 0068 — Relatórios gerenciais: extrato sem oráculo de id, rateio com teto e SQL só por parâmetro

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão: `docs/security/REVIEW_API_FINANCEIRO.md` (sétima rodada)

## Contexto

A revisão dos relatórios gerenciais (ADR 0082) conferiu o escopo por condomínio, o CSV, os tetos de
linhas e o custo das consultas. O CSV já usa `CollectionReports::csvCell`, então a correção do ADR
0067 vale para eles sem mudança. Os relatórios em linhas já têm teto de 5.000 linhas, e os
agregados ficam limitados pelo número de grupos. Ficaram três pontos.

## Decisão

- **Extrato da conta sem oráculo.** Uma conta bancária inexistente respondia 422 ("não encontrada"),
  e a de outro condomínio, 404. Isso revelava que o id existia. Agora as duas respondem 404
  (`CondominiumAccessDenied`).
- **Rateio com teto.** O rateio monta na memória a matriz despesas × unidades e só limitava as linhas
  das visões por lançamento. A visão unidade × conta não tinha teto, e a matriz também não. Agora
  `ManagementReports::assertMatrix` recusa mais de 5.000 despesas ou mais de 500.000 células, nas
  duas competências do comparativo, e a visão unidade × conta para em 5.000 linhas. Acima disso,
  responde 422.
- **SQL só por parâmetro.** A data de abertura da conta entrava concatenada na consulta do extrato.
  Vinha do banco, mas agora vai por parâmetro.

## Consequências

- `ManagementReportsTest::testApportionmentMatrixCapAndStrictCsv` e
  `ManagementReportsScopeIntegrationTest` cobrem as regras.
