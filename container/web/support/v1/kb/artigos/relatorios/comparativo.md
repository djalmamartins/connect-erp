---
codigo: AP-050
titulo: Relatório Comparativo
resumo: Duas faixas de competência lado a lado, por conta do plano, com a diferença e a variação.
categoria: relatorios
rota: /relatorios/comparativo
menu: Relatórios > Financeiros > Comparativo
icone: document-text-outline
situacao: funciona
palavras: comparativo, relatórios, AP-050
rascunho: nao
---

## Para que serve

Duas faixas de competência lado a lado, por conta do plano, com a diferença e a variação. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/comparison`.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Comparativo]].

```tela comparativo
rota: /relatorios/comparativo
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório Comparativo
1: menu | Comparativo no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: Período A e Período B
4: main table thead | Colunas: Código, Conta, Natureza, Período A, Período B, Diferença, Variação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **Período A e Período B**: duas faixas de competência para comparar; o padrão compara este ano com o mesmo intervalo do ano passado.
2. Clique em {{Gerar}}.
3. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Código**
- **Conta**
- **Natureza**
- **Período A**
- **Período B**
- **Diferença**
- **Variação**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.entries.view** e **erp.chart.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
