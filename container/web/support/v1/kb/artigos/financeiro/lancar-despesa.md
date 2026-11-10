---
codigo: AP-024
titulo: Lançar uma despesa
resumo: Formulário direto para lançar uma despesa do condomínio. Use quando chega uma conta para pagar, como uma nota de serviço ou a conta de água.
categoria: financeiro
rota: /financeiro/lancar-despesa
menu: Financeiro > Lançamentos > Lançar Despesa
icone: create-outline
situacao: funciona
relacionados: contas-a-pagar, importacao-nf-e-xml, lancar-receita, lancar-desconto
palavras: lançar despesa, financeiro, AP-024
rascunho: nao
---

Formulário direto para lançar uma despesa do condomínio. Use quando chega uma conta para pagar, como uma nota de serviço ou a conta de água.

## Onde fica

No menu lateral, abra [[Financeiro > Lançamentos > Lançar Despesa]].

```tela lancar-despesa
rota: /financeiro/lancar-despesa
condominio: residencial-jardim-das-acacias
legenda: Tela Lançar Despesa
1: menu | Lançar Despesa no menu lateral
2: main form:not([method=get]) | Dados da conta a pagar
```

## Dados da conta a pagar

1. Preencha **Condomínio**, **Competência**, **Descrição**, **Valor**, **Conta do plano de contas**, **Vencimento** e **Situação**.
2. Clique em {{Salvar}}.

```tela lancar-despesa-form
rota: /financeiro/lancar-despesa
condominio: residencial-jardim-das-acacias
legenda: Dados da conta a pagar
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) :is(.erp-month:has([name="competence"]), [name="competence"]:not([type=hidden])) | Competência (obrigatório)
3: main form:not([method=get]) [name="description"] | Descrição (obrigatório)
4: main form:not([method=get]) [name="amount_cents"] | Valor (obrigatório)
5: main form:not([method=get]) [name="chart_account_id"] | Conta do plano de contas (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Competência** (obrigatório): mês e ano de referência, ex.: nov/2026. Clique no calendário ao lado do campo e escolha o mês, ou digite 11/2026.
- **Descrição** (obrigatório).
- **Valor** (obrigatório).
- **Conta do plano de contas** (obrigatório): só aparecem contas de despesa, ex.: **2.04.01 Manutenção elétrica**.
- **Centro de custo**: opcional.
- **Vencimento** (obrigatório).
- **Pago em**: Preencha para dar baixa.
- **Conta bancária**: só é obrigatória ao dar baixa (com **Pago em** preenchido).
- **Fornecedor**.
- **Unidade**: Unidade que deve a receita (faturas preenchem sozinhas).
- **Nº do documento**: Nota fiscal, recibo ou boleto.
- **Observações**.
- **Situação** (obrigatório): Opções: Válido e Cancelado.

> Já pagou? Preencha **Pago em** e a **Conta bancária** no mesmo formulário, e a despesa entra paga.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Contas a pagar](artigo:contas-a-pagar), [Importar nota fiscal eletrônica (NF-e XML)](artigo:importacao-nf-e-xml) e [Lançar uma receita](artigo:lancar-receita).
