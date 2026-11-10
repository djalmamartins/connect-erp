---
codigo: AP-132
titulo: Cadastrar e gerenciar previsão de Visita
resumo: Visitas avisadas pela unidade, para a portaria liberar na data.
categoria: operacional
rota: /operacional/previsao-de-visita
menu: Operacional > Portaria > Previsão de Visita
icone: list-outline
situacao: funciona
palavras: previsão de visita, operacional, AP-132
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Portaria > Previsão de Visita]].

```tela previsao-de-visita
rota: /operacional/previsao-de-visita
condominio: residencial-jardim-das-acacias
legenda: Tela Previsão de Visita
1: menu | Previsão de Visita no menu lateral
2: main :is(a,button):has-text("Nova visita prevista") | Botão {{Nova visita prevista}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Situação
4: main .erp-card | Previsão de visita
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova visita prevista}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela previsao-de-visita-novo
rota: /operacional/previsao-de-visita/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de visita prevista
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Unidade** (obrigatório): unidade cadastrada em Condomínios › Unidades. Escolha entre as unidades do condomínio selecionado.
- **Visitante** (obrigatório).
- **Documento (CPF ou RG)**.
- **Data** (obrigatório): no formato dia/mês/ano.
- **Até**: vazio = só no dia. No formato dia/mês/ano.
- **Autorizado por**.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

