# 0199 — Acompanhamento da arrecadação

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0157 (dashboard com dados reais), 0198 (conferência do faturamento), 0186 (previsão de caixa),
  0039 (inadimplência e acordos)

## Contexto

Ao longo do mês, o síndico pergunta à administradora: "quanto da taxa já entrou?", "o pessoal está pagando em dia?",
"este mês está pior que o anterior?". O dashboard (ADR 0157) mostra a arrecadação e a inadimplência da competência, mas
não o ritmo de pagamento nem a comparação com os meses anteriores. O relatório de inadimplentes lista unidades, e não
responde à pergunta agregada.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/collection-progress?condominium_id=&competence=AAAA-MM&as_of=`. O
service é `CollectionProgress`. Conta só as contas a receber válidas (ativas, não excluídas) das unidades do condomínio, por
competência. Receita sem unidade (rendimento de aplicação, multa avulsa) fica de fora: não é arrecadação dos
moradores e distorceria o % em dia (ela aparece na conferência do faturamento, ADR 0198). A situação é olhada numa data de referência (`as_of`, padrão hoje): pagamento depois dela conta como em
aberto, para dar para ver como a competência estava num dia.

### O que mostra

- **Totais:** cobrado, recebido até a data, em aberto, vencido (em aberto com vencimento antes da data), % recebido e
  % em dia.
- **Faixas de pagamento em relação ao vencimento** (regra pura `bucket`): em dia (até o vencimento), 1–5, 6–15, 16–30,
  31–60, mais de 60 dias depois, e em aberto. Cada faixa tem valor, quantidade e % do cobrado.
- **Competência anterior:** as mesmas faixas, e a diferença em pontos no % em dia.
- **Tendência dos últimos 6 meses** (a competência e as 5 anteriores): % em dia e % recebido de cada mês com cobrança.

### Histórico curto e mês sem cobrança

- **Histórico curto:** meses sem cobrança ficam de fora. Com menos de 2 meses cobrados (condomínio novo, ou o
  laboratório no começo do ano), não há tendência e vem o aviso `short_history`.
- **Mês sem cobrança:** vem o aviso `no_billing`, e os percentuais vêm `null`, não zero.

### Dados, permissões e escopo

- **Dados expostos:** só números agregados; nenhuma unidade nem morador. Para ver as unidades há o relatório de
  inadimplentes e a mesa de cobrança.
- **Permissão:** exige `erp.access` e `erp.entries.view`.
- **Escopo:** um condomínio, obrigatório; fora do escopo dá 404 antes de consultar.
- **Entradas inválidas:** competência ou data inválidas dão 422.
- **Consultas:** parametrizadas.

## Consequências

- **Testes.** `CollectionProgressTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2) confere:
  - os totais e as faixas, com o pagamento depois da data de referência em aberto;
  - que cancelada, excluída, despesa e outro condomínio não entram;
  - o mês anterior e a diferença em pontos;
  - a tendência de 6 meses, com meses sem cobrança de fora;
  - o histórico curto e o mês sem cobrança;
  - os limites de cada faixa;
  - o escopo, as entradas inválidas, o controller e a rota.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration, sem tabela nova e sem gravação.**
- **Sem tela nova neste patch.** O painel do condomínio pode mostrar o gráfico de faixas e a tendência quando a frente
  de layout quiser; quando a tela existir, ela ganha o artigo da Central de Ajuda.
