---
codigo: AP-160
titulo: Cadastrar e gerenciar plano de Contas
resumo: Contas de receita e despesa. Sem condomínio, a conta faz parte do plano padrão da Connect e vale para todos.
categoria: configuracoes
rota: /configuracoes/plano-de-contas
menu: Configurações > Financeiro e parâmetros > Plano de Contas
icone: list-outline
situacao: funciona
palavras: plano de contas, configurações, AP-160
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Plano de Contas]].

```tela plano-de-contas
rota: /configuracoes/plano-de-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Plano de Contas
1: menu | Plano de Contas no menu lateral
2: main :is(a,button):has-text("Nova conta") | Botão {{Nova conta}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Natureza, Situação
4: main table thead | Lista com Nome da conta, Condomínio, Código, Natureza, Tipo e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova conta}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela plano-de-contas-novo
rota: /configuracoes/plano-de-contas/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de conta
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio**: vazio: plano padrão da Connect (todos os condomínios). Só aparecem os condomínios que a sua conta pode ver.
- **Código** (obrigatório): ex.: 2.01.003.
- **Natureza** (obrigatório): opções: Receita e Despesa.
- **Nome da conta** (obrigatório).
- **Tipo** (obrigatório): opções: Sintética (agrupa) e Analítica (recebe lançamentos).
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

