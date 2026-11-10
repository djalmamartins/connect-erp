---
codigo: AP-168
titulo: Cadastrar e gerenciar tipos de Faturas
resumo: O que é cobrado das unidades e a conta de receita de cada cobrança. Sem condomínio, o tipo vale para todos.
categoria: configuracoes
rota: /configuracoes/tipos-de-faturas
menu: Configurações > Financeiro e parâmetros > Tipos de Faturas
icone: list-outline
situacao: funciona
palavras: tipos de faturas, configurações, AP-168
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Tipos de Faturas]].

```tela tipos-de-faturas
rota: /configuracoes/tipos-de-faturas
condominio: residencial-jardim-das-acacias
legenda: Tela Tipos de Faturas
1: menu | Tipos de Faturas no menu lateral
2: main :is(a,button):has-text("Novo tipo de fatura") | Botão {{Novo tipo de fatura}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Recorrência, Situação
4: main table thead | Lista com Nome, Condomínio, Recorrência, Valor padrão e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo tipo de fatura}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela tipos-de-faturas-novo
rota: /configuracoes/tipos-de-faturas/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de tipo de fatura
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio**: vazio: tipo padrão da Connect (todos os condomínios). Só aparecem os condomínios que a sua conta pode ver.
- **Nome** (obrigatório): ex.: Taxa condominial, Fundo de reserva.
- **Conta de receita** (obrigatório): conta analítica de receita do plano de contas.
- **Recorrência** (obrigatório): opções: Mensal e Avulsa.
- **Valor padrão**: opcional. Em reais, por exemplo 1.250,00.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

