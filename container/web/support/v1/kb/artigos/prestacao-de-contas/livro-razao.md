---
codigo: AP-083
titulo: Relatório Livro Razão
resumo: Escolha o condomínio e o período. Período e critério aparecem na impressão e na exportação.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/livro-razao
menu: Prestação de contas > Livro Razão
icone: document-text-outline
situacao: sem-backend
palavras: livro razão, prestação de contas, AP-083
rascunho: nao
---

>! Esta tela está **em implantação**: ela mostra como vai funcionar, com dados de exemplo, e ainda não grava nada.

## Onde fica

No menu lateral, abra [[Prestação de contas > Livro Razão]].

```tela livro-razao
rota: /prestacao-de-contas/livro-razao
condominio: residencial-jardim-das-acacias
legenda: Tela Livro Razão
1: menu | Livro Razão no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Condomínio, De, Até, Critério, Pesquisar
5: main table thead | Lista com Data, Descrição, Conta, Tipo e Valor
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como gerar o relatório

1. Escolha condomínio, de, até, critério e pesquisar.
2. Clique em {{Filtrar}}.
3. Confira o resultado na tela.

### O que cada coluna mostra

- **Data**
- **Descrição**
- **Conta**
- **Tipo**
- **Valor**

## Imprimir ou exportar

Use {{Imprimir}} e {{Exportar}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Prestação de contas**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

