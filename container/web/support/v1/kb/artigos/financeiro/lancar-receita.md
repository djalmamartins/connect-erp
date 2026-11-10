---
codigo: AP-025
titulo: Lançar uma receita
resumo: Formulário direto para lançar uma receita avulsa, como um aluguel de salão ou um rendimento. A taxa condominial do mês sai pelas Faturas, não daqui.
categoria: financeiro
rota: /financeiro/lancar-receita
menu: Financeiro > Lançamentos > Lançar Receita
icone: create-outline
situacao: funciona
relacionados: contas-a-receber, faturas, lancar-despesa, lancar-desconto
palavras: lançar receita, financeiro, AP-025
rascunho: nao
---

Formulário direto para lançar uma receita avulsa, como um aluguel de salão ou um rendimento. A taxa condominial do mês sai pelas Faturas, não daqui.

## Onde fica

No menu lateral, abra [[Financeiro > Lançamentos > Lançar Receita]].

```tela lancar-receita
rota: /financeiro/lancar-receita
condominio: residencial-jardim-das-acacias
legenda: Tela Lançar Receita
1: menu | Lançar Receita no menu lateral
2: main form:not([method=get]) | Dados da conta a receber
```

## Dados da conta a receber

1. Preencha **Condomínio**, **Competência**, **Descrição**, **Valor**, **Conta do plano de contas**, **Vencimento** e **Situação**.
2. Clique em {{Salvar}}.

```tela lancar-receita-form
rota: /financeiro/lancar-receita
condominio: residencial-jardim-das-acacias
legenda: Dados da conta a receber
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

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Contas a receber](artigo:contas-a-receber), [Faturas](artigo:faturas) e [Lançar uma despesa](artigo:lancar-despesa).
