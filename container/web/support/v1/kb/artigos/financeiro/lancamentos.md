---
codigo: AP-020
titulo: Lançamentos: receitas e despesas
resumo: Todas as receitas e despesas do condomínio ficam aqui, com a conta do plano, o centro de custo, o vencimento e o pagamento. É a base dos demonstrativos, do balancete e da prestação de contas.
categoria: financeiro
rota: /financeiro/lancamentos
menu: Financeiro > Lançamentos > Lançamentos
icone: list-outline
situacao: funciona
relacionados: lancamentos-fixos, lancar-despesa, lancar-receita, lancar-desconto
palavras: lançamentos, financeiro, AP-020, receita, despesa, baixa, pagamento, competência
rascunho: nao
---

Todas as receitas e despesas do condomínio ficam aqui, com a conta do plano, o centro de custo, o vencimento e o pagamento. É a base dos demonstrativos, do balancete e da prestação de contas.

## Onde fica

No menu lateral, abra [[Financeiro > Lançamentos > Lançamentos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela lancamentos
rota: /financeiro/lancamentos
condominio: residencial-jardim-das-acacias
legenda: Tela Lançamentos
1: menu | Lançamentos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo lançamento") | Botão {{Novo lançamento}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Natureza e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo lançamento}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Natureza**, **Competência**, **Descrição**, **Valor**, **Conta do plano de contas**, **Vencimento** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela lancamentos-novo-lancamento
rota: /financeiro/lancamentos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo lançamento
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="nature"] | Natureza (obrigatório)
3: main form:not([method=get]) [name="competence"] | Competência (obrigatório)
4: main form:not([method=get]) [name="description"] | Descrição (obrigatório)
5: main form:not([method=get]) [name="amount_cents"] | Valor (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Natureza** (obrigatório): Opções: Receita e Despesa.
- **Competência** (obrigatório): AAAA-MM.
- **Descrição** (obrigatório).
- **Valor** (obrigatório).
- **Conta do plano de contas** (obrigatório).
- **Centro de custo**.
- **Vencimento** (obrigatório).
- **Pago em**: Vazio enquanto estiver em aberto.
- **Conta bancária**: Obrigatória quando o lançamento está pago.
- **Fornecedor**.
- **Unidade**: Unidade que deve a receita (faturas preenchem sozinhas).
- **Nº do documento**: Nota fiscal, recibo ou boleto.
- **Observações**.
- **Situação** (obrigatório): Opções: Válido e Cancelado.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Natureza** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Descrição**, **Condomínio**, **Natureza**, **Competência**, **Valor**, **Vencimento**, **Pago em** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

> Para dar baixa, edite o lançamento e informe **Pago em** e a **Conta bancária**.

> Use a competência (mês de referência) certa: é ela que decide em que mês o valor aparece nos relatórios.

>! Mês fechado no Fechamento Mensal não aceita lançamento novo nem alteração até ser reaberto.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Lançamentos fixos (recorrentes)](artigo:lancamentos-fixos), [Lançar uma despesa](artigo:lancar-despesa) e [Lançar uma receita](artigo:lancar-receita).
