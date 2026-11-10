---
codigo: AP-053
titulo: Relatório Demonstrativo Analítico
resumo: Cada lançamento das competências, agrupado pela conta do plano.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/demonstrativo-analitico
menu: Prestação de contas > Demonstrativo Analítico
icone: document-text-outline
situacao: funciona
palavras: demonstrativo analítico, prestação de contas, AP-053
rascunho: nao
---

## Para que serve

Cada lançamento das competências, agrupado pela conta do plano. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/financial-statement`.

## Onde fica

No menu lateral, abra [[Prestação de contas > Demonstrativo Analítico]].

```tela demonstrativo-analitico
rota: /prestacao-de-contas/demonstrativo-analitico
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório Demonstrativo Analítico
1: menu | Demonstrativo Analítico no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: Competência inicial e final
4: main table thead | Colunas: Código, Conta, Natureza, Descrição, Unidade, Competência, Vencimento, Pagamento, Situação, Valor
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **Competência inicial e final**: a faixa de competências (no máximo 24); o padrão vai de janeiro até o mês atual.
2. Clique em {{Gerar}}.
3. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Código**
- **Conta**
- **Natureza**
- **Descrição**
- **Unidade**
- **Competência**
- **Vencimento**
- **Pagamento**
- **Situação**
- **Valor**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.entries.view** e **erp.chart.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
