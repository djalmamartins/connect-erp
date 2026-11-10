---
codigo: AP-156
titulo: Banners do aplicativo
resumo: Avisos curtos no topo do aplicativo dos moradores, por período. Sem condomínio, o banner aparece para todos.
categoria: configuracoes
rota: /configuracoes/banners
menu: Configurações > Aplicativo e notificações > Banners
icone: list-outline
situacao: funciona
relacionados: notificacoes-aplicativo, alertas, layout-da-aplicacao, notificacoes-da-prestacao-de-contas
palavras: banners, configurações, AP-156
rascunho: nao
---

Avisos curtos no topo do aplicativo dos moradores, por período. Sem condomínio, o banner aparece para todos.

## Onde fica

No menu lateral, abra [[Configurações > Aplicativo e notificações > Banners]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela banners
rota: /configuracoes/banners
condominio: residencial-jardim-das-acacias
legenda: Tela Banners
1: menu | Banners no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo banner") | Botão {{Novo banner}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
```

## Como cadastrar

1. Clique em {{Novo banner}}, no alto da lista.
2. Preencha os campos obrigatórios: **Título**, **Texto**, **Início**, **Fim** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela banners-novo-banner
rota: /configuracoes/banners/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo banner
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="title"] | Título (obrigatório)
2: main form:not([method=get]) [name="message"] | Texto (obrigatório)
3: main form:not([method=get]) [name="starts_on"] | Início (obrigatório)
4: main form:not([method=get]) [name="ends_on"] | Fim (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio**: Vazio: todos os condomínios.
- **Título** (obrigatório).
- **Texto** (obrigatório).
- **Início** (obrigatório).
- **Fim** (obrigatório).
- **Ordem**: Menor aparece primeiro.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a tela mostra

- **Banners**

> Na tela: *Cadastre o primeiro registro para começar.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Notificações do aplicativo](artigo:notificacoes-aplicativo), [Alertas do sistema](artigo:alertas) e [Aparência do aplicativo](artigo:layout-da-aplicacao).
