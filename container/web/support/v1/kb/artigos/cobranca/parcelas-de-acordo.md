---
codigo: AP-034
titulo: Parcelas de acordo
resumo: As parcelas dos acordos ativos, das vencidas para as pagas. Cada parcela é uma conta a receber: a baixa é feita pelo boleto (arquivo de retorno) ou nos lançamentos.
categoria: cobranca
rota: /cobranca/parcelas-de-acordo
menu: Cobrança > Parcelas de Acordo
icone: list-outline
situacao: funciona
relacionados: acordos, relatorio-de-acordos, boletos
palavras: parcelas de acordo, cobrança, AP-034
rascunho: nao
---

As parcelas dos acordos ativos, das vencidas para as pagas. Cada parcela é uma conta a receber: a baixa é feita pelo boleto (arquivo de retorno) ou nos lançamentos.

## Onde fica

No menu lateral, abra [[Cobrança > Parcelas de Acordo]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela parcelas-de-acordo
rota: /cobranca/parcelas-de-acordo
condominio: residencial-jardim-das-acacias
legenda: Tela Parcelas de Acordo
1: menu | Parcelas de Acordo no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Acordos") | Botão {{Acordos}}
3: main table thead | Colunas da lista
```

## Outros botões da tela

- {{Acordos}}: volta para a tela de acordos.

## O que a lista mostra

Colunas: **Acordo**, **Parcela**, **Unidade**, **Vencimento**, **Valor** e **Situação**.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cobrança**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Acordos](artigo:acordos), [Relatório de acordos](artigo:relatorio-de-acordos) e [Boletos](artigo:boletos).
