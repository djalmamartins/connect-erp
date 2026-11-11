# 0180 — Previsto contra realizado na prestação de contas mensal

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0085 (configurações, previsão orçamentária AP-163), 0166 (prestação de contas, AP-101), 0178
  (CSV) e 0179 (publicação)

## Contexto

Na assembleia, o orçamento do ano é aprovado conta por conta (AP-163). Todo mês, o conselho quer saber se o
condomínio está gastando o previsto. O relatório anual de previsão (AP-084) já mostra isso, mas o pacote do mês (AP-101)
não mostrava. Quem prepara a prestação precisava juntar os dois à mão.

## Decisão

O pacote ganha a seção `budget`, montada a partir de `ErpSettings::budget` do ano da competência, sem SQL próprio.

### O que vem por conta

Só entram as contas com previsão. Para cada uma:

- **o mês:** o previsto, o lançado, a diferença (lançado − previsto) e o percentual;
- **o acumulado:** o mesmo, de janeiro até o mês.

O lançado é por competência, como a DRE do pacote e o AP-084. Os totais são por natureza (receita e despesa).

### No CSV (ADR 0178)

A seção entra em duas linhas por conta:

- "Orçamento (mês)";
- "Orçamento (acumulado)".

Cada linha traz o previsto, o lançado e a diferença.

### Na publicação (ADR 0179)

A seção é parte do pacote, então entra no conteúdo congelado. Uma publicação feita antes deste ADR continua com o
conteúdo que tinha. A próxima publicação de um mês já publicado vira uma nova versão, porque o pacote mudou; a versão
anterior fica como estava.

## Consequências

- **Testes.** `AccountabilityDeliveriesOpeningsTest` confere:
  - a taxa com previsto de 1.800,00 no mês e lançado de 2.000,00: +200,00 e 11,11%;
  - o acumulado de 3.600,00 contra 2.000,00: −1.600,00;
  - a despesa e os totais por natureza;
  - que outro condomínio não entra;
  - a seção vazia quando não há previsão;
  - as duas linhas do CSV.

  O `AccountabilityPublicationsTest` ganhou a tabela no fixture.
- **Contrato da API.** `GET /api/v1/reports/accountability` ganha `budget`; nada foi removido.
- **Sem migration, rota ou tela.** Não precisa de linha nova no `index.php`, na OpenAPI nem no TRUNCATE.
