# 0189 — Simulação da taxa condominial a partir da previsão

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** AP-163 (previsão orçamentária), ADR 0187 (proposta de previsão), AP-062 (rateio pela fração
  ideal), AP-102 (taxa de inadimplência dos gráficos)

## Contexto

Depois de aprovar a previsão orçamentária (ADR 0187), a assembleia decide a taxa condominial: quanto cada unidade paga
por mês. Na prática o síndico faz a conta numa planilha: a despesa do ano ÷ 12, mais o fundo de reserva, mais uma margem
para quem não paga, e depois o rateio pela fração ideal. O sistema já tem a previsão, as frações, o que cada unidade
pagou no último mês e a inadimplência. Por isso pode fazer a conta.

## Decisão

Rota nova, somente leitura: `GET /api/v1/budgets/fee-simulation?condominium_id=&year=&reserve_percent=&delinquency_percent=&split=`.
O service é `FeeSimulation`.

### A conta

- **Necessidade do mês:** a despesa prevista no ano ÷ 12.
- **Fundo de reserva:** de 0% a 30%, sobre a necessidade.
- **Margem de inadimplência:** de 0% a 50%; a cobrança é dividida por (1 − %), para que o que entra cubra a
  necessidade.
- **Arredondamento:** a taxa total do mês é arredondada ao centavo.
- **Hipótese conservadora:** as outras receitas previstas não abatem nada; a taxa cobre a despesa toda. A receita
  prevista aparece na resposta, só como informação.
- **Previsão parcial:** se a previsão de despesa tem menos de 12 meses preenchidos, a conta continua sendo o ano ÷ 12,
  com o aviso `partial_budget`.

### Rateio

- **`fraction` (padrão):** pela fração ideal das unidades ativas. Se alguma não tem fração, o rateio fica bloqueado,
  como no AP-062, e a resposta lista quais estão sem fração.
- **`equal`:** em partes iguais.
- **Centavos:** o que sobra vai pelo maior resto (`Invoices::apportion`); a soma das partes é a taxa total.

### Comparação e sugestão

- **Último mês completo:** cada unidade traz o que foi cobrado dela, por competência, só contas a receber válidas, e a
  variação. Esse valor pode incluir água, gás e outras cobranças da unidade; a resposta diz qual competência foi usada.
  Sem cobrança nesse mês, vem o aviso `no_billing_last_month`.
- **Inadimplência observada (sugestão para a margem):** nas contas a receber com vencimento nos 12 meses completos
  anteriores, o vencido em aberto hoje ÷ o faturado (`ChartReports::rate`).
  - Só é sugerida com faturamento em pelo menos 3 desses meses.
  - Com histórico curto (um condomínio novo, ou o laboratório, que começa em janeiro de 2026), vem `null` e o aviso
    `short_history`. Assim um ou dois meses atípicos não viram margem.

### Permissões e escopo

- **Permissão:** exige `erp.access`, `erp.settings.view` e `erp.entries.view`, as mesmas da proposta de previsão.
- **Escopo:** um condomínio, obrigatório; fora do escopo dá 404 antes de consultar.
- **Entradas inválidas:** ano fora de 2000 a hoje + 5, percentuais fora da faixa ou rateio desconhecido dá 422.
- **Sem previsão:** sem despesa prevista no ano, a resposta vem `blocked`, com a mensagem.
- **Dados expostos:** saem as unidades (rótulo e fração) e os valores. Nenhum dado de morador.
- **Consultas:** parametrizadas.

## Consequências

- **Testes.** `FeeSimulationTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2) confere:
  - **condomínio com histórico como o do laboratório** (faturamento de julho a outubro):
    - a conta com reserva e margem;
    - o rateio pela fração e o centavo pelo maior resto;
    - as unidades inativa e excluída fora;
    - o cobrado no último mês, sem cancelada, excluída nem despesa;
    - a inadimplência observada, sem o que vence antes ou depois da base nem outro condomínio;
    - as partes iguais;
  - **condomínio com histórico curto:** só 2 meses, sem sugestão, previsão parcial, fração faltando, partes iguais
    sem cobrança no último mês;
  - **ano sem previsão;**
  - a regra pura, o escopo, as entradas inválidas, o controller e a rota.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration nem tabela nova.**
- **Sem tela nova**, então sem artigo na Central de Ajuda. A tela de previsão orçamentária pode mostrar a simulação ao
  lado da proposta quando a frente de layout quiser.
- **Fora de escopo:** gravar a taxa nova (no tipo de fatura ou na regra de cobrança) fica para um ciclo próprio, porque
  mexe em cobrança.
