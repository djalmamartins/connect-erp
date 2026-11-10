---
codigo: AP-037
titulo: Remessas
resumo: Como usar a tela Remessas do Connect ERP: onde fica no menu, o que cada parte mostra e o passo a passo.
categoria: cobranca
rota: /cobranca/remessas
menu: Cobrança > Remessas
icone: list-outline
situacao: sem-backend
palavras: remessas, cobrança, AP-037
rascunho: nao
---

>! Esta tela está **em implantação**: ela mostra como vai funcionar, com dados de exemplo, e ainda não grava nada.

## Onde fica

No menu lateral, abra [[Cobrança > Remessas]].

```tela remessas
rota: /cobranca/remessas
condominio: residencial-jardim-das-acacias
legenda: Tela Remessas
1: menu | Remessas no menu lateral
2: main :is(a,button):has-text("Nova remessa") | Botão {{Nova remessa}}
3: main :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Condomínio, De, Até, Situação
5: main table thead | Lista com Arquivo, Banco, Condomínio, Boletos, Gerada em e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como usar

Os botões no alto da tela são {{Exportar}} e {{Nova remessa}}.

1. Ajuste os filtros (Condomínio, De, Até e Situação).
2. Clique em {{Filtrar}}.
3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.

### O que cada coluna mostra

- **Arquivo**
- **Banco**
- **Condomínio**
- **Boletos**
- **Gerada em**
- **Situação**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cobrança**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

