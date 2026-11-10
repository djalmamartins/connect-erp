---
codigo: AP-104
titulo: Gráfico de consumo da unidade
resumo: Consumo medido de uma unidade por tipo de medidor e competência. Bom para mostrar ao morador a evolução do consumo.
categoria: relatorios
rota: /relatorios/grafico-de-consumo-unidade
menu: Relatórios > Gráficos > Gráfico de consumo (unidade)
icone: bar-chart-outline
situacao: funciona
relacionados: grafico-de-contas, grafico-de-consumo-geral, graficos-gerais, grafico-financeiro
palavras: gráfico de consumo (unidade), relatórios, AP-104
rascunho: nao
---

Consumo medido de uma unidade por tipo de medidor e competência. Bom para mostrar ao morador a evolução do consumo.

## Onde fica

No menu lateral, abra [[Relatórios > Gráficos > Gráfico de consumo (unidade)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela grafico-de-consumo-unidade
rota: /relatorios/grafico-de-consumo-unidade
condominio: residencial-jardim-das-acacias
legenda: Tela Gráfico de consumo (unidade)
1: menu | Gráfico de consumo (unidade) no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De, Até e Unidade
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De**, **Até** e **Unidade**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

> Na tela: *Escolha a unidade O gráfico mostra o consumo dos medidores da unidade escolhida.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Gráfico de contas](artigo:grafico-de-contas), [Gráfico de consumo geral](artigo:grafico-de-consumo-geral) e [Gráficos gerais](artigo:graficos-gerais).
