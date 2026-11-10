---
codigo: AP-084
titulo: Relatório de previsão orçamentária
resumo: O previsto de cada conta no ano contra o que foi lançado (competência), com a diferença e a variação.
categoria: relatorios
rota: /relatorios/relatorio-de-previsao-orcamentaria
menu: Relatórios > Financeiros > Relatório de previsão orçamentária
icone: document-text-outline
situacao: funciona
palavras: relatório de previsão orçamentária, relatórios, AP-084
rascunho: nao
---

## Para que serve

O previsto de cada conta no ano contra o que foi lançado (competência), com a diferença e a variação. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/budgets`.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Relatório de previsão orçamentária]].

```tela relatorio-de-previsao-orcamentaria
rota: /relatorios/relatorio-de-previsao-orcamentaria
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de previsão orçamentária
1: menu | Relatório de previsão orçamentária no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: Ano
4: main table thead | Colunas: Código, Conta, Natureza, Previsto, Lançado, Diferença, Variação
```

>! Este relatório usa os dados de **um condomínio só**. Escolha o condomínio na barra superior; com "Todos os condomínios", a tela pede a escolha.

## Como gerar o relatório

1. **Ano**: o ano do relatório.
2. Clique em {{Gerar}}.
3. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Código**
- **Conta**
- **Natureza**
- **Previsto**
- **Lançado**
- **Diferença**
- **Variação**

O previsto vem de [[Configurações > Financeiro e parâmetros > Previsão Orçamentária]]; o lançado soma os lançamentos ativos pela competência. Este relatório não tem exportação em CSV: use {{Imprimir}}.

## Imprimir

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.settings.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
