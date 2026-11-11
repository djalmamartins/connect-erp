# 0201 — Comparativo de despesas entre condomínios

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0188 (conferência de despesas), 0189 (simulação da taxa), 0190 (gastos por fornecedor)

## Contexto

A administradora cuida de vários condomínios e o síndico pergunta: "a nossa limpeza está cara?". Hoje os relatórios
comparam um condomínio com ele mesmo: período contra período (ManagementReports) ou mês contra a média dele (ADR 0188).
Nada mostra que a limpeza da Aurora sai a R$ 50 por unidade enquanto os outros condomínios pagam R$ 30.

Comparar o total não serve, porque condomínio grande gasta mais. Comparar conta própria de um condomínio também não,
porque ela não tem par nos outros.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/expense-benchmark?from=AAAA-MM&to=AAAA-MM&tolerance=50`. O service é
`ExpenseBenchmark`. Olha os condomínios do escopo do usuário.

### Cálculo

- **Despesas:** ativas, não excluídas, por competência, na faixa (no máximo 12 meses).
- **Contas:** só as analíticas do plano padrão (`condominium_id` nulo), comuns a todos os condomínios.
- **Por unidade por mês** (regra pura `perUnitMonth`): total da conta ÷ unidades ativas ÷ meses em que o condomínio
  teve alguma despesa na faixa. Assim, condomínio que começou no meio da faixa não parece barato.
- **Mediana** (regra pura `median`) entre os condomínios com gasto na conta e pelo menos 3 meses de histórico.
- **Outlier:** acima da mediana mais que a tolerância (padrão 50%, de 10% a 500%), só se a conta tem pelo menos 3
  condomínios comparáveis.

### Casos especiais

- **Histórico curto** (menos de 3 meses): o condomínio aparece com `short_history`, fora da mediana e nunca outlier.
- **Sem unidade ativa:** fica de fora, com o aviso `no_units`.
- **Menos de 2 condomínios comparáveis:** aviso `not_enough_condominiums`.

### Dados e permissões

- **Dados expostos:** só nomes de condomínio, contas do plano e valores. Nenhuma unidade, morador ou fornecedor.
- **Permissão:** exige `erp.access` e `erp.entries.view`; sem uma delas, 403 (fail-closed).
- **Entrada inválida:** competência, faixa ou tolerância inválida dá 422.
- **Consultas:** parametrizadas; a lista de condomínios usa marcadores `?`.

## Consequências

- **Testes.** `ExpenseBenchmarkTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2) confere:
  - por unidade por mês, mediana, outlier e ordem;
  - unidade inativa ou excluída de fora;
  - conta própria e conta sintética de fora;
  - receita, cancelada, excluída e fora da faixa de fora;
  - condomínio fora do escopo sem peso na mediana e sem aparecer;
  - histórico curto marcado e fora da mediana;
  - conta com só 2 comparáveis sem outlier;
  - tolerância maior;
  - avisos `no_units` e `not_enough_condominiums`;
  - regras puras, validação, controller e rota.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration, sem tabela nova e sem gravação.**
- **Sem tela nova neste patch.** Quando a tela existir, ela ganha o artigo da Central de Ajuda.
