---
codigo: AP-112
titulo: Cadastrar e gerenciar ocorrências
resumo: Ocorrências do condomínio (barulho, vazamento, dano). Quando precisa da administradora, abra o chamado no Help Desk.
categoria: operacional
rota: /operacional/ocorrencias
menu: Operacional > Rotina > Ocorrências
icone: list-outline
situacao: funciona
palavras: ocorrências, operacional, AP-112
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Ocorrências]].

```tela ocorrencias
rota: /operacional/ocorrencias
condominio: residencial-jardim-das-acacias
legenda: Tela Ocorrências
1: menu | Ocorrências no menu lateral
2: main :is(a,button):has-text("Nova ocorrência") | Botão {{Nova ocorrência}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Prioridade, Situação
4: main table thead | Lista com Assunto, Condomínio, Unidade, Prioridade, Data e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova ocorrência}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela ocorrencias-novo
rota: /operacional/ocorrencias/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de ocorrência
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Unidade**: unidade cadastrada em Condomínios › Unidades. Escolha entre as unidades do condomínio selecionado.
- **Assunto** (obrigatório).
- **Prioridade** (obrigatório): opções: Baixa, Média e Alta.
- **Data** (obrigatório): no formato dia/mês/ano.
- **Foto ou documento**: nº do arquivo no Disco Virtual (Documentos › Disco Virtual).
- **Descrição** (obrigatório).

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

