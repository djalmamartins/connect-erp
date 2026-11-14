---
codigo: AP-030
titulo: Fechar o mês (fechamento mensal)
resumo: O fechamento trava a competência depois de conferida: ninguém lança nem altera nada daquele mês. Confira o resumo (receitas, despesas, resultado e o que está em aberto), feche e, se precisar corrigir, reabra informando o motivo.
categoria: financeiro
rota: /financeiro/fechamento-mensal
menu: Financeiro > Fechamento > Fechamento Mensal
icone: lock-closed-outline
situacao: funciona
relacionados: listagem-de-prestacoes-de-contas, demonstrativo, outros-lancamentos, pagamentos-bancos
palavras: fechamento mensal, financeiro, AP-030
rascunho: nao
---

O fechamento trava a competência depois de conferida: ninguém lança nem altera nada daquele mês. Confira o resumo (receitas, despesas, resultado e o que está em aberto), feche e, se precisar corrigir, reabra informando o motivo.

## Onde fica

No menu lateral, abra [[Financeiro > Fechamento > Fechamento Mensal]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela fechamento-mensal
rota: /financeiro/fechamento-mensal
condominio: residencial-jardim-das-acacias
legenda: Tela Fechamento Mensal
1: menu | Fechamento Mensal no menu lateral
2: main :is(form[method=get], .erp-filters) | Filtros: Competência
3: main table thead | Colunas da lista
```

## Pesquisar e filtrar

Use os filtros no alto da lista: **Competência**.
Depois clique em {{Ver competência}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Condomínio**, **Situação**, **Receitas**, **Despesas**, **Resultado**, **Em aberto** e **Lançamentos**.

## Como fechar e reabrir

1. Escolha a **Competência** e confira o resumo de cada condomínio.
2. Clique em {{Fechar competência}} na linha do condomínio.
3. Para corrigir depois, clique em {{Reabrir competência}} e informe o motivo. A reabertura aparece na listagem de prestações de contas.

>! Feche só depois de conferir as baixas e os lançamentos do mês: o fechamento é a base da prestação de contas.

> Ao fechar, o sistema gera sozinho o [link da prestação de contas para os moradores](artigo:livro-caixa) e manda o e-mail às unidades do condomínio. Ao fechar dezembro, sai também o livro anual. O prazo para os moradores receberem é o 5º dia útil do mês seguinte; perto dele, quem pode fechar o mês recebe um aviso no sistema.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Listagem de prestações de contas](artigo:listagem-de-prestacoes-de-contas), [Demonstrativo (regime de caixa)](artigo:demonstrativo) e [Transferir entre contas bancárias](artigo:outros-lancamentos).
