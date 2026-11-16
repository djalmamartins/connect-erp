---
codigo: AP-101
titulo: Relatório Prestação de contas
resumo: O pacote do mês de um condomínio: resultado, saldos bancários, inadimplência no fim do mês e a situação do fechamento, para conferir e imprimir.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/prestacao-de-contas
menu: Prestação de contas > Prestação de contas
icone: document-text-outline
situacao: funciona
relacionados: demonstracao-do-resultado, balancete-verificacao, saldos-de-contas
palavras: prestação de contas, mensal, síndico, resultado, inadimplência, fechamento, AP-101
rascunho: nao
---

## Para que serve

Junta numa página o que o síndico e o conselho precisam ver do mês: receitas e despesas da competência, o resultado, as entradas e saídas do caixa, o saldo das contas bancárias, a inadimplência no último dia do mês e se o mês já foi fechado. Os avisos no alto mostram o que ainda pode mudar os números.

## Onde fica

No menu lateral, abra [[Prestação de contas > Prestação de contas]].

```tela prestacao-de-contas
rota: /prestacao-de-contas/prestacao-de-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Prestação de contas
1: menu | Prestação de contas no menu lateral
2: main form.erp-filters | Mês da prestação
3: main .erp-grid--kpi | Resumo do mês
```

>! A prestação é de **um condomínio**. Escolha o condomínio na barra superior; com "Todos os condomínios", a tela pede a escolha.

## Como usar

1. Escolha o **Mês** e clique em {{Gerar}}. A tela abre no mês anterior, que é o que costuma ser prestado.
2. Leia os avisos: mês ainda não fechado, receitas ou despesas da competência em aberto e contas vencidas no fim do mês.
3. Confira o resumo, a [Demonstração do resultado](artigo:demonstracao-do-resultado), os [Saldos de contas](artigo:saldos-de-contas) e a inadimplência por unidade.
4. Use {{Imprimir}} para levar à reunião. Cada parte tem o CSV no relatório de origem; os links ficam no título de cada bloco.

## Ocultar unidade

Marque **Ocultar unidade** nos filtros e clique em {{Gerar}} para mostrar os valores sem dizer qual unidade pagou ou deve, por exemplo para mandar ao grupo dos moradores. A coluna Unidade sai da lista de inadimplência, na tela e na impressão.

## O documento da prestação de contas (PDF)

A tabela **Prestações de contas dos últimos meses** lista os 12 últimos meses do condomínio, com a situação do fechamento e quantos pagamentos houve. Em cada mês, **Visualizar** abre o documento como página (para conferir e imprimir) e **PDF** abre o arquivo PDF numa aba do navegador; de lá você decide se baixa. No alto da tela, {{Visualizar documento}} e {{Abrir PDF}} fazem o mesmo para o mês escolhido.

O documento segue o padrão da Connect (cabeçalho com a Connect, o condomínio e a data; rodapé com o site e o número da página) e traz, cada parte numa página:

1. **Capa** com o condomínio e o período.
2. **Termo de abertura** com a razão social, o endereço, o município e o CNPJ do condomínio.
3. **Parecer do síndico e do conselho**, com os nomes do cadastro [[Cadastros > Síndico e conselho]] e as linhas para assinar.
4. **Demonstrativo financeiro**: receitas e despesas pagas no mês por grupo e conta, com o total de cada grupo, o resumo (receitas, despesas e resultado) e o **resumo das contas** bancárias (saldo anterior, entradas, saídas e saldo atual).
5. **Relatório de lançamentos**: as despesas pagas no mês e, para cada uma, a linha do lançamento seguida dos comprovantes anexados. Imagens entram dentro do PDF; anexo em PDF aparece citado (ele fica no pacote do livro caixa).

Com **Ocultar unidade** marcado, o documento também sai sem a unidade nas receitas.

## Quem pode usar

Exige as permissões **erp.entries.view** e **erp.chart.view**. Cada pessoa só vê os condomínios vinculados à própria conta.
