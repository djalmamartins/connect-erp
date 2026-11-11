# 0186 — Previsão de caixa do condomínio

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0027 (lançamentos fixos, AP-021), 0165 (Saldos de Contas, AP-073), 0170 (aplicações), 0181
  (conferência antes de fechar)

## Contexto

Os relatórios mostravam o que já aconteceu: Razão, Balanço e prestação de contas. Para decidir o futuro, o síndico
precisa saber se vai faltar dinheiro nos próximos meses. Por exemplo: se pode antecipar uma obra, se precisa de uma
taxa extra, se deve resgatar a aplicação. Os dados já estavam no sistema, nas contas em aberto e nos lançamentos fixos,
mas espalhados.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/cash-forecast?condominium_id=&months=`. O service é `CashForecast`.
A previsão vai de 1 a 12 meses, com padrão de 6.

### Como cada parte é calculada

- **Saldo de hoje:** as contas bancárias do condomínio pela mesma conciliação do AP-073
  (`AccountingReports::accountBalances`), com a aplicação incluída.
- **Por mês:**
  - entradas: contas a receber em aberto que vencem no mês. No mês corrente, só a partir de hoje;
  - saídas: contas a pagar em aberto que vencem no mês;
  - lançamentos fixos ativos vigentes no mês que ainda não geraram o lançamento daquela competência, pelo valor e
    pelo dia do modelo. O fixo já gerado entra como conta em aberto, então não conta duas vezes. O fixo do mês
    corrente com dia já passado não entra.
- **Vencidos:** o a receber vencido não entra na previsão, porque pode não ser pago; aparece à parte. O a pagar
  vencido entra no primeiro mês, porque precisa ser pago, e também aparece à parte.
- **Saldo:** saldo de abertura, entradas, saídas, líquido e saldo no fim de cada mês, além do menor saldo do período.
- **Avisos:**
  - o primeiro mês com saldo negativo;
  - a existência de contas a pagar vencidas.

### Hipótese

O a receber em dia entra pelo valor cheio. Não há taxa de inadimplência estimada, porque ela é uma decisão de negócio
e ainda não foi aprovada. Se o Djalma quiser, é um parâmetro a mais no `project()`.

### Permissões e escopo

- **Permissão:** exige `erp.entries.view`.
- **Escopo:** um condomínio, obrigatório; fora do escopo dá 404.
- **Entradas inválidas:** meses fora de 1–12 ou sem condomínio dá 422.
- **Consultas:** parametrizadas, sem parâmetro repetido.

## Consequências

- **Testes.** `CashForecastTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2) confere:
  - o saldo de hoje e os vencidos à parte;
  - que conta cancelada, conta excluída e outro condomínio não entram;
  - novembro começando hoje, com o fixo do dia já passado fora e o vencido a pagar no primeiro mês;
  - dezembro sem contar duas vezes o fixo já gerado;
  - janeiro depois do fim de um fixo;
  - o menor saldo e o aviso no primeiro mês negativo;
  - o padrão de 6 meses;
  - as janelas na virada do ano e em fevereiro;
  - o escopo, as entradas inválidas e o controller.
- **Rota e documentação no patch:** a rota entra no `index.php` e na OpenAPI, dentro do patch.
- **Sem migration nem tabela nova.**
- **Sem tela nova**, então sem artigo na Central de Ajuda. O dashboard ou o painel do condomínio podem mostrar o gráfico
  quando a frente de layout quiser.
