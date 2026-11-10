---
codigo: AP-062
titulo: Rateio das despesas
resumo: As despesas da competência rateadas pela fração ideal de cada unidade, sem perder centavo: a soma das partes é igual ao total.
categoria: relatorios
rota: /relatorios/rateio
menu: Relatórios > Financeiros > Rateio
icone: document-text-outline
situacao: funciona
relacionados: inadimplentes-morador, rateio-comparativo, inadimplentes-taxa, rateio-detalhado
palavras: rateio, relatórios, AP-062
rascunho: nao
---

As despesas da competência rateadas pela fração ideal de cada unidade, sem perder centavo: a soma das partes é igual ao total.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Rateio]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela rateio
rota: /relatorios/rateio
condominio: residencial-jardim-das-acacias
legenda: Tela Rateio
1: menu | Rateio no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Competência
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Competência**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Unidade**, **Fração ideal** e **Valor**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Inadimplentes por responsável](artigo:inadimplentes-morador), [Rateio comparativo](artigo:rateio-comparativo) e [Inadimplentes por taxa](artigo:inadimplentes-taxa).
