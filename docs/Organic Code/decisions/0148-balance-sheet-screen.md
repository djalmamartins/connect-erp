# ADR 0148 — Tela do Balanço Patrimonial ligada à API

- Situação: aceita (10/11/2026)
- Contexto: serviço e API do ADR 0167 (`BalanceSheet`, `GET /api/v1/reports/balance-sheet`); tela de saldo
  inicial do ADR 0146 (AP-161)

## Contexto

O ADR 0167 entregou o Balanço Patrimonial (AP-081) como API e CSV, mas a página do catálogo seguia com a
demonstração (Conta, Saldo anterior, Entradas, Saídas, Saldo final), que não corresponde ao relatório real.
O mesmo ADR trouxe as naturezas patrimoniais ao plano de contas e o saldo inicial como abertura do Livro
Razão, e a tela AP-161 ainda mostrava só "Receita" e "Despesa".

## Decisão

- `BalanceSheetScreens` lê pelo mesmo serviço da API e entrega `pages/reports/balance-sheet`:
  - pede o condomínio da barra superior (o balanço é de um condomínio só);
  - filtro "Data do balanço", padrão hoje; data inválida ou futura volta para hoje;
  - selo "Fechado" ou "Diferença de R$ …", os avisos do serviço e o aviso de corte por limite;
  - totais de ativo, passivo, patrimônio líquido, passivo + PL e diferença;
  - uma tabela por grupo com a árvore do plano (sintéticas em negrito), as contas bancárias logo abaixo do
    Disponível e a coluna "Vencido" em contas a receber e a pagar;
  - as limitações do cálculo, com link para o saldo inicial (AP-161);
  - "Exportar CSV" aponta para a própria API (`format=csv`, com limite e auditoria de exportação dela).
- Permissão: menu com `erp.entries.view`; os dados exigem também `erp.chart.view`, como a API. Sem banco ou
  sem permissão, a tela segue com a demonstração.
- AP-161: as naturezas ganham nome (`SettingsScreens::NATURE_LABELS`: ativo, passivo, patrimônio líquido) na
  tabela e no seletor de contas, e o cabeçalho mostra o total de cada natureza patrimonial quando houver.

## Consequências

- O artigo da Central de Ajuda do AP-081 passa a `situacao: funciona` e descreve as colunas reais; os prints
  ainda são da demonstração até rodar `bash tools/help/prints.sh` no Mac. O artigo do AP-161 deixa de dizer
  que o saldo inicial não entra no Razão.
- Testes: `BalanceSheetScreensIntegrationTest` (balanço do condomínio da barra, pedido de condomínio, sem
  permissão) e o smoke da tela (árvore, bancos, vencido, CSV, erro, pedido de condomínio, data).
- Sem escrita nova: a tela é somente leitura.
