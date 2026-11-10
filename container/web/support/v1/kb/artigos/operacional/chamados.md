---
codigo: CN-004
titulo: Chamados
resumo: Abra chamados para o Help Desk com o condomínio, a unidade e quem pediu, e acompanhe o andamento.
categoria: operacional
rota: /operacional/chamados
menu: Operacional > Rotina > Chamados
icone: list-outline
situacao: funciona
palavras: chamados, operacional, CN-004
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Chamados]].

```tela chamados
rota: /operacional/chamados
condominio: residencial-jardim-das-acacias
legenda: Tela Chamados
1: menu | Chamados no menu lateral
2: main :is(a,button):has-text("Abrir chamado") | Botão {{Abrir chamado}}
3: main :is(a,button):has-text("Fila do Help Desk") | Botão {{Fila do Help Desk}}
4: main form[method=get] | Filtros: Buscar, Condomínio, Situação
5: main table thead | Lista com Protocolo, Assunto, Condomínio / unidade, Solicitante, Origem, Prioridade, Situação e Aberto em
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como usar

Os botões no alto da tela são {{Fila do Help Desk}} e {{Abrir chamado}}.

1. Ajuste os filtros (Buscar, Condomínio e Situação).
2. Clique em {{Filtrar}}.
3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.

### O que cada coluna mostra

- **Protocolo**
- **Assunto**
- **Condomínio / unidade**
- **Solicitante**
- **Origem**
- **Prioridade**
- **Situação**
- **Aberto em**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

