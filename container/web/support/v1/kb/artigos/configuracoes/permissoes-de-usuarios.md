---
codigo: AP-150
titulo: Permissões dos perfis de usuário
resumo: A grade define o que cada perfil (Administrador, Financeiro, Atendimento, Consulta) pode fazer em cada módulo.
categoria: configuracoes
rota: /configuracoes/permissoes-de-usuarios
menu: Configurações > Acesso > Permissões de Usuários
icone: options-outline
situacao: exemplo
relacionados: usuarios-do-sistema, permissoes-de-moradores, administrativo, usuarios-de-imobiliarias
palavras: permissões de usuários, configurações, AP-150
rascunho: nao
---

A grade define o que cada perfil (Administrador, Financeiro, Atendimento, Consulta) pode fazer em cada módulo.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Configurações > Acesso > Permissões de Usuários]].

```tela permissoes-de-usuarios
rota: /configuracoes/permissoes-de-usuarios
condominio: residencial-jardim-das-acacias
legenda: Tela Permissões de Usuários
1: menu | Permissões de Usuários no menu lateral
2: main table thead | Colunas da lista
```

## Permissões por perfil

Marque o que cada perfil pode ver e alterar

```tela permissoes-de-usuarios-form
rota: /configuracoes/permissoes-de-usuarios
condominio: residencial-jardim-das-acacias
legenda: Permissões por perfil
recorte: main form:not([method=get])
1: main form:not([method=get]) button:has-text("Salvar configurações") | Botão {{Salvar configurações}}
```

## O que a lista mostra

Colunas: **Módulo**, **Administrador**, **Financeiro**, **Atendimento** e **Consulta**.

## Como mudar uma permissão

1. Encontre o módulo na linha e o perfil na coluna.
2. Marque ou desmarque a caixa.
3. Clique em {{Salvar configurações}}. {{Desfazer alterações}} volta como estava antes de salvar.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Usuários do sistema](artigo:usuarios-do-sistema), [Permissões dos moradores no aplicativo](artigo:permissoes-de-moradores) e [Dados da administradora](artigo:administrativo).
