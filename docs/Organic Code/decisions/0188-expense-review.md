# 0188 — Conferência de despesas para o conselho fiscal

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0022 (lançamentos, AP-020), 0083 (faixa de competências), 0179 e 0183 (prestação de contas
  publicada), 0185 (qualidade do cadastro)

## Contexto

O conselho fiscal aprova as contas do síndico. Hoje ele confere as despesas uma a uma, no Razão ou na pasta de
documentos. Os erros mais comuns, e também os sinais de fraude, têm padrão: a mesma conta paga duas vezes, pagamento
sem fornecedor, pagamento sem nota e um gasto muito acima do normal. O sistema já tem os dados para apontar esses
casos.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/expense-review?condominium_id=&from=AAAA-MM&to=AAAA-MM`. O service é
`ExpenseReview`. A faixa de competências usa a mesma regra dos relatórios gerenciais (`ManagementReports::competences`,
no máximo 24 meses).

### O que é conferido

Só despesas válidas (ativas e não excluídas) do condomínio, por competência:

- **Possível duplicidade:** despesas do mesmo fornecedor com o mesmo valor e vencimentos a até 7 dias um do outro (os
  vencimentos próximos se encadeiam no mesmo grupo), ou com o mesmo número de documento, comparado sem pontuação e sem
  diferença de maiúsculas. Despesa sem fornecedor não entra nesta conferência.
- **Paga sem fornecedor:** a quem foi pago?
- **Paga sem número de documento** (nota fiscal ou recibo); documento em branco conta como sem documento.
- **Pico:** conta de despesa cujo total no mês passa de 1,5 vez a média dos 12 meses anteriores, com estas condições:
  - pelo menos 3 meses com valor nesses 12;
  - a média é só dos meses com valor, para que um condomínio com histórico curto não acuse pico falso;
  - diferença de R$ 100,00 ou mais.

  O histórico pode vir de antes da faixa pedida.

São indícios, não acusações: o relatório aponta e o conselho confere. Os limites (7 dias, 1,5 vez, R$ 100,00, 3 meses)
são constantes do service. Se o Djalma quiser outros valores, viram configuração do condomínio.

### Resposta

- Um resumo com a contagem de cada conferência.
- Cada lista vem limitada a 200 itens, com o total e a marca `truncated`.
- Cada despesa traz: id, competência, vencimento, pagamento, valor, descrição, número do documento, conta e
  fornecedor (id e nome fantasia, ou a razão social).
- Do fornecedor, o documento, os dados bancários e o Pix não saem.

### Permissões e escopo

- **Permissão:** exige `erp.access` e `erp.entries.view`, a mesma de quem já vê os lançamentos.
- **Escopo:** um condomínio, obrigatório; fora do escopo dá 404 antes de consultar.
- **Entradas inválidas:** sem condomínio ou competências inválidas, invertidas ou acima de 24 meses dá 422.
- **Consultas:** parametrizadas, sem parâmetro repetido.

## Consequências

- **Testes.** `ExpenseReviewTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2) confere:
  - o resumo e as quatro listas, com cancelada, excluída, outro condomínio, receita, em aberto e fora do período
    fora das conferências;
  - o pico pelo histórico de antes do período;
  - que o documento e o Pix do fornecedor não saem;
  - as regras puras de duplicidade (encadeamento, 8 dias, outro fornecedor, sem fornecedor, documento sem pontuação)
    e de pico (histórico curto, diferença mínima, limite de 1,5 vez);
  - o escopo, as entradas inválidas, o controller e a rota.
- **Rota e documentação no patch:** a rota entra no `index.php` e na OpenAPI, dentro do patch.
- **Sem migration nem tabela nova.**
- **Sem tela nova**, então sem artigo na Central de Ajuda. A prestação de contas ou o painel do conselho podem mostrar
  o resumo quando a frente de layout quiser.
