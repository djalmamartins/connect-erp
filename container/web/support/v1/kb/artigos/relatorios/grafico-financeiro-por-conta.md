---
codigo: AP-108
titulo: Gráfico Gráfico financeiro por conta
resumo: Saldo de cada mês (entradas − saídas, com as transferências) por conta bancária.
categoria: relatorios
rota: /relatorios/grafico-financeiro-por-conta
menu: Relatórios > Gráficos > Gráfico financeiro por conta
icone: bar-chart-outline
situacao: funciona
palavras: gráfico financeiro por conta, relatórios, AP-108
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Relatórios > Gráficos > Gráfico financeiro por conta]].

```tela grafico-financeiro-por-conta
rota: /relatorios/grafico-financeiro-por-conta
condominio: residencial-jardim-das-acacias
legenda: Tela Gráfico financeiro por conta
1: menu | Gráfico financeiro por conta no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form[method=get] | Filtros: De, Até
4: main table thead | Lista com Mês e Conta movimento
```

## Como ler o gráfico

1. Escolha de e até.
2. Clique em {{Gerar}}.
3. Confira o resultado na tela.

### O que cada coluna mostra

- **Mês**
- **Conta movimento**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

