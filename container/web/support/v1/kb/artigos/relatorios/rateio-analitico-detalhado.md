---
codigo: AP-066
titulo: Rateio analítico detalhado
resumo: Cada despesa da competência dividida entre todas as unidades.
categoria: relatorios
rota: /relatorios/rateio-analitico-detalhado
menu: Relatórios > Financeiros > Rateio Analítico (Detalhado)
icone: document-text-outline
situacao: funciona
relacionados: rateio-analitico, rateio-por-conta, rateio-detalhado, relatorio-de-lancamentos
palavras: rateio analítico (detalhado), relatórios, AP-066
rascunho: nao
---

Cada despesa da competência dividida entre todas as unidades.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Rateio Analítico (Detalhado)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela rateio-analitico-detalhado
rota: /relatorios/rateio-analitico-detalhado
condominio: residencial-jardim-das-acacias
legenda: Tela Rateio Analítico (Detalhado)
1: menu | Rateio Analítico (Detalhado) no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Competência
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Competência**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Unidade**, **Despesa**, **Código**, **Valor da despesa** e **Parte da unidade**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Rateio analítico de uma unidade](artigo:rateio-analitico), [Rateio por conta](artigo:rateio-por-conta) e [Rateio detalhado](artigo:rateio-detalhado).
