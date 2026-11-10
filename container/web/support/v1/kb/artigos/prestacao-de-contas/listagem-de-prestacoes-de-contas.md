---
codigo: AP-094
titulo: Listagem de prestações de contas
resumo: Fechamentos mensais (prestações de contas) nas competências, com o resultado e as reaberturas.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/listagem-de-prestacoes-de-contas
menu: Prestação de contas > Listagem de prestações de contas
icone: document-text-outline
situacao: funciona
palavras: listagem de prestações de contas, prestação de contas, AP-094
rascunho: nao
---

## Para que serve

Fechamentos mensais (prestações de contas) nas competências, com o resultado e as reaberturas. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/closings`.

## Onde fica

No menu lateral, abra [[Prestação de contas > Listagem de prestações de contas]].

```tela listagem-de-prestacoes-de-contas
rota: /prestacao-de-contas/listagem-de-prestacoes-de-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Listagem de prestações de contas
1: menu | Listagem de prestações de contas no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: Competência inicial e final
4: main table thead | Colunas: Condomínio, Competência, Situação, Receitas, Despesas, Resultado, Fechado em, Reaberto em
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **Competência inicial e final**: a faixa de competências (no máximo 24); o padrão vai de janeiro até o mês atual.
2. Clique em {{Gerar}}.
3. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Condomínio**
- **Competência**
- **Situação**
- **Receitas**
- **Despesas**
- **Resultado**
- **Fechado em**
- **Reaberto em**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.closing.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
