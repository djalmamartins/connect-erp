---
codigo: AP-022
titulo: Contas a pagar
resumo: Despesas em aberto do condomínio, por vencimento. Use para saber o que pagar na semana e para dar baixa depois do pagamento.
categoria: financeiro
rota: /financeiro/contas-a-pagar
menu: Financeiro > Contas e pagamentos > Contas a Pagar
icone: list-outline
situacao: funciona
relacionados: lancar-despesa, pagamentos-pendentes, contas-a-receber, pagamentos-bancos
palavras: contas a pagar, financeiro, AP-022, despesa, pagar, vencimento, baixa
rascunho: nao
---

Despesas em aberto do condomínio, por vencimento. Use para saber o que pagar na semana e para dar baixa depois do pagamento.

## Onde fica

No menu lateral, abra [[Financeiro > Contas e pagamentos > Contas a Pagar]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela contas-a-pagar
rota: /financeiro/contas-a-pagar
condominio: residencial-jardim-das-acacias
legenda: Tela Contas a Pagar
1: menu | Contas a Pagar no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova conta a pagar") | Botão {{Nova conta a pagar}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Nova conta a pagar}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Competência**, **Descrição**, **Valor**, **Conta do plano de contas**, **Vencimento** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela contas-a-pagar-nova-conta-a-pagar
rota: /financeiro/contas-a-pagar/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova conta a pagar
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="competence"] | Competência (obrigatório)
3: main form:not([method=get]) [name="description"] | Descrição (obrigatório)
4: main form:not([method=get]) [name="amount_cents"] | Valor (obrigatório)
5: main form:not([method=get]) [name="chart_account_id"] | Conta do plano de contas (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Competência** (obrigatório): AAAA-MM.
- **Descrição** (obrigatório).
- **Valor** (obrigatório).
- **Conta do plano de contas** (obrigatório).
- **Centro de custo**.
- **Vencimento** (obrigatório).
- **Pago em**: Preencha para dar baixa.
- **Conta bancária**: Obrigatória quando o lançamento está pago.
- **Fornecedor**.
- **Unidade**: Unidade que deve a receita (faturas preenchem sozinhas).
- **Nº do documento**: Nota fiscal, recibo ou boleto.
- **Observações**.
- **Situação** (obrigatório): Opções: Válido e Cancelado.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Descrição**, **Condomínio**, **Competência**, **Valor**, **Vencimento**, **Pago em** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

> Para dar baixa, abra a conta e informe **Pago em** e a **Conta bancária** de onde saiu o dinheiro.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Lançar uma despesa](artigo:lancar-despesa), [Pagamentos pendentes](artigo:pagamentos-pendentes) e [Contas a receber](artigo:contas-a-receber).
