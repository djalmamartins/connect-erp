---
codigo: AP-068
titulo: Relatório de lançamentos
resumo: Os lançamentos das competências escolhidas, com a situação de hoje. Filtre por natureza (receita ou despesa) e situação.
categoria: relatorios
rota: /relatorios/relatorio-de-lancamentos
menu: Relatórios > Financeiros > Relatório de lançamentos
icone: document-text-outline
situacao: funciona
relacionados: rateio-por-conta, relatorio-de-lancamentos-detalhado, rateio-analitico-detalhado, relatorio-de-boletos
palavras: relatório de lançamentos, relatórios, AP-068
rascunho: nao
---

Os lançamentos das competências escolhidas, com a situação de hoje. Filtre por natureza (receita ou despesa) e situação.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Relatório de lançamentos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela relatorio-de-lancamentos
rota: /relatorios/relatorio-de-lancamentos
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de lançamentos
1: menu | Relatório de lançamentos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Competência inicial, Competência final, Natureza e Situação
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Competência inicial**, **Competência final**, **Natureza** e **Situação**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Condomínio**, **Natureza**, **Descrição**, **Código**, **Unidade**, **Competência**, **Vencimento**, **Pagamento**, **Situação** e **Valor**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Rateio por conta](artigo:rateio-por-conta), [Relatório de lançamentos detalhado](artigo:relatorio-de-lancamentos-detalhado) e [Rateio analítico detalhado](artigo:rateio-analitico-detalhado).
