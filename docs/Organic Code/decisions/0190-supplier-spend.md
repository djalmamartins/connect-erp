# 0190 — Gastos por fornecedor

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0022 (lançamentos), 0087 (limite de exportação), 0188 (conferência de despesas), 0178 (CSV seguro)

## Contexto

O conselho fiscal e o síndico perguntam com quem o condomínio gasta e se depende demais de alguém. Por exemplo: "a
limpeza custou quanto este ano?", "o elevador subiu em relação ao semestre passado?", "metade da despesa está num
fornecedor só?". Hoje o Razão e a lista de lançamentos mostram as despesas uma a uma, sem somar por fornecedor. A
conferência de despesas (ADR 0188) aponta casos suspeitos, mas não dá a visão do todo.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/supplier-spend?condominium_id=&from=AAAA-MM&to=AAAA-MM`. Com
`format=csv`, baixa o mesmo relatório. O service é `SupplierSpend`. A faixa de competências segue a regra dos
relatórios gerenciais: no máximo 24 meses.

### O que mostra

Só despesas válidas (ativas e não excluídas) do condomínio, por competência.

- **Por fornecedor,** do maior para o menor:
  - os lançamentos, o total, o pago e o em aberto;
  - a participação no total com fornecedor;
  - as 3 contas de maior valor;
  - a primeira e a última competência e os meses com gasto;
  - o total no período anterior de mesmo tamanho e a variação.
- **Sem fornecedor:** fica à parte, fora do ranking e da participação. Já é um indício na conferência (ADR 0188).
- **Concentração:** a participação do maior e dos 5 maiores, e o índice HHI (soma dos quadrados das participações em %,
  de 0 a 10.000; acima de 2.500 é alta concentração).
- **Fornecedores que sumiram:** quantos tinham gasto no período anterior e não têm no atual.

### Histórico curto

Se o período anterior não tem nenhuma despesa (condomínio novo, ou o laboratório, que começa em jan/26), a variação de
todos vem `null`, com o aviso `no_previous_period`. Assim o relatório não mostra "+100%" para tudo. Se há despesa no
período anterior, mas não daquele fornecedor, o anterior vem 0 e a variação `null` (fornecedor novo).

### Dados do fornecedor

- **Saem:** o id, o nome fantasia (ou a razão social) e o documento mascarado (`CollectionReports::maskDocument`), porque
  o fornecedor pode ser pessoa física.
- **Não saem:** banco e Pix.

### CSV

- Mesmo conteúdo, com as células saneadas contra fórmula (`CollectionReports::csvCell`).
- Até 30 exportações por hora (`ReportExportLimit`, ADR 0087), com 429 acima disso.
- Auditado como `export` em `erp_reports`.

### Permissões e escopo

- **Permissão:** exige `erp.access` e `erp.entries.view`.
- **Escopo:** um condomínio, obrigatório; fora do escopo dá 404 antes de consultar.
- **Entradas inválidas:** competências inválidas, invertidas ou acima de 24 meses dão 422.
- **Consultas:** parametrizadas. A lista de ids de fornecedor usa marcadores `?`.
- **Tamanho:** até 200 fornecedores, com a marca `truncated`.

## Consequências

- **Testes.** `SupplierSpendTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2) confere:
  - o ranking, o pago e o em aberto, as participações, as contas principais, as competências e a variação;
  - que cancelada, excluída, receita, outro condomínio e fora do período não entram;
  - o sem fornecedor à parte, a concentração e o fornecedor que sumiu;
  - que documento inteiro, Pix e banco não saem;
  - o condomínio sem período anterior, com o aviso e a variação `null`;
  - o CSV com fórmula neutralizada;
  - as regras puras (período anterior, concentração), o escopo, as entradas inválidas, o controller e a rota.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration nem tabela nova.**
- **Sem tela nova**, então sem artigo na Central de Ajuda. O painel do conselho pode mostrar o ranking quando a frente
  de layout quiser.
