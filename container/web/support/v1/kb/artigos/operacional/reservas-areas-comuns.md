---
codigo: AP-113
titulo: Cadastrar e gerenciar reservas Áreas Comuns
resumo: Reservas pelas regras de cada área comum: antecedência, horário, taxa e aprovação. Aguardando aprovação = Agendamentos Pendentes.
categoria: operacional
rota: /operacional/reservas-areas-comuns
menu: Operacional > Rotina > Reservas Áreas Comuns
icone: list-outline
situacao: funciona
palavras: reservas áreas comuns, operacional, AP-113
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Reservas Áreas Comuns]].

```tela reservas-areas-comuns
rota: /operacional/reservas-areas-comuns
condominio: residencial-jardim-das-acacias
legenda: Tela Reservas Áreas Comuns
1: menu | Reservas Áreas Comuns no menu lateral
2: main :is(a,button):has-text("Nova reserva") | Botão {{Nova reserva}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Situação
4: main table thead | Lista com Morador, Condomínio, Área comum, Unidade, Data, Taxa e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Nova reserva}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela reservas-areas-comuns-novo
rota: /operacional/reservas-areas-comuns/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de reserva
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Área comum** (obrigatório).
- **Unidade** (obrigatório): unidade cadastrada em Condomínios › Unidades. Escolha entre as unidades do condomínio selecionado.
- **Morador** (obrigatório).
- **Data** (obrigatório): no formato dia/mês/ano.
- **Das**.
- **Até**.
- **Taxa**: vazio = taxa da área. Em reais, por exemplo 1.250,00.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

