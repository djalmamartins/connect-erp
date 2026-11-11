# 0182 — Prestação de contas anual para a assembleia

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0164 (Balancete Anual), 0166 (prestação de contas mensal), 0167 (Balanço), 0177 (fundos),
  0178 (CSV) e 0180 (orçamento)

## Contexto

Na assembleia ordinária, o síndico presta contas do ano, como manda o Código Civil (art. 1.348, VIII). Os relatórios
do ano já existiam soltos:

- Balancete Anual;
- DRE;
- Balanço;
- previsão orçamentária.

O pacote mensal (AP-101) junta tudo, mas é de um mês só. Quem prepara a assembleia precisava juntar à mão doze pacotes
ou quatro relatórios.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/annual-accountability?condominium_id=&year=AAAA`, com
`format=csv`. O service é `AnnualAccountability`. Ele junta, só com os services que já existem:

- a **DRE do ano** por competência;
- o **Balanço** no fim do ano. No ano corrente, a data de corte é hoje;
- os **fundos** no ano, pelo Livro Razão das contas 8 (`Accountability::funds`): saldo no início do ano, entradas,
  saídas e saldo na data;
- o **previsto contra realizado** do ano inteiro (`Accountability::budgetSection` em dezembro, que dá o acumulado de
  janeiro a dezembro);
- a **inadimplência** na data, por unidade, sem nome de morador;
- os **meses fechados**, contra os esperados de janeiro até o mês da data de corte. Mês reaberto não conta como
  fechado.

### Avisos

- **Meses ainda abertos:** os números podem mudar.
- **Balanço que não fecha.**
- **Contas vencidas:** há contas vencidas em aberto.

### CSV

O CSV segue o padrão do mensal (ADR 0178):

- **Seções:** Resumo, DRE, Balanço (Ativo, Passivo e Patrimônio Líquido), Fundos, Orçamento, Inadimplência e Avisos.
- **Limite e auditoria:** até 30 exportações por hora, com auditoria `export`.
- **Formato:** fórmulas neutralizadas, BOM UTF-8, separador `;` e linhas CRLF.

### Permissões e escopo

- **Permissões:** `erp.entries.view` e `erp.chart.view`, como o pacote mensal.
- **Condomínio:** um só, obrigatório e do escopo; fora do escopo dá 404.
- **Ano:** ano futuro ou fora de 2000–2100 dá 422.

## Consequências

- **Testes.** `AnnualAccountabilityTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2) confere:
  - a DRE do ano, sem o ano anterior nem outro condomínio;
  - o Balanço fechando;
  - o Disponível;
  - o fundo de reserva no ano;
  - o orçamento do ano inteiro (−1.500,00, −30%);
  - os 11 meses esperados até novembro, com só setembro fechado, porque o mês reaberto não conta;
  - os avisos e a inadimplência;
  - que um ano já encerrado tem a data de corte em 31/12;
  - as seções e as linhas do CSV;
  - a neutralização de fórmula;
  - o escopo e as entradas inválidas;
  - o controller.
- **Rota e documentação no patch:** a rota entra no `index.php` e na `docs/api/openapi.yaml`, dentro do patch.
- **Sem migration nem tabela nova.**
- **Sem tela nova**, então sem artigo na Central de Ajuda. Quando houver tela, entra a página no catálogo e o artigo.
- **Publicação:** a publicação congelada (ADR 0179) é só do pacote mensal. Congelar o anual fica para um próximo
  ciclo, se for pedido.
