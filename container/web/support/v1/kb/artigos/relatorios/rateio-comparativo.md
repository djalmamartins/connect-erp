---
codigo: AP-063
titulo: Rateio comparativo
resumo: O rateio de duas competências lado a lado, por unidade, com a diferença e a variação.
categoria: relatorios
rota: /relatorios/rateio-comparativo
menu: Relatórios > Financeiros > Rateio Comparativo
icone: document-text-outline
situacao: funciona
relacionados: rateio, rateio-detalhado, inadimplentes-morador, rateio-analitico
palavras: rateio comparativo, relatórios, AP-063
rascunho: nao
---

O rateio de duas competências lado a lado, por unidade, com a diferença e a variação.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Rateio Comparativo]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela rateio-comparativo
rota: /relatorios/rateio-comparativo
condominio: residencial-jardim-das-acacias
legenda: Tela Rateio Comparativo
1: menu | Rateio Comparativo no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Competência e Comparar com
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Competência** e **Comparar com**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Unidade**, **Fração ideal**, **Competência**, **Comparação**, **Diferença** e **Variação**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Rateio das despesas](artigo:rateio), [Rateio detalhado](artigo:rateio-detalhado) e [Inadimplentes por responsável](artigo:inadimplentes-morador).
