---
codigo: AP-052
titulo: Relatório Demonstrativo Contas
resumo: Receitas e despesas por conta do plano: previsto, realizado e em aberto.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/demonstrativo-contas
menu: Prestação de contas > Demonstrativo Contas
icone: document-text-outline
situacao: funciona
palavras: demonstrativo contas, prestação de contas, AP-052
rascunho: nao
---

## Para que serve

Receitas e despesas por conta do plano: previsto, realizado e em aberto. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/financial-statement`.

## Onde fica

No menu lateral, abra [[Prestação de contas > Demonstrativo Contas]].

```tela demonstrativo-contas
rota: /prestacao-de-contas/demonstrativo-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório Demonstrativo Contas
1: menu | Demonstrativo Contas no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: Competência inicial e final
4: main table thead | Colunas: Código, Conta, Natureza, Lançamentos, Previsto, Realizado, Em aberto
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
- **Lançamentos**
- **Previsto**
- **Realizado**
- **Em aberto**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.entries.view** e **erp.chart.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
