---
codigo: CN-003
titulo: Cadastrar e gerenciar contas bancárias
resumo: Contas correntes, poupanças e aplicações de cada condomínio. Uma delas é a principal.
categoria: financeiro
rota: /financeiro/contas-bancarias
menu: Financeiro > Cadastros financeiros > Contas bancárias
icone: list-outline
situacao: funciona
palavras: contas bancárias, financeiro, CN-003
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Financeiro > Cadastros financeiros > Contas bancárias]].

```tela contas-bancarias
rota: /financeiro/contas-bancarias
condominio: residencial-jardim-das-acacias
legenda: Tela Contas bancárias
1: menu | Contas bancárias no menu lateral
2: main :is(a,button):has-text("Nova conta bancária") | Botão {{Nova conta bancária}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Tipo, Situação
4: main table thead | Lista com Apelido, Condomínio, Banco (código), Agência, Conta, Conta principal e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova conta bancária}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela contas-bancarias-novo
rota: /financeiro/contas-bancarias/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de conta bancária
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Apelido** (obrigatório): ex.: Conta movimento.
- **Banco (código)** (obrigatório).
- **Agência** (obrigatório).
- **Conta** (obrigatório).
- **Tipo** (obrigatório): opções: Conta corrente, Poupança e Aplicação.
- **Carteira de cobrança**: quando houver.
- **Saldo inicial**: em reais, por exemplo 1.250,00.
- **Data do saldo inicial**: no formato dia/mês/ano.
- **Conta principal**: marque para ativar.
- **Centro de custo padrão**: opcional; sugerido nos lançamentos desta conta.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

