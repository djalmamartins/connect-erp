# ADR 0146 — Telas do ciclo 55 ligadas à API (prestação de contas, entregas e saldo inicial do plano)

- Situação: aceita (10/11/2026)
- Contexto: serviço e API do ADR 0166; inventário em docs/process/PAGINAS-EXEMPLO.md

## Contexto

O ADR 0166 criou `Accountability` (`GET /api/v1/reports/accountability`), `DeliveryLog`
(`GET /api/v1/reports/deliveries` e `POST /api/v1/delivery-pickups`) e `ChartOpenings`
(`GET/POST /api/v1/chart-account-openings`). As páginas AP-101, AP-136 e AP-161 seguiam com a demonstração.

## Decisão

- **AP-161 Saldo inicial (plano de contas):** entra em `SettingsScreens` (ADR 0144) como o tipo
  `chart-openings`, com o template `pages/settings/chart-openings`: a árvore de `ChartOpenings::list` (sintética
  com a soma, em negrito) e o formulário que só oferece conta analítica ativa do padrão ou do condomínio. Pede
  um condomínio na barra superior. Gravar pelo erp.js (`data-chart-opening-form`) na API.
- **AP-136 Registros de Entregas:** `DeliveryScreens` + `pages/operational/deliveries`, com os filtros
  situação, período da chegada e unidade (a unidade só com um condomínio escolhido), o resumo do serviço em
  cartões e a lista. {{Dar baixa}} abre um diálogo com quem retirou e a data (mínimo a chegada, máximo hoje) e
  envia para `POST /api/v1/delivery-pickups` (erp.js, `data-erp-deliveries`). O período inválido aparece como
  erro na tela, com a mensagem do serviço. Permissões: `erp.deliveries.view` (menu) e `erp.deliveries.manage`
  (botão), como na API.
- **AP-101 Prestação de contas:** `AccountabilityScreens` + `pages/reports/accountability`, de um condomínio,
  abrindo no mês anterior (o mês que costuma ser prestado). Mostra o fechamento, os avisos do serviço, o resumo
  em cartões, a DRE, os saldos bancários e a inadimplência por unidade, com links para os relatórios de origem
  (que têm o CSV). Somente leitura e impressão. Exige `erp.entries.view` e `erp.chart.view`, como a API.

## Consequências

- Os artigos da Central de Ajuda das três páginas passam a `situacao: funciona`; os prints ainda são da
  demonstração até rodar `bash tools/help/prints.sh` no Mac.
- Testes: `Cycle55ScreensIntegrationTest` (soma na sintética, filtros e escopo das entregas, prestação do mês e
  sem permissão) e o smoke de renderização das três telas.
- O saldo inicial do plano ainda não entra no saldo anterior do Livro Razão (pendência do ADR 0166). **Atualização:** resolvido pelo ADR 0167 (abertura do Razão); a tela passou a nomear as naturezas patrimoniais e a mostrar o total delas (ADR 0148).
