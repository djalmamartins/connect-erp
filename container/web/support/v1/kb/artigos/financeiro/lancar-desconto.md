---
codigo: AP-026
titulo: Lançar Desconto
resumo: Desconto numa conta a receber em aberto. O valor da conta diminui e fica registrado o antes, o depois e o motivo. Para perdoar a conta inteira, cancele o lançamento.
categoria: financeiro
rota: /financeiro/lancar-desconto
menu: Financeiro > Lançamentos > Lançar Desconto
icone: create-outline
situacao: funciona
palavras: lançar desconto, financeiro, AP-026
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Financeiro > Lançamentos > Lançar Desconto]].

```tela lancar-desconto
rota: /financeiro/lancar-desconto
condominio: residencial-jardim-das-acacias
legenda: Tela Lançar Desconto
1: menu | Lançar Desconto no menu lateral
2: main :is(a,button):has-text("Contas a receber") | Botão {{Contas a receber}}
3: main form[method=get] | Filtros: Buscar
4: main table thead | Lista com Vencimento, Conta, Unidade e Valor
```

## Como preencher

Depois de preencher, clique em {{Salvar}}. O sistema avisa no próprio campo o que precisa corrigir.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

