---
codigo: AP-025
titulo: Lançar Receita
resumo: Preencha os dados e salve. Campos com * são obrigatórios.
categoria: financeiro
rota: /financeiro/lancar-receita
menu: Financeiro > Lançamentos > Lançar Receita
icone: create-outline
situacao: funciona
palavras: lançar receita, financeiro, AP-025
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Financeiro > Lançamentos > Lançar Receita]].

```tela lancar-receita
rota: /financeiro/lancar-receita
condominio: residencial-jardim-das-acacias
legenda: Tela Lançar Receita
1: menu | Lançar Receita no menu lateral
2: main .erp-card | Dados da conta a receber
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

