---
codigo: AP-148
titulo: Usuários de imobiliárias
resumo: Acesso de imobiliárias parceiras aos condomínios administrados pela Connect. A imobiliária só consulta, e os contatos dos moradores aparecem mascarados.
categoria: configuracoes
rota: /configuracoes/usuarios-de-imobiliarias
menu: Configurações > Acesso > Usuários de imobiliárias
icone: list-outline
situacao: exemplo
relacionados: permissoes-de-moradores, criacao-de-usuarios-para-moradores, permissoes-de-usuarios, usuarios-do-sistema
palavras: usuários de imobiliárias, configurações, AP-148
rascunho: nao
---

Acesso de imobiliárias parceiras aos condomínios administrados pela Connect. A imobiliária só consulta, e os contatos dos moradores aparecem mascarados.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Configurações > Acesso > Usuários de imobiliárias]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela usuarios-de-imobiliarias
rota: /configuracoes/usuarios-de-imobiliarias
condominio: residencial-jardim-das-acacias
legenda: Tela Usuários de imobiliárias
1: menu | Usuários de imobiliárias no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo usuário de imobiliária") | Botão {{Novo usuário de imobiliária}}
3: main .erp-page-head__actions :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar e Situação
5: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo usuário de imobiliária}}. A janela abre por cima da lista.
2. Preencha os campos obrigatórios: **Nome**, **Imobiliária**, **E-mail** e **Condomínios**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.

```tela usuarios-de-imobiliarias-novo-usuario-de-imobiliaria
rota: /configuracoes/usuarios-de-imobiliarias
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo usuário de imobiliária
antes: clicar main .erp-page-head__actions :is(a,button):has-text("Novo usuário de imobiliária")
antes: esperar 500
recorte: dialog[open]
1: dialog[open] [name="ap-148-nome"] | Nome (obrigatório)
2: dialog[open] [name="ap-148-imobiliaria"] | Imobiliária (obrigatório)
3: dialog[open] [name="ap-148-e-mail"] | E-mail (obrigatório)
4: dialog[open] [name="ap-148-condominios"] | Condomínios (obrigatório)
5: dialog[open] button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Nome** (obrigatório).
- **Imobiliária** (obrigatório).
- **E-mail** (obrigatório): Exemplo: nome@exemplo.com.
- **Condomínios** (obrigatório).

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar** e **Situação**.
Depois clique em {{Limpar filtros}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Nome**, **Imobiliária**, **E-mail**, **Condomínios** e **Situação**.

## Ações de cada linha

- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.
- **Excluir** pede confirmação antes. O histórico guarda quem excluiu e quando.

## Imprimir ou exportar

- {{Exportar}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Permissões dos moradores no aplicativo](artigo:permissoes-de-moradores), [Criar o acesso de um morador](artigo:criacao-de-usuarios-para-moradores) e [Permissões dos perfis de usuário](artigo:permissoes-de-usuarios).
