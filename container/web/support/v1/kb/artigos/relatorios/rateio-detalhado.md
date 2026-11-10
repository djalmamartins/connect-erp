---
codigo: AP-064
titulo: Rateio detalhado
resumo: A parte de cada unidade em cada conta do plano.
categoria: relatorios
rota: /relatorios/rateio-detalhado
menu: Relatórios > Financeiros > Rateio Detalhado
icone: document-text-outline
situacao: funciona
relacionados: rateio-comparativo, rateio-analitico, rateio, rateio-analitico-detalhado
palavras: rateio detalhado, relatórios, AP-064
rascunho: nao
---

A parte de cada unidade em cada conta do plano.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Rateio Detalhado]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela rateio-detalhado
rota: /relatorios/rateio-detalhado
condominio: residencial-jardim-das-acacias
legenda: Tela Rateio Detalhado
1: menu | Rateio Detalhado no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Competência
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Competência**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Unidade**, **Código**, **Conta** e **Valor**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Rateio comparativo](artigo:rateio-comparativo), [Rateio analítico de uma unidade](artigo:rateio-analitico) e [Rateio das despesas](artigo:rateio).
