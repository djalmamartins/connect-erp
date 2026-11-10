---
codigo: AP-031
titulo: Pagamentos (Bancos)
resumo: Como usar a tela Pagamentos (Bancos) do Connect ERP: onde fica no menu, o que cada parte mostra e o passo a passo.
categoria: financeiro
rota: /financeiro/pagamentos-bancos
menu: Financeiro > Contas e pagamentos > Pagamentos (Bancos)
icone: list-outline
situacao: sem-backend
palavras: pagamentos (bancos), financeiro, AP-031
rascunho: nao
---

>! Esta tela está **em implantação**: ela mostra como vai funcionar, com dados de exemplo, e ainda não grava nada.

## Onde fica

No menu lateral, abra [[Financeiro > Contas e pagamentos > Pagamentos (Bancos)]].

```tela pagamentos-bancos
rota: /financeiro/pagamentos-bancos
condominio: residencial-jardim-das-acacias
legenda: Tela Pagamentos (Bancos)
1: menu | Pagamentos (Bancos) no menu lateral
2: main :is(a,button):has-text("Novo pagamento") | Botão {{Novo pagamento}}
3: main :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Condomínio, De, Até, Situação
5: main table thead | Lista com Data, Conta bancária, Favorecido, Condomínio, Valor e Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como usar

Os botões no alto da tela são {{Exportar}} e {{Novo pagamento}}.

1. Ajuste os filtros (Condomínio, De, Até e Situação).
2. Clique em {{Filtrar}}.
3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.

### O que cada coluna mostra

- **Data**
- **Conta bancária**
- **Favorecido**
- **Condomínio**
- **Valor**
- **Situação**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

