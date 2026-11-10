---
codigo: AP-102
titulo: Gráficos gerais
resumo: Receitas e despesas previstas por competência, o resultado de cada mês e o que venceu e segue em aberto, em gráfico e em tabela.
categoria: relatorios
rota: /relatorios/graficos-gerais
menu: Relatórios > Gráficos > Gráficos gerais
icone: bar-chart-outline
situacao: funciona
relacionados: grafico-de-contas, grafico-de-consumo-unidade, grafico-de-consumo-geral, grafico-financeiro
palavras: gráficos gerais, relatórios, AP-102
rascunho: nao
---

Receitas e despesas previstas por competência, o resultado de cada mês e o que venceu e segue em aberto, em gráfico e em tabela.

## Onde fica

No menu lateral, abra [[Relatórios > Gráficos > Gráficos gerais]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela graficos-gerais
rota: /relatorios/graficos-gerais
condominio: residencial-jardim-das-acacias
legenda: Tela Gráficos gerais
1: menu | Gráficos gerais no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De e Até
4: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De** e **Até**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **MÊS**, **Receitas**, **Despesas**, **Resultado** e **Vencido em aberto**.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Gráfico de contas](artigo:grafico-de-contas), [Gráfico de consumo da unidade](artigo:grafico-de-consumo-unidade) e [Gráfico de consumo geral](artigo:grafico-de-consumo-geral).
