---
codigo: AP-067
titulo: Rateio por conta
resumo: O rateio por conta do plano, com a maior e a menor parte de uma unidade.
categoria: relatorios
rota: /relatorios/rateio-por-conta
menu: Relatórios > Financeiros > Rateio por Conta
icone: document-text-outline
situacao: funciona
relacionados: rateio-analitico-detalhado, relatorio-de-lancamentos, rateio-analitico, relatorio-de-lancamentos-detalhado
palavras: rateio por conta, relatórios, AP-067
rascunho: nao
---

O rateio por conta do plano, com a maior e a menor parte de uma unidade.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Rateio por Conta]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela rateio-por-conta
rota: /relatorios/rateio-por-conta
condominio: residencial-jardim-das-acacias
legenda: Tela Rateio por Conta
1: menu | Rateio por Conta no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Competência
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Competência**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Código**, **Conta**, **Valor**, **Maior parte** e **Menor parte**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Rateio analítico detalhado](artigo:rateio-analitico-detalhado), [Relatório de lançamentos](artigo:relatorio-de-lancamentos) e [Rateio analítico de uma unidade](artigo:rateio-analitico).
