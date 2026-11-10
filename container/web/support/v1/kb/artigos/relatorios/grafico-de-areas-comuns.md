---
codigo: AP-109
titulo: Gráfico Gráfico de áreas comuns
resumo: Reservas não canceladas por área comum e mês. As oito áreas mais usadas aparecem; as demais somam em "Outras".
categoria: relatorios
rota: /relatorios/grafico-de-areas-comuns
menu: Relatórios > Gráficos > Gráfico de áreas comuns
icone: bar-chart-outline
situacao: funciona
palavras: gráfico de áreas comuns, relatórios, AP-109
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Relatórios > Gráficos > Gráfico de áreas comuns]].

```tela grafico-de-areas-comuns
rota: /relatorios/grafico-de-areas-comuns
condominio: residencial-jardim-das-acacias
legenda: Tela Gráfico de áreas comuns
1: menu | Gráfico de áreas comuns no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form[method=get] | Filtros: De, Até
4: main table thead | Lista com Mês, Salão de festas e Churrasqueira
```

## Como ler o gráfico

1. Escolha de e até.
2. Clique em {{Gerar}}.
3. Confira o resultado na tela.

### O que cada coluna mostra

- **Mês**
- **Salão de festas**
- **Churrasqueira**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

