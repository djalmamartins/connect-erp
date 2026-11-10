---
codigo: AP-150
titulo: Permissões de usuários
resumo: O que cada papel pode fazer no ERP e as exceções de cada usuário, tudo fechado por padrão.
categoria: configuracoes
rota: /configuracoes/permissoes-de-usuarios
menu: Configurações > Acesso > Permissões de Usuários
icone: options-outline
situacao: funciona
relacionados: usuarios-do-sistema, permissoes-de-moradores, administrativo
palavras: permissões, papéis, perfis, exceção, acesso, AP-150
rascunho: nao
---

## Para que serve

A grade mostra, para cada papel, quais permissões do ERP ele tem. Abaixo, você libera ou bloqueia uma permissão para uma pessoa só. Sem papel ou sem permissão, a pessoa não entra: tudo começa fechado.

## Onde fica

No menu lateral, abra [[Configurações > Acesso > Permissões de Usuários]].

```tela permissoes-de-usuarios
rota: /configuracoes/permissoes-de-usuarios
condominio: residencial-jardim-das-acacias
legenda: Tela Permissões de Usuários
1: menu | Permissões de Usuários no menu lateral
2: main [data-erp-access] | Permissões por papel e exceções por usuário
```

## Mudar a permissão de um papel

Marque ou desmarque a caixa na linha da permissão e na coluna do papel. A mudança grava na hora e fica na auditoria. Como o papel vale para todos os condomínios, só quem acessa todos pode mudar.

## Criar uma exceção para uma pessoa

1. Em **Exceções por usuário**, escolha o usuário e clique em {{Ver}}.
2. Na linha da permissão, escolha **Liberar**, **Bloquear** ou **Segue o papel**.

**Bloquear** sempre vence o papel. Atualize a página para ver a coluna **Vale** com a mudança.

>! Você não concede o que não tem, não mexe em papel igual ou acima do seu nem na própria conta.
