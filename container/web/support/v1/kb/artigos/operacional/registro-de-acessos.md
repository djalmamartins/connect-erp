---
codigo: AP-133
titulo: Cadastrar e gerenciar registro de Acessos
resumo: Entradas e saídas registradas na portaria: visitantes, prestadores, entregadores e moradores.
categoria: operacional
rota: /operacional/registro-de-acessos
menu: Operacional > Portaria > Registro de Acessos
icone: list-outline
situacao: funciona
palavras: registro de acessos, operacional, AP-133
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Portaria > Registro de Acessos]].

```tela registro-de-acessos
rota: /operacional/registro-de-acessos
condominio: residencial-jardim-das-acacias
legenda: Tela Registro de Acessos
1: menu | Registro de Acessos no menu lateral
2: main :is(a,button):has-text("Novo acesso") | Botão {{Novo acesso}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Tipo, Situação
4: main .erp-card | Registro de acessos
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo acesso}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela registro-de-acessos-novo
rota: /operacional/registro-de-acessos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de acesso
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio** (obrigatório): só aparecem os condomínios que a sua conta pode ver.
- **Unidade**: unidade cadastrada em Condomínios › Unidades. Escolha entre as unidades do condomínio selecionado.
- **Pessoa** (obrigatório).
- **Documento (CPF ou RG)**.
- **Tipo** (obrigatório): opções: Visitante, Prestador, Entregador e Morador.
- **Placa**.
- **Entrada** (obrigatório): no formato dia/mês/ano.
- **Hora** (obrigatório).
- **Saída**: no mesmo dia.
- **Portaria**.
- **Visita prevista**.
- **Observações**.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

