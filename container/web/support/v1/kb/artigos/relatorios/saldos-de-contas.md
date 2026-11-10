---
codigo: AP-073
titulo: Saldos das contas bancárias
resumo: O saldo de cada conta bancária na data: saldo do dia anterior, entradas, saídas do dia e saldo final.
categoria: relatorios
rota: /relatorios/saldos-de-contas
menu: Relatórios > Financeiros > Saldos de Contas
icone: document-text-outline
situacao: funciona
relacionados: recebimentos-por-conta, registro-de-pagamentos, recebimentos, relatorio-memoria-de-calculo
palavras: saldos de contas, relatórios, AP-073
rascunho: nao
---

O saldo de cada conta bancária na data: saldo do dia anterior, entradas, saídas do dia e saldo final.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Saldos de Contas]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela saldos-de-contas
rota: /relatorios/saldos-de-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Saldos de Contas
1: menu | Saldos de Contas no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Data de referência
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Data de referência**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Condomínio**, **Conta bancária**, **Saldo anterior**, **Entradas**, **Saídas** e **Saldo final**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Recebimentos por conta](artigo:recebimentos-por-conta), [Registro de pagamentos](artigo:registro-de-pagamentos) e [Relatório de recebimentos](artigo:recebimentos).
