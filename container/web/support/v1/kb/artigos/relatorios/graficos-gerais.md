---
codigo: AP-102
titulo: Gráfico Gráficos gerais
resumo: Receitas e despesas previstas por competência, o resultado do mês e o que venceu e segue em aberto.
categoria: relatorios
rota: /relatorios/graficos-gerais
menu: Relatórios > Gráficos > Gráficos gerais
icone: bar-chart-outline
situacao: funciona
palavras: gráficos gerais, relatórios, AP-102
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Relatórios > Gráficos > Gráficos gerais]].

```tela graficos-gerais
rota: /relatorios/graficos-gerais
condominio: residencial-jardim-das-acacias
legenda: Tela Gráficos gerais
1: menu | Gráficos gerais no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form[method=get] | Filtros: De, Até
4: main table thead | Lista com Mês, Receitas, Despesas, Resultado e Vencido em aberto
```

## Como ler o gráfico

1. Escolha de e até.
2. Clique em {{Gerar}}.
3. Confira o resultado na tela.

### O que cada coluna mostra

- **Mês**
- **Receitas**
- **Despesas**
- **Resultado**
- **Vencido em aberto**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

