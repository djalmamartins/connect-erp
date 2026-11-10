---
codigo: AP-105
titulo: Gráfico de consumo geral
resumo: Consumo medido por tipo de medidor e competência, somando todas as unidades.
categoria: relatorios
rota: /relatorios/grafico-de-consumo-geral
menu: Relatórios > Gráficos > Gráfico de consumo (geral)
icone: bar-chart-outline
situacao: funciona
relacionados: grafico-de-consumo-unidade, grafico-financeiro, grafico-de-contas, grafico-de-inadimplencia
palavras: gráfico de consumo (geral), relatórios, AP-105
rascunho: nao
---

Consumo medido por tipo de medidor e competência, somando todas as unidades.

## Onde fica

No menu lateral, abra [[Relatórios > Gráficos > Gráfico de consumo (geral)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela grafico-de-consumo-geral
rota: /relatorios/grafico-de-consumo-geral
condominio: residencial-jardim-das-acacias
legenda: Tela Gráfico de consumo (geral)
1: menu | Gráfico de consumo (geral) no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De e Até
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De** e **Até**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

> Na tela: *Sem dados no período Residencial Jardim das Acácias · de dez/25 a nov/26. Tente outro período ou outro condomínio.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Gráfico de consumo da unidade](artigo:grafico-de-consumo-unidade), [Gráfico financeiro (caixa)](artigo:grafico-financeiro) e [Gráfico de contas](artigo:grafico-de-contas).
