---
codigo: AP-079
titulo: Relatório Balancete Verificação
resumo: Lançamentos válidos por conta do plano, pela competência: o previsto, o que já foi pago ou recebido e o que segue em aberto.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/balancete-verificacao
menu: Prestação de contas > Balancete Verificação
icone: document-text-outline
situacao: funciona
palavras: balancete verificação, prestação de contas, AP-079
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Prestação de contas > Balancete Verificação]].

```tela balancete-verificacao
rota: /prestacao-de-contas/balancete-verificacao
condominio: residencial-jardim-das-acacias
legenda: Tela Balancete Verificação
1: menu | Balancete Verificação no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form[method=get] | Filtros: De (competência), Até (competência)
4: main table thead | Lista com Conta, Previsto, Realizado e Em aberto
```

## Como gerar o relatório

1. Escolha de (competência) e até (competência).
2. Clique em {{Gerar}}.
3. Confira o resultado na tela.

### O que cada coluna mostra

- **Conta**
- **Previsto**
- **Realizado**
- **Em aberto**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Prestação de contas**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

