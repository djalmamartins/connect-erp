---
codigo: AP-042
titulo: Leituras
resumo: Lance as leituras do mês de todos os relógios de uma vez, confira o consumo e o valor de cada unidade e gere as contas a receber.
categoria: operacional
rota: /operacional/leituras
menu: Operacional > Leituras e consumo > Leituras
icone: list-outline
situacao: funciona
palavras: leituras, operacional, AP-042
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Leituras e consumo > Leituras]].

```tela leituras
rota: /operacional/leituras
condominio: residencial-jardim-das-acacias
legenda: Tela Leituras
1: menu | Leituras no menu lateral
2: main :is(a,button):has-text("Tarifas") | Botão {{Tarifas}}
3: main :is(a,button):has-text("Relógios") | Botão {{Relógios}}
4: main form[method=get] | Filtros: Competência, Tipo
5: main .erp-card | Relógios lidos
```

## Como usar

Os botões no alto da tela são {{Relógios}} e {{Tarifas}}.

1. Ajuste os filtros (Competência e Tipo).
2. Clique em {{Filtrar}}.
3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

