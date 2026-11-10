---
codigo: AP-107
titulo: Gráfico Gráfico de inadimplência
resumo: Por competência: o cobrado das unidades, o que venceu e segue em aberto hoje e a taxa de inadimplência.
categoria: relatorios
rota: /relatorios/grafico-de-inadimplencia
menu: Relatórios > Gráficos > Gráfico de inadimplência
icone: bar-chart-outline
situacao: funciona
palavras: gráfico de inadimplência, relatórios, AP-107
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Relatórios > Gráficos > Gráfico de inadimplência]].

```tela grafico-de-inadimplencia
rota: /relatorios/grafico-de-inadimplencia
condominio: residencial-jardim-das-acacias
legenda: Tela Gráfico de inadimplência
1: menu | Gráfico de inadimplência no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form[method=get] | Filtros: De, Até
4: main table thead | Lista com Mês, Cobrado das unidades, Vencido em aberto hoje e Inadimplência (%)
```

## Como ler o gráfico

1. Escolha de e até.
2. Clique em {{Gerar}}.
3. Confira o resultado na tela.

### O que cada coluna mostra

- **Mês**
- **Cobrado das unidades**
- **Vencido em aberto hoje**
- **Inadimplência (%)**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

