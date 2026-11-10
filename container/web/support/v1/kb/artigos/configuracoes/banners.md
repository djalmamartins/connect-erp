---
codigo: AP-156
titulo: Cadastrar e gerenciar banners
resumo: Avisos curtos no topo do aplicativo, por período. Sem condomínio, o banner aparece para todos.
categoria: configuracoes
rota: /configuracoes/banners
menu: Configurações > Aplicativo e notificações > Banners
icone: list-outline
situacao: funciona
palavras: banners, configurações, AP-156
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Configurações > Aplicativo e notificações > Banners]].

```tela banners
rota: /configuracoes/banners
condominio: residencial-jardim-das-acacias
legenda: Tela Banners
1: menu | Banners no menu lateral
2: main :is(a,button):has-text("Novo banner") | Botão {{Novo banner}}
3: main form[method=get] | Filtros: Pesquisar, Condomínio, Situação
4: main .erp-card | Banners
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como cadastrar

1. Clique em {{Novo banner}} no alto da tela.
2. Preencha os campos. Os obrigatórios têm asterisco (*).
3. Clique em {{Salvar}}. O sistema confere os dados e avisa, no próprio campo, o que precisa corrigir.

```tela banners-novo
rota: /configuracoes/banners/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário de banner
recorte: main form.erp-form
1: main form.erp-form .erp-field | Comece pelo primeiro campo; os obrigatórios têm *
2: main .erp-form__actions button[type=submit] | Botão {{Salvar}} grava o cadastro
```

### O que preencher

- **Condomínio**: vazio: todos os condomínios. Só aparecem os condomínios que a sua conta pode ver.
- **Título** (obrigatório).
- **Texto** (obrigatório).
- **Início** (obrigatório): no formato dia/mês/ano.
- **Fim** (obrigatório): no formato dia/mês/ano.
- **Ordem**: menor aparece primeiro.

## Pesquisar e filtrar

Use o campo **Pesquisar** e os filtros acima da lista e clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros e filtre de novo.

## Editar ou excluir

Clique no nome ou no lápis da linha para abrir o cadastro, altere o que precisar e clique em {{Salvar}}. Para excluir, abra o cadastro e use {{Excluir}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

