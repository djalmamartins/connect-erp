---
codigo: AP-050
titulo: Comparativo entre períodos
resumo: Duas faixas de competência lado a lado, por conta do plano, com a diferença e a variação. Use para comparar este ano com o ano passado, por exemplo.
categoria: relatorios
rota: /relatorios/comparativo
menu: Relatórios > Financeiros > Comparativo
icone: document-text-outline
situacao: funciona
relacionados: relatorio-de-centros-de-custo, inadimplentes-geral, taxas-de-acordos, inadimplentes-unidade
palavras: comparativo, relatórios, AP-050
rascunho: nao
---

Duas faixas de competência lado a lado, por conta do plano, com a diferença e a variação. Use para comparar este ano com o ano passado, por exemplo.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Comparativo]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela comparativo
rota: /relatorios/comparativo
condominio: residencial-jardim-das-acacias
legenda: Tela Comparativo
1: menu | Comparativo no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Período A: de, Período A: até, Período B: de e Período B: até
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Período A: de**, **Período A: até**, **Período B: de** e **Período B: até**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Código**, **Conta**, **Natureza**, **Período a**, **Período b**, **Diferença** e **Variação**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de centros de custo](artigo:relatorio-de-centros-de-custo), [Inadimplentes por condomínio](artigo:inadimplentes-geral) e [Taxas de acordos](artigo:taxas-de-acordos).
