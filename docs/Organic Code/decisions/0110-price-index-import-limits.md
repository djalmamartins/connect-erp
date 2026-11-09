# 0110 — Índices de correção: sem mês futuro e com teto por arquivo

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão das regras de cobrança (ADR 0040)

## Contexto

Na ADR 0040, `BillingRules::parseIndexFile` aceitava qualquer competência válida, inclusive meses
futuros, que ainda não têm índice publicado. Um erro de digitação (2027 no lugar de 2026) gravava uma
variação que passaria a corrigir as contas quando aquele mês chegasse. O arquivo também não tinha
limite de linhas.

## Decisão

- Competência depois do mês corrente é recusada ("ainda não tem índice publicado"), no lançamento de
  um mês e na importação. Os dois caminhos usam `parseIndexFile`.
- No máximo `MAX_INDEX_MONTHS` = 600 meses (50 anos) por arquivo.
- O mês corrente é aceito, para quem lança o índice assim que é publicado.

## Consequências

- A API responde 422 nesses casos. Não há migration.
- Os testes estão na suíte unitária (`LateChargesTest::testRuleValidationAndIndexFile`), que roda no CI.
