---
codigo: AP-104
titulo: Gráfico Gráfico de consumo (unidade)
resumo: Consumo medido da unidade, por tipo de medidor e competência.
categoria: relatorios
rota: /relatorios/grafico-de-consumo-unidade
menu: Relatórios > Gráficos > Gráfico de consumo (unidade)
icone: bar-chart-outline
situacao: funciona
palavras: gráfico de consumo (unidade), relatórios, AP-104
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Relatórios > Gráficos > Gráfico de consumo (unidade)]].

```tela grafico-de-consumo-unidade
rota: /relatorios/grafico-de-consumo-unidade
condominio: residencial-jardim-das-acacias
legenda: Tela Gráfico de consumo (unidade)
1: menu | Gráfico de consumo (unidade) no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form[method=get] | Filtros: De, Até, Unidade
```

## Como ler o gráfico

1. Escolha de, até e unidade.
2. Clique em {{Gerar}}.
3. Confira o resultado na tela.

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

