---
codigo: AP-105
titulo: Gráfico Gráfico de consumo (geral)
resumo: Consumo medido por tipo de medidor e competência, somando as unidades.
categoria: relatorios
rota: /relatorios/grafico-de-consumo-geral
menu: Relatórios > Gráficos > Gráfico de consumo (geral)
icone: bar-chart-outline
situacao: funciona
palavras: gráfico de consumo (geral), relatórios, AP-105
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Relatórios > Gráficos > Gráfico de consumo (geral)]].

```tela grafico-de-consumo-geral
rota: /relatorios/grafico-de-consumo-geral
condominio: residencial-jardim-das-acacias
legenda: Tela Gráfico de consumo (geral)
1: menu | Gráfico de consumo (geral) no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form[method=get] | Filtros: De, Até
```

## Como ler o gráfico

1. Escolha de e até.
2. Clique em {{Gerar}}.
3. Confira o resultado na tela.

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

