---
codigo: AP-081
titulo: Relatório Balanço Patrimonial
resumo: O que o condomínio tem e o que deve numa data: saldos bancários, contas a receber e a pagar, fundos e o resultado acumulado.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/balanco-patrimonial
menu: Prestação de contas > Balanço Patrimonial
icone: document-text-outline
situacao: funciona
relacionados: saldo-inicial-plano-de-contas, demonstracao-do-resultado, saldos-de-contas
palavras: balanço patrimonial, ativo, passivo, patrimônio líquido, fundo de reserva, prestação de contas, AP-081
rascunho: nao
---

## Para que serve

Mostra a posição do condomínio numa data: o **Ativo** (dinheiro nas contas bancárias e contas a receber), o **Passivo** (contas a pagar e fundos) e o **Patrimônio líquido** (resultados acumulados e o resultado do ano). O balanço é calculado na hora, a partir do que o sistema já registra; não há lançamento próprio.

## Onde fica

No menu lateral, abra [[Prestação de contas > Balanço Patrimonial]].

```tela balanco-patrimonial
rota: /prestacao-de-contas/balanco-patrimonial
condominio: residencial-jardim-das-acacias
legenda: Tela Balanço Patrimonial
1: menu | Balanço Patrimonial no menu lateral
2: main :is(a,button):has-text("Exportar") | Botão {{Exportar CSV}}
3: main form.erp-filters | Data do balanço
4: main .erp-grid--kpi | Totais do balanço
```

>! O balanço é de **um condomínio**. Escolha o condomínio na barra superior; com "Todos os condomínios", a tela pede a escolha.

## Como usar

1. Escolha a **Data do balanço** (até hoje) e clique em {{Gerar}}. A tela abre na data de hoje.
2. Confira o selo no alto: **Fechado** quando o ativo é igual a passivo + patrimônio líquido, ou **Diferença de R$ …** quando não fecha.
3. Leia os totais e as três tabelas. Abaixo do **Disponível** aparecem as contas bancárias, com o saldo de cada uma na data.
4. Use {{Imprimir}} para levar à reunião ou {{Exportar CSV}} para abrir na planilha.

### O que cada coluna mostra

- **Código** e **Conta**: a conta do plano; as contas que somam outras ficam em negrito.
- **Vencido**: em contas a receber e a pagar, quanto já passou do vencimento na data.
- **Saldo**: o valor da conta na data.

## Quando o balanço não fecha

A diferença costuma vir de saldo inicial que falta. Lance o saldo das contas bancárias e dos resultados acumulados (9.1) na [Saldo inicial (plano de contas)](artigo:saldo-inicial-plano-de-contas). Os fundos (8.2, 8.3 e contas próprias do condomínio) também só mudam pelo saldo inicial, porque os lançamentos são sempre de receita ou despesa.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Prestação de contas** e mostra os dados para quem também pode ver o plano de contas. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda exportação fica registrada no histórico.
