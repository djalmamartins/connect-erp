---
codigo: AP-070
titulo: Relatório de boletos
resumo: Boletos com vencimento no período, com o valor emitido, a data do pagamento e o valor pago.
categoria: relatorios
rota: /relatorios/relatorio-de-boletos
menu: Relatórios > Financeiros > Relatório de boletos
icone: document-text-outline
situacao: funciona
relacionados: relatorio-de-lancamentos-detalhado, recebimentos, relatorio-de-lancamentos, recebimentos-por-conta
palavras: relatório de boletos, relatórios, AP-070
rascunho: nao
---

Boletos com vencimento no período, com o valor emitido, a data do pagamento e o valor pago.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Relatório de boletos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela relatorio-de-boletos
rota: /relatorios/relatorio-de-boletos
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de boletos
1: menu | Relatório de boletos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: De, Até e Situação
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De**, **Até** e **Situação**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Condomínio**, **Nosso número**, **Situação**, **Unidade**, **Descrição**, **Conta bancária**, **Vencimento**, **Valor**, **Pagamento** e **Valor pago**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de lançamentos detalhado](artigo:relatorio-de-lancamentos-detalhado), [Relatório de recebimentos](artigo:recebimentos) e [Relatório de lançamentos](artigo:relatorio-de-lancamentos).
