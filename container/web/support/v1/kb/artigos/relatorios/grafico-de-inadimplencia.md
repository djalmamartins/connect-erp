---
codigo: AP-107
titulo: Gráfico de inadimplência
resumo: Por competência: o que foi cobrado das unidades, o que venceu e segue em aberto hoje e a taxa de inadimplência.
categoria: relatorios
rota: /relatorios/grafico-de-inadimplencia
menu: Relatórios > Gráficos > Gráfico de inadimplência
icone: bar-chart-outline
situacao: funciona
relacionados: grafico-financeiro, grafico-financeiro-por-conta, grafico-de-consumo-geral, grafico-de-areas-comuns
palavras: gráfico de inadimplência, relatórios, AP-107
rascunho: nao
---

Por competência: o que foi cobrado das unidades, o que venceu e segue em aberto hoje e a taxa de inadimplência.

## Onde fica

No menu lateral, abra [[Relatórios > Gráficos > Gráfico de inadimplência]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela grafico-de-inadimplencia
rota: /relatorios/grafico-de-inadimplencia
condominio: residencial-jardim-das-acacias
legenda: Tela Gráfico de inadimplência
1: menu | Gráfico de inadimplência no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De e Até
4: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De** e **Até**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **MÊS**, **Cobrado das unidades**, **Vencido em aberto hoje** e **Inadimplência (%)**.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Gráfico financeiro (caixa)](artigo:grafico-financeiro), [Gráfico financeiro por conta bancária](artigo:grafico-financeiro-por-conta) e [Gráfico de consumo geral](artigo:grafico-de-consumo-geral).
