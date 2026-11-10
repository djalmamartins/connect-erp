# CN-006 — Lançamentos patrimoniais

Origem: Balanço Patrimonial (ADR 0167) e lançamentos patrimoniais (ADR 0169). Não existe no inventário APControle; é página própria da Connect.
Rota: `/erp/financeiro/lancamentos-patrimoniais` (grupo Financeiro › Lançamentos). Decisão: ADR 0149.
API: `/api/v1/patrimonial-entries`.

## Regras implementadas

| Regra | Situação |
|---|---|
| Lista do período (padrão: 1º de janeiro até hoje, até 366 dias), do condomínio da barra ou de todos do escopo | CONFIRMADO (serviço do ADR 0169) |
| Novo lançamento: data até hoje, descrição, valor positivo, débito e crédito em contas patrimoniais analíticas diferentes | CONFIRMADO |
| 7.1, 7.2, 8.1 e subcontas (calculadas pelo balanço) ficam fora do seletor e a API recusa | CONFIRMADO |
| Conta bancária e lançamento de origem opcionais, só referência | CONFIRMADO |
| Estorno com motivo e data (não antes do original); sem exclusão; estorno de estorno recusado | CONFIRMADO |
| Competência fechada recusa lançamento e estorno (409) | CONFIRMADO (ADR 0031) |
| Ver exige `erp.entries.view` e `erp.chart.view`; lançar e estornar, também `erp.entries.manage` | CONFIRMADO |

| Importação por planilha (CSV/XLSX até 2 MB): prévia por linha e gravação tudo ou nada; exige também `erp.imports.view` e `erp.imports.manage` | CONFIRMADO (ADR 0171) |

## PENDENTE

- Histórico das importações patrimoniais na tela (a API só tem o detalhe por id).

## Estados

- Sem banco ou sem permissão: demonstração.
- "Todos os condomínios": só a lista, com a coluna do condomínio.
- Sem lançamentos no período: estado vazio.
