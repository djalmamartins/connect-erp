# 0104 — Leitura manual recusa competência fechada e unidade do consumo de energia

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão: `docs/security/REVIEW_API_FINANCEIRO.md` (13ª rodada)

## Contexto

A revisão de `MeterReadings` (ADR 0045) encontrou dois pontos.

1. **Competência fechada.** A importação de leituras (ADR 0058) recusa a linha de competência fechada
   (ADR 0031), mas só na prévia. O lançamento manual (`POST /api/v1/meter-readings`) não conferia o
   fechamento, e a gravação da importação também não, então um mês fechado entre a prévia e a
   gravação recebia a leitura.
2. **Unidade de medida.** A descrição da conta gerada pela cobrança dizia "m³" também para energia.

## Decisão

- `MeterReadings::record` confere o fechamento dentro da transação, depois de travar o relógio, com
  `RegistryService::isClosed`, que trava a leitura do fechamento (ADR 0100). Competência fechada gera
  `DomainException`. A importação grava por `record`, então a regra vale nas duas entradas, e a
  importação inteira volta atrás.
- `MeterReadings::unitOf` (regra pura) dá kWh para energia e m³ para água e gás, usado na descrição.

## Consequências

- Corrigir uma leitura de mês fechado exige reabrir o mês, como nos lançamentos.
- Testes: `MeterReadingsTest::testConsumptionUnit` e
  `MeterReadingsIntegrationTest::testClosedCompetenceRefusesReading` (este roda só no Mac, porque o CI
  roda apenas a suíte unitária).
