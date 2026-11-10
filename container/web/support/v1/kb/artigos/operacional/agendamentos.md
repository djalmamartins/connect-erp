---
codigo: AP-120
titulo: Cadastrar e gerenciar agendamentos
resumo: Mudanças de entrada e saída por período. Um agendamento por período e dia em cada condomínio.
categoria: operacional
rota: /operacional/agendamentos
menu: Operacional > Rotina > Agendamentos
icone: list-outline
situacao: funciona
palavras: agendamentos, operacional, AP-120
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Agendamentos]].

```tela agendamentos
rota: /operacional/agendamentos
condominio: residencial-jardim-das-acacias
legenda: Tela Agendamentos
1: menu | Agendamentos no menu lateral
2: main :is(a,button):has-text("Novo agendamento") | Botão {{Novo agendamento}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Tipo, Situação
4: main table thead | Lista com Morador, Condomínio, Unidade, Tipo, Data, Período e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo agendamento}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela agendamentos-novo
rota: /operacional/agendamentos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de agendamento
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Unidade** (obrigatório): unidade cadastrada em Condomínios › Unidades. Escolha entre as unidades do condomínio selecionado.
- **Morador** (obrigatório).
- **Tipo** (obrigatório): opções: Entrada e Saída.
- **Data** (obrigatório): no formato dia/mês/ano.
- **Período** (obrigatório): opções: Manhã e Tarde.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

