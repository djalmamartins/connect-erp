---
codigo:
titulo: Encontrar uma tela pelo menu ou pela pesquisa
resumo: Como usar o menu lateral, a pesquisa de páginas (Ctrl + K), as notificações e o botão de ajuda que abre o tutorial da tela em que você está.
categoria: primeiros-passos
rota: /dashboard
menu:
icone: navigate-outline
situacao: guia
relacionados: escolher-o-condominio, pedir-ajuda, dashboard
palavras: menu, pesquisar página, atalho, ctrl k, ajuda desta página, notificações, navegação
rascunho: nao
---

Todas as telas do Connect ERP ficam no **menu lateral**, separadas por assunto: Condomínios, Cadastros, Financeiro, Cobrança, Prestação de contas e assim por diante.

## O menu lateral e a barra superior

```tela menu-geral
rota: /dashboard
condominio: residencial-jardim-das-acacias
legenda: Menu lateral e barra superior
1: .erp-nav | Menu lateral com os assuntos do sistema
2: [data-erp-navsearch-toggle] | Pesquisar páginas
3: .erp-navbar__actions summary[aria-label="Suporte"] | Suporte: ajuda desta página e chamados
4: .erp-navbar__actions summary[aria-label^="Notificações"] | Notificações
5: .erp-user-chip | Seu nome: perfil e {{Sair}}
```

- Clique no nome de um assunto para abrir as telas dele. A tela aberta fica destacada no menu.
- Nos tutoriais desta Central, o caminho no menu aparece assim: [[Cadastros > Unidades]].
- No celular, o menu fica escondido no botão de três linhas, no canto superior esquerdo.

## Pesquisar uma página

Quando você não lembra em que assunto está uma tela, pesquise pelo nome:

1. Clique na lupa da barra superior, ou aperte **Ctrl + K** (no Mac, **⌘ + K**).
2. Digite parte do nome, por exemplo `boleto` ou `acordo`.
3. Clique no resultado, ou use as setas e aperte **Enter**.

```tela menu-pesquisa
rota: /dashboard
condominio: residencial-jardim-das-acacias
legenda: Pesquisa de páginas aberta
antes: clicar [data-erp-navsearch-toggle]
antes: preencher #erp-navsearch-panel input => boleto
antes: esperar 400
1: #erp-navsearch-panel | Digite o nome da tela e escolha o resultado
```

> A pesquisa procura só os nomes das telas. Para achar um morador, uma unidade ou uma fatura, use o campo de busca dentro da própria lista.

## Ajuda desta página

O ponto de interrogação na barra superior abre o menu **Suporte**:

- **Ajuda desta página** abre o tutorial da tela em que você está, com os prints e o passo a passo. Se a tela ainda não tiver tutorial, abre a página inicial da Central de Ajuda.
- **Abrir chamado** e **Meus chamados** levam aos seus chamados com a equipe da Connect.

```tela menu-suporte
rota: /dashboard
condominio: residencial-jardim-das-acacias
legenda: Menu Suporte aberto
antes: clicar .erp-navbar__actions summary[aria-label="Suporte"]
antes: esperar 300
1: .erp-dropdown__item:has-text("Ajuda desta página") | Tutorial da tela atual
2: .erp-dropdown__item:has-text("Abrir chamado") | Abrir um chamado
```

>> Veja também [Como pedir ajuda e abrir um chamado](artigo:pedir-ajuda).
