---
codigo: AP-049
titulo: Relatório de centros de custo
resumo: Previsto (pela competência), realizado (pago) e em aberto de cada centro de custo.
categoria: relatorios
rota: /relatorios/relatorio-de-centros-de-custo
menu: Relatórios > Financeiros > Relatório de centros de custo
icone: document-text-outline
situacao: funciona
relacionados: taxas-de-acordos, comparativo, relatorio-de-acordos, inadimplentes-geral
palavras: relatório de centros de custo, relatórios, AP-049
rascunho: nao
---

Previsto (pela competência), realizado (pago) e em aberto de cada centro de custo.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Relatório de centros de custo]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela relatorio-de-centros-de-custo
rota: /relatorios/relatorio-de-centros-de-custo
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de centros de custo
1: menu | Relatório de centros de custo no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Competência inicial e Competência final
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Competência inicial** e **Competência final**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Condomínio**, **Natureza**, **Código**, **Centro de custo**, **Previsto**, **Realizado** e **Em aberto**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Taxas de acordos](artigo:taxas-de-acordos), [Comparativo entre períodos](artigo:comparativo) e [Relatório de acordos](artigo:relatorio-de-acordos).
