---
codigo: AP-115
titulo: Cadastrar e gerenciar lista de espera
resumo: Quem quer uma área comum numa data já reservada. A posição é a ordem de inscrição.
categoria: operacional
rota: /operacional/lista-de-espera
menu: Operacional > Rotina > Lista de espera
icone: list-outline
situacao: funciona
palavras: lista de espera, operacional, AP-115
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Lista de espera]].

```tela lista-de-espera
rota: /operacional/lista-de-espera
condominio: residencial-jardim-das-acacias
legenda: Tela Lista de espera
1: menu | Lista de espera no menu lateral
2: main :is(a,button):has-text("Nova inscrição") | Botão {{Nova inscrição}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Situação
4: main .erp-card | Lista de espera
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova inscrição}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela lista-de-espera-novo
rota: /operacional/lista-de-espera/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de inscrição
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Área comum** (obrigatório).
- **Unidade** (obrigatório): unidade cadastrada em Condomínios › Unidades. Escolha entre as unidades do condomínio selecionado.
- **Morador** (obrigatório).
- **Data desejada** (obrigatório): no formato dia/mês/ano.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

