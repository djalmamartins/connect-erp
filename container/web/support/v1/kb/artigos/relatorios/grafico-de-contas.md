---
codigo: AP-103
titulo: Gráfico Gráfico de contas
resumo: Valores por conta do plano, na competência. As oito maiores aparecem; as demais somam em "Outras".
categoria: relatorios
rota: /relatorios/grafico-de-contas
menu: Relatórios > Gráficos > Gráfico de contas
icone: bar-chart-outline
situacao: funciona
palavras: gráfico de contas, relatórios, AP-103
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Relatórios > Gráficos > Gráfico de contas]].

```tela grafico-de-contas
rota: /relatorios/grafico-de-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Gráfico de contas
1: menu | Gráfico de contas no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form[method=get] | Filtros: De, Até, Natureza
4: main table thead | Lista com Mês, 2.01 pessoal, 2.02 água e esgoto e 2.03 manutenção
```

## Como ler o gráfico

1. Escolha de, até e natureza.
2. Clique em {{Gerar}}.
3. Confira o resultado na tela.

### O que cada coluna mostra

- **Mês**
- **2.01 pessoal**
- **2.02 água e esgoto**
- **2.03 manutenção**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

