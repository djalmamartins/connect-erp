---
codigo: AP-004
titulo: Condomínios
resumo: Condomínios administrados pela Connect aos quais você tem acesso.
categoria: condominios
rota: /condominios
menu: Condomínios > Condomínios
icone: list-outline
situacao: funciona
palavras: condomínios, AP-004
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Condomínios > Condomínios]].

```tela condominios
rota: /condominios
condominio: residencial-jardim-das-acacias
legenda: Tela Condomínios
1: menu | Condomínios no menu lateral
2: main :is(a,button):has-text("Novo condomínio") | Botão {{Novo condomínio}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Situação
4: main table thead | Lista com Nome, Cnpj e Situação
```

## Como usar

Os botões no alto da tela são {{Novo condomínio}}.

1. Ajuste os filtros (Pesquisar e Situação).
2. Clique em {{Filtrar}}.
3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.

### O que cada coluna mostra

- **Nome**
- **Cnpj**
- **Situação**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Condomínios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

