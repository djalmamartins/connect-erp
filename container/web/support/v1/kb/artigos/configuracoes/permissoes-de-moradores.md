---
codigo: AP-153
titulo: Permissões dos moradores no aplicativo
resumo: A grade define o que cada tipo de pessoa (proprietário, inquilino, morador, responsável financeiro) vê no aplicativo: boletos, prestação de contas, reservas e outros módulos.
categoria: configuracoes
rota: /configuracoes/permissoes-de-moradores
menu: Configurações > Acesso > Permissões de Moradores
icone: options-outline
situacao: exemplo
relacionados: permissoes-de-usuarios, usuarios-de-imobiliarias, usuarios-do-sistema, criacao-de-usuarios-para-moradores
palavras: permissões de moradores, configurações, AP-153
rascunho: nao
---

A grade define o que cada tipo de pessoa (proprietário, inquilino, morador, responsável financeiro) vê no aplicativo: boletos, prestação de contas, reservas e outros módulos.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Configurações > Acesso > Permissões de Moradores]].

```tela permissoes-de-moradores
rota: /configuracoes/permissoes-de-moradores
condominio: residencial-jardim-das-acacias
legenda: Tela Permissões de Moradores
1: menu | Permissões de Moradores no menu lateral
2: main table thead | Colunas da lista
```

## Permissões por perfil

Marque o que cada perfil pode ver e alterar

```tela permissoes-de-moradores-form
rota: /configuracoes/permissoes-de-moradores
condominio: residencial-jardim-das-acacias
legenda: Permissões por perfil
recorte: main form:not([method=get])
1: main form:not([method=get]) button:has-text("Salvar configurações") | Botão {{Salvar configurações}}
```

## O que a lista mostra

Colunas: **Módulo**, **Proprietário**, **Inquilino**, **Morador** e **Responsável financeiro**.

## Como mudar uma permissão

1. Encontre o módulo na linha e o tipo de pessoa na coluna.
2. Marque ou desmarque a caixa.
3. Clique em {{Salvar configurações}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Permissões dos perfis de usuário](artigo:permissoes-de-usuarios), [Usuários de imobiliárias](artigo:usuarios-de-imobiliarias) e [Usuários do sistema](artigo:usuarios-do-sistema).
