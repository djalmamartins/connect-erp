---
codigo: AP-148
titulo: Usuários de imobiliárias
resumo: Acesso de imobiliárias parceiras aos condomínios administrados pela Connect
categoria: configuracoes
rota: /configuracoes/usuarios-de-imobiliarias
menu: Configurações > Acesso > Usuários de imobiliárias
icone: list-outline
situacao: exemplo
palavras: usuários de imobiliárias, configurações, AP-148
rascunho: nao
---

>! Esta tela ainda mostra **dados de exemplo**. Ela já abre com os filtros e as colunas definitivos, mas os números reais chegam numa próxima versão. Use-a para conhecer o relatório; não tome decisões com os valores mostrados.

## Onde fica

No menu lateral, abra [[Configurações > Acesso > Usuários de imobiliárias]].

```tela usuarios-de-imobiliarias
rota: /configuracoes/usuarios-de-imobiliarias
condominio: residencial-jardim-das-acacias
legenda: Tela Usuários de imobiliárias
1: menu | Usuários de imobiliárias no menu lateral
2: main :is(a,button):has-text("Novo usuário de imobiliária") | Botão {{Novo usuário de imobiliária}}
3: main :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Situação
5: main table thead | Lista com Nome, Imobiliária, E-mail, Condomínios e Situação
```

## Como usar

Os botões no alto da tela são {{Exportar}} e {{Novo usuário de imobiliária}}.

1. Ajuste os filtros (Pesquisar e Situação).
2. Clique em {{Filtrar}}.
3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.

### O que cada coluna mostra

- **Nome**
- **Imobiliária**
- **E-mail**
- **Condomínios**
- **Situação**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

