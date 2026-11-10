---
codigo: AP-057
titulo: Inadimplentes por condomínio
resumo: Total em atraso de cada condomínio na data de referência: unidades, contas, valor, encargos sugeridos pela regra de cobrança e o maior atraso. Bom para ver a carteira inteira.
categoria: relatorios
rota: /relatorios/inadimplentes-geral
menu: Relatórios > Financeiros > Inadimplentes (Geral)
icone: document-text-outline
situacao: funciona
relacionados: comparativo, inadimplentes-unidade, relatorio-de-centros-de-custo, inadimplentes-detalhado
palavras: inadimplentes (geral), relatórios, AP-057
rascunho: nao
---

Total em atraso de cada condomínio na data de referência: unidades, contas, valor, encargos sugeridos pela regra de cobrança e o maior atraso. Bom para ver a carteira inteira.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Inadimplentes (Geral)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela inadimplentes-geral
rota: /relatorios/inadimplentes-geral
condominio: residencial-jardim-das-acacias
legenda: Tela Inadimplentes (Geral)
1: menu | Inadimplentes (Geral) no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Data de referência
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Data de referência**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Condomínio**, **Unidades**, **Contas**, **Valor**, **Encargos**, **Total** e **Maior atraso**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Comparativo entre períodos](artigo:comparativo), [Inadimplentes por unidade](artigo:inadimplentes-unidade) e [Relatório de centros de custo](artigo:relatorio-de-centros-de-custo).
