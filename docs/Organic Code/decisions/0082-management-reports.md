# 0082 — Relatórios gerenciais: consumo, centros de custo, comparativo, demonstrativos, rateio, lançamentos e boletos

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Regras: issue #46

## Contexto

Depois dos relatórios de cobrança (ADR 0081), faltavam os demais relatórios do catálogo: consumo
(AP-045/046), centros de custo e comparativo (AP-049/050), demonstrativos e extrato (AP-052 a AP-056;
o AP-055 foi removido do catálogo), rateio (AP-062 a AP-067), e lançamentos e boletos (AP-068 a
AP-070). A coordenação pediu: só consulta, escopo por condomínio e o mesmo CSV seguro do 0081. As
fichas são esqueletos; as definições abaixo são nossas e conservadoras.

## Decisão

- **`ManagementReports`**, somente leitura e sem migration, calculado dos lançamentos válidos (status
  active, não excluídos), das leituras, dos boletos e das transferências:
  - **AP-045/046 Consumo** (`/reports/consumption`, `by=unit`): leituras, consumo, valor e quantas
    foram cobradas, por tipo, no geral ou por unidade;
  - **AP-049 Centros de custo:** previsto (competência), realizado (pago) e em aberto, por centro de
    custo e natureza. Lançamento sem centro aparece como "Sem centro de custo";
  - **AP-050 Comparativo:** duas faixas de competência (A e B) por conta do plano, com a diferença e o
    percentual (sem percentual quando a base é zero);
  - **AP-052/053/054 Demonstrativos** (`/reports/financial-statement?view=`):
    - `accounts`: por conta, com previsto, realizado e em aberto;
    - `analytic`: cada lançamento sob a conta;
    - `situation`: por natureza e situação (pago, a vencer, vencido, cancelado). Vencer hoje ainda é
      "a vencer";
    - o resultado é receitas − despesas, sem os cancelados;
  - **AP-056 Extrato da conta** (`/reports/account-statement`): saldo anterior (saldo inicial + o que
    foi movimentado antes do período), os lançamentos pagos e as transferências (ADR 0057) do período,
    com o saldo corrido e o saldo final;
  - **AP-062 a AP-067 Rateio** (`/reports/apportionment?view=`), sobre as despesas da competência pela
    fração ideal:
    - `unit` (AP-062): por unidade;
    - `compare` (AP-063): duas competências;
    - `unit_account` (AP-064): unidade × conta;
    - `unit_entries` (AP-065): cada despesa para uma unidade;
    - `entries` (AP-066): despesa × unidade;
    - `account` (AP-067): por conta, com a maior e a menor parte;
    - cada despesa é dividida por `Invoices::apportion`, com o centavo que sobra pelo maior resto, e a
      soma das partes é sempre a despesa;
    - **unidade ativa sem fração ideal bloqueia o rateio:** a lista das unidades sem fração aparece, e
      nada é calculado. O rateio é só informativo: não gera fatura;
  - **AP-068/069 Lançamentos** (`/reports/entries`, `detailed=1`): filtros por natureza e situação.
    O detalhado traz fornecedor, centro de custo, conta bancária, documento e a origem: fatura, acordo,
    cobrança extra ou multa, lançamento fixo, importação ou manual;
  - **AP-070 Boletos:** por vencimento no período e situação, com o valor emitido e o pago.
- **Limites:** até 24 competências, ou 366 dias nos relatórios por data, e até 5.000 linhas. Acima
  disso, o relatório pede para reduzir a faixa, em vez de cortar em silêncio.
- **Escopo:** um condomínio ou todos os do usuário. O rateio exige um condomínio; o extrato exige a
  conta bancária de um condomínio do escopo. Fora do escopo responde 404.
- **CSV** (`?format=csv`): o mesmo do ADR 0081 (`CollectionReports::csv` e `csvCell`). Toda célula que
  começa com `=`, `+`, `-` ou `@` ganha apóstrofo, inclusive os valores negativos do extrato. A
  exportação fica na auditoria.
- **Permissões existentes:** cada relatório exige a permissão de leitura dos dados que mostra (por
  exemplo, `erp.meter_readings.view` no consumo, `erp.cost_centers.view` nos centros de custo,
  `erp.bank_charges.view` nos boletos e `erp.units.view` no rateio).

## Fora deste ADR

- Os gráficos (AP-102 a AP-109) vêm no próximo ciclo, sobre estas mesmas consultas.
- Os relatórios cadastrais e administrativos (AP-084 a AP-101) seguem pela ordem do backlog.

## Consequências

- Telas (layout): cada relatório usa a sua rota, com "Exportar CSV" na mesma URL com `format=csv`.
