---
codigo: AP-034
titulo: Parcelas de Acordo
resumo: Parcelas dos acordos ativos, das vencidas para as pagas. Cada parcela é uma conta a receber: a baixa é feita nos lançamentos ou pelo boleto.
categoria: cobranca
rota: /cobranca/parcelas-de-acordo
menu: Cobrança > Parcelas de Acordo
icone: list-outline
situacao: funciona
palavras: parcelas de acordo, cobrança, AP-034
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Cobrança > Parcelas de Acordo]].

```tela parcelas-de-acordo
rota: /cobranca/parcelas-de-acordo
condominio: residencial-jardim-das-acacias
legenda: Tela Parcelas de Acordo
1: menu | Parcelas de Acordo no menu lateral
2: main :is(a,button):has-text("Acordos") | Botão {{Acordos}}
3: main table thead | Lista com Acordo, Parcela, Unidade, Vencimento, Valor e Situação
```

## Como usar

Os botões no alto da tela são {{Acordos}}.

### O que cada coluna mostra

- **Acordo**
- **Parcela**
- **Unidade**
- **Vencimento**
- **Valor**
- **Situação**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cobrança**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

