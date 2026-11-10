---
codigo: AP-029
titulo: Transferir entre contas bancárias
resumo: Registra a transferência de dinheiro entre contas do mesmo condomínio, como da conta corrente para a poupança. Não é receita nem despesa: muda só o saldo de cada conta.
categoria: financeiro
rota: /financeiro/outros-lancamentos
menu: Financeiro > Lançamentos > Outros Lançamentos
icone: create-outline
situacao: funciona
relacionados: relatorio-de-transferencias, extrato-conta, cobrancas-extras, lancar-multa-avulsa
palavras: outros lançamentos, financeiro, AP-029
rascunho: nao
---

Registra a transferência de dinheiro entre contas do mesmo condomínio, como da conta corrente para a poupança. Não é receita nem despesa: muda só o saldo de cada conta.

## Onde fica

No menu lateral, abra [[Financeiro > Lançamentos > Outros Lançamentos]].

```tela outros-lancamentos
rota: /financeiro/outros-lancamentos
condominio: residencial-jardim-das-acacias
legenda: Tela Outros Lançamentos
1: menu | Outros Lançamentos no menu lateral
2: main form:not([method=get]) | Registrar transferência
```

## Registrar transferência

Sai de uma conta e entra na outra na mesma data; o total do condomínio não muda.

1. Preencha **Sai da conta**, **Entra na conta**, **Valor (R$)**, **Data** e **Descrição**.
2. Clique em {{Registrar}}.

```tela outros-lancamentos-form
rota: /financeiro/outros-lancamentos
condominio: residencial-jardim-das-acacias
legenda: Registrar transferência
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="from_bank_account_id"] | Sai da conta (obrigatório)
2: main form:not([method=get]) [name="to_bank_account_id"] | Entra na conta (obrigatório)
3: main form:not([method=get]) [name="amount_cents"] | Valor (R$) (obrigatório)
4: main form:not([method=get]) [name="transferred_on"] | Data (obrigatório)
5: main form:not([method=get]) [name="description"] | Descrição (obrigatório)
6: main form:not([method=get]) button:has-text("Registrar") | Botão {{Registrar}}
```

### O que preencher

- **Sai da conta** (obrigatório): Opções: Conta movimento — 001 — 12345-6 e Fundo de reserva — 001 — 65432-1.
- **Entra na conta** (obrigatório): Opções: Conta movimento — 001 — 12345-6 e Fundo de reserva — 001 — 65432-1.
- **Valor (R$)** (obrigatório).
- **Data** (obrigatório).
- **Descrição** (obrigatório): Exemplo: Ex.: Aplicação do fundo de reserva.

> Na tela: *Use o formulário acima para registrar a primeira.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de transferências](artigo:relatorio-de-transferencias), [Extrato da conta bancária](artigo:extrato-conta) e [Cobranças extras (rateio ou valor fixo)](artigo:cobrancas-extras).
