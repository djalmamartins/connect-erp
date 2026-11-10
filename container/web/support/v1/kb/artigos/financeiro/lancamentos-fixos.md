---
codigo: AP-021
titulo: Cadastrar e gerenciar lançamentos fixos
resumo: Receitas e despesas que se repetem todo mês. Gere os lançamentos de uma competência sem duplicar os já gerados.
categoria: financeiro
rota: /financeiro/lancamentos-fixos
menu: Financeiro > Lançamentos > Lançamentos fixos
icone: list-outline
situacao: funciona
palavras: lançamentos fixos, financeiro, AP-021
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Financeiro > Lançamentos > Lançamentos fixos]].

```tela lancamentos-fixos
rota: /financeiro/lancamentos-fixos
condominio: residencial-jardim-das-acacias
legenda: Tela Lançamentos fixos
1: menu | Lançamentos fixos no menu lateral
2: main :is(a,button):has-text("Novo lançamento fixo") | Botão {{Novo lançamento fixo}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Natureza, Situação
4: main table thead | Lista com Descrição, Condomínio, Natureza, Valor, Dia do vencimento, Primeira competência, Última competência e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo lançamento fixo}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela lancamentos-fixos-novo
rota: /financeiro/lancamentos-fixos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de lançamento fixo
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Natureza** (obrigatório): opções: Receita e Despesa.
- **Descrição** (obrigatório).
- **Valor** (obrigatório): em reais, por exemplo 1.250,00.
- **Conta do plano de contas** (obrigatório).
- **Centro de custo**.
- **Conta bancária**: conta sugerida; o lançamento gerado nasce em aberto.
- **Fornecedor**.
- **Dia do vencimento** (obrigatório): 1 a 28.
- **Primeira competência** (obrigatório): aAAA-MM.
- **Última competência**: vazio = sem fim.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

