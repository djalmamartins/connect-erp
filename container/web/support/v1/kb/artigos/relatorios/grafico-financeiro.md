---
codigo: AP-106
titulo: Gráfico Gráfico financeiro
resumo: Recebido e pago por mês do pagamento (caixa) e o saldo de cada mês.
categoria: relatorios
rota: /relatorios/grafico-financeiro
menu: Relatórios > Gráficos > Gráfico financeiro
icone: bar-chart-outline
situacao: funciona
palavras: gráfico financeiro, relatórios, AP-106
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Relatórios > Gráficos > Gráfico financeiro]].

```tela grafico-financeiro
rota: /relatorios/grafico-financeiro
condominio: residencial-jardim-das-acacias
legenda: Tela Gráfico financeiro
1: menu | Gráfico financeiro no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form[method=get] | Filtros: De, Até
4: main table thead | Lista com Mês, Recebido, Pago e Saldo do mês
```

## Como ler o gráfico

1. Escolha de e até.
2. Clique em {{Gerar}}.
3. Confira o resultado na tela.

### O que cada coluna mostra

- **Mês**
- **Recebido**
- **Pago**
- **Saldo do mês**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

