---
codigo: AP-024
titulo: Lançar Despesa
resumo: Preencha os dados e salve. Campos com * são obrigatórios.
categoria: financeiro
rota: /financeiro/lancar-despesa
menu: Financeiro > Lançamentos > Lançar Despesa
icone: create-outline
situacao: funciona
palavras: lançar despesa, financeiro, AP-024
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Financeiro > Lançamentos > Lançar Despesa]].

```tela lancar-despesa
rota: /financeiro/lancar-despesa
condominio: residencial-jardim-das-acacias
legenda: Tela Lançar Despesa
1: menu | Lançar Despesa no menu lateral
2: main .erp-card | Dados da conta a pagar
```

## Como preencher

- **Condomínio** (obrigatório)
- **Competência** (obrigatório)
- **Descrição** (obrigatório)
- **Valor** (obrigatório)
- **Conta do plano de contas** (obrigatório)
- **Centro de custo**
- **Vencimento** (obrigatório)
- **Pago em**
- **Conta bancária**
- **Fornecedor**
- **Unidade**
- **Nº do documento**
- **Observações**
- **Situação** (obrigatório)

Depois de preencher, clique em {{Salvar}}. O sistema avisa no próprio campo o que precisa corrigir.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

