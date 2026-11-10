---
codigo: AP-149
titulo: Usuários do Sistema
resumo: Como usar a tela Usuários do Sistema do Connect ERP: onde fica no menu, o que cada parte mostra e o passo a passo.
categoria: configuracoes
rota: /configuracoes/usuarios-do-sistema
menu: Configurações > Acesso > Usuários do Sistema
icone: list-outline
situacao: sem-backend
palavras: usuários do sistema, configurações, AP-149
rascunho: nao
---

>! Esta tela está **em implantação**: ela mostra como vai funcionar, com dados de exemplo, e ainda não grava nada.

## Onde fica

No menu lateral, abra [[Configurações > Acesso > Usuários do Sistema]].

```tela usuarios-do-sistema
rota: /configuracoes/usuarios-do-sistema
condominio: residencial-jardim-das-acacias
legenda: Tela Usuários do Sistema
1: menu | Usuários do Sistema no menu lateral
2: main :is(a,button):has-text("Novo usuário") | Botão {{Novo usuário}}
3: main :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Situação
5: main table thead | Lista com Nome, E-mail, Perfil, 2fa, Último acesso e Situação
```

## Como usar

Os botões no alto da tela são {{Exportar}} e {{Novo usuário}}.

1. Ajuste os filtros (Pesquisar e Situação).
2. Clique em {{Filtrar}}.
3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.

### O que cada coluna mostra

- **Nome**
- **E-mail**
- **Perfil**
- **2fa**
- **Último acesso**
- **Situação**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

