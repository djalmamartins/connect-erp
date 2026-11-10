---
codigo: AP-052
titulo: Relatório Demonstrativo Contas
resumo: Escolha o condomínio e o período. Período e critério aparecem na impressão e na exportação.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/demonstrativo-contas
menu: Prestação de contas > Demonstrativo Contas
icone: document-text-outline
situacao: exemplo
palavras: demonstrativo contas, prestação de contas, AP-052
rascunho: nao
---

>! Esta tela ainda mostra **dados de exemplo**. Ela já abre com os filtros e as colunas definitivos, mas os números reais chegam numa próxima versão. Use-a para conhecer o relatório; não tome decisões com os valores mostrados.

## Onde fica

No menu lateral, abra [[Prestação de contas > Demonstrativo Contas]].

```tela demonstrativo-contas
rota: /prestacao-de-contas/demonstrativo-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Demonstrativo Contas
1: menu | Demonstrativo Contas no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Condomínio, De, Até, Critério, Pesquisar
5: main table thead | Lista com Conta, Saldo anterior, Entradas, Saídas e Saldo final
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como gerar o relatório

1. Escolha condomínio, de, até, critério e pesquisar.
2. Clique em {{Filtrar}}.
3. Confira o resultado na tela.

### O que cada coluna mostra

- **Conta**
- **Saldo anterior**
- **Entradas**
- **Saídas**
- **Saldo final**

## Imprimir ou exportar

Use {{Imprimir}} e {{Exportar}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Prestação de contas**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

