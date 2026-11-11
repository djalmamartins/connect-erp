<!-- markdownlint-disable MD024 -->
# 0220 — Conferência das leituras de consumo do mês

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0045 (leituras e consumo);
  - 0058 (importação de leituras);
  - 0104 (competência fechada);
  - 0188 (conferência de despesas: pico com histórico curto);
  - 0218 (mesmo formato de relatório).

## Contexto

As leituras de água, gás e energia (`MeterReadings`, AP-041 e AP-042) viram a cobrança de cada unidade. Os relatórios
que existem (`ManagementReports` e `ChartReports`) mostram só totais.

Antes de cobrar, nada aponta:

- o relógio sem leitura no mês;
- o consumo muito acima do costume da unidade (vazamento, leitura digitada errada) ou muito abaixo dele;
- o consumo zero (relógio parado);
- a leitura que não continua da anterior.

Hoje a administradora confere unidade por unidade, ou descobre depois, pela reclamação do morador.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/meter-reading-review?condominium_id=&competence=AAAA-MM&factor=`. O
service é `MeterReadingReview`.

### Regra pura `classify()`

A regra roda por relógio ativo do condomínio.

| Problema | Quando |
|---|---|
| `missing` | sem leitura na competência |
| `sequence_break` | a leitura anterior gravada não é a última leitura do relógio (ou a inicial, na primeira) |
| `zero` | consumo zero |
| `spike` | consumo acima de `factor` × a média |
| `drop` | consumo abaixo da média ÷ `factor` |

- **Fator:** padrão 2, de 1,5 a 5.
- **Média:** usa só os meses com leitura dos 12 anteriores e exige 3 meses. Com menos que isso, `average` fica `null`,
  nada é apontado por média e o relógio entra na contagem `short_history`. É a lição dos dados do laboratório, que
  começam no meio do ano.
- **Totais:** relógios, lidos, faltando, já cobrados (`entry_id`) e histórico curto; e a contagem por problema.

### Reuso

- Usa as mesmas tabelas e a mesma ordem de leitura do `MeterReadings`.
- A correção continua pelo lançamento da leitura (`MeterReadings::record`), que já recusa competência fechada e leitura
  menor que a anterior.

### Acesso e dados

- **Escopo (ADR-002):** um condomínio do escopo; fora dele, 404; entrada inválida, 422.
- **Permissão:** exige `erp.access` e `erp.meter_readings.view`, as mesmas da lista de leituras; sem uma delas, 403
  (fail-closed).
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** o relógio (tipo e número), a unidade (id e rótulo), o consumo, a média e a razão. Nenhum nome de
  morador.
- **Limite:** até 3000 relógios por condomínio.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\MeterReadingReviewTest`:**
  - a regra pura: histórico curto, pico, queda, fator e sequência;
  - a montagem no SQLite, com relógio inativo e outro condomínio de fora e histórico curto contado;
  - validação e escopo (404);
  - contrato: somente leitura e sem morador;
  - controller e rota.
- **`Integration\MeterReadingReviewTest`** (MariaDB, banco `*_test`):
  - leituras gravadas pelo `MeterReadings::record`;
  - pico de 3,5×, relógio sem leitura e consumo zero;
  - os totais;
  - nenhuma linha nova na auditoria;
  - o fator 4 não apontando o pico;
  - escopo.
- **Sem tela nova neste patch.** A tela de leituras pode ganhar o botão "Conferir antes de cobrar"; quando ganhar, vem
  o artigo da Central de Ajuda.
