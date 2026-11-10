---
codigo: CN-001
titulo: Vistorias
resumo: Visitas presenciais aos condomínios com check-in por GPS, checklist, fotos e relatório.
categoria: operacional
rota: /operacional/vistorias
menu: Operacional > Rotina > Vistorias
icone: list-outline
situacao: funciona
palavras: vistorias, operacional, CN-001
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Vistorias]].

```tela vistorias
rota: /operacional/vistorias
condominio: residencial-jardim-das-acacias
legenda: Tela Vistorias
1: menu | Vistorias no menu lateral
2: main :is(a,button):has-text("Nova vistoria") | Botão {{Nova vistoria}}
3: main :is(a,button):has-text("Configurar") | Botão {{Configurar}}
4: main form[method=get] | Filtros: Condomínio, Situação
5: main .erp-card | Programação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como usar

Os botões no alto da tela são {{Configurar}} e {{Nova vistoria}}.

1. Ajuste os filtros (Condomínio e Situação).
2. Clique em {{Filtrar}}.
3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

