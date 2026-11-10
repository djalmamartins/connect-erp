---
codigo: AP-049
titulo: Relatório Relatório de centros de custo
resumo: Escolha o condomínio e o período. Período e critério aparecem na impressão e na exportação.
categoria: relatorios
rota: /relatorios/relatorio-de-centros-de-custo
menu: Relatórios > Financeiros > Relatório de centros de custo
icone: document-text-outline
situacao: exemplo
palavras: relatório de centros de custo, relatórios, AP-049
rascunho: nao
---

>! Esta tela ainda mostra **dados de exemplo**. Ela já abre com os filtros e as colunas definitivos, mas os números reais chegam numa próxima versão. Use-a para conhecer o relatório; não tome decisões com os valores mostrados.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Relatório de centros de custo]].

```tela relatorio-de-centros-de-custo
rota: /relatorios/relatorio-de-centros-de-custo
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de centros de custo
1: menu | Relatório de centros de custo no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Condomínio, De, Até, Critério, Pesquisar
5: main table thead | Lista com Conta, Previsto, Realizado, Diferença e Execução
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como gerar o relatório

1. Escolha condomínio, de, até, critério e pesquisar.
2. Clique em {{Filtrar}}.
3. Confira o resultado na tela.

### O que cada coluna mostra

- **Conta**
- **Previsto**
- **Realizado**
- **Diferença**
- **Execução**

## Imprimir ou exportar

Use {{Imprimir}} e {{Exportar}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

