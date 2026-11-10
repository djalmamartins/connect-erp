---
codigo: CN-002
titulo: Cadastrar e gerenciar centros de custo
resumo: Agrupam lançamentos por área ou projeto. Sem condomínio, o centro de custo vale para todos.
categoria: financeiro
rota: /financeiro/centros-de-custo
menu: Financeiro > Cadastros financeiros > Centros de custo
icone: list-outline
situacao: funciona
palavras: centros de custo, financeiro, CN-002
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Financeiro > Cadastros financeiros > Centros de custo]].

```tela centros-de-custo
rota: /financeiro/centros-de-custo
condominio: residencial-jardim-das-acacias
legenda: Tela Centros de custo
1: menu | Centros de custo no menu lateral
2: main :is(a,button):has-text("Novo centro de custo") | Botão {{Novo centro de custo}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Situação
4: main table thead | Lista com Código, Condomínio, Nome e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo centro de custo}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela centros-de-custo-novo
rota: /financeiro/centros-de-custo/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de centro de custo
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio**: vazio: centro de custo padrão da Connect (todos os condomínios). Só aparecem os condomínios que a sua conta pode ver.
- **Código** (obrigatório).
- **Nome** (obrigatório): ex.: Portaria, Limpeza, Obra do bloco B.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

