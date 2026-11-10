---
codigo: AP-026
titulo: Lançar um desconto numa conta a receber
resumo: Dá desconto numa conta a receber em aberto: o valor da conta diminui e fica registrado o valor antes, o depois e o motivo. Para perdoar a conta inteira, cancele o lançamento em vez de dar 100% de desconto.
categoria: financeiro
rota: /financeiro/lancar-desconto
menu: Financeiro > Lançamentos > Lançar Desconto
icone: create-outline
situacao: funciona
relacionados: lancar-receita, lancar-multa-avulsa, lancar-despesa, cobrancas-extras
palavras: lançar desconto, financeiro, AP-026
rascunho: nao
---

Dá desconto numa conta a receber em aberto: o valor da conta diminui e fica registrado o valor antes, o depois e o motivo. Para perdoar a conta inteira, cancele o lançamento em vez de dar 100% de desconto.

## Onde fica

No menu lateral, abra [[Financeiro > Lançamentos > Lançar Desconto]].

```tela lancar-desconto
rota: /financeiro/lancar-desconto
condominio: residencial-jardim-das-acacias
legenda: Tela Lançar Desconto
1: menu | Lançar Desconto no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Contas a receber") | Botão {{Contas a receber}}
3: main :is(form[method=get], .erp-filters) | Filtros: Buscar
4: main table thead | Colunas da lista
```

## Outros botões da tela

- {{Contas a receber}}: abre a lista completa de contas a receber.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Buscar**.
Depois clique em {{Buscar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Vencimento**, **Conta**, **Unidade** e **Valor**.

## Ações de cada linha

- **Abrir** mostra os detalhes do item.
- **Desconto** lança um desconto na conta a receber da linha.

## Como dar o desconto

1. Pesquise a conta pelo campo **Buscar** (unidade, descrição ou competência).
2. Na linha da conta, clique em {{Desconto}}.
3. Informe o valor do desconto e o motivo, e confirme.

>! O motivo é obrigatório e aparece na auditoria. Escreva de forma que outra pessoa entenda depois.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Lançar uma receita](artigo:lancar-receita), [Lançar uma multa avulsa](artigo:lancar-multa-avulsa) e [Lançar uma despesa](artigo:lancar-despesa).
