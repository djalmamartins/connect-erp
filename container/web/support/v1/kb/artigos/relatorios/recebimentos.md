---
codigo: AP-071
titulo: Relatório de recebimentos
resumo: Receitas pagas no período, pela data do pagamento, com atraso e conta bancária.
categoria: relatorios
rota: /relatorios/recebimentos
menu: Relatórios > Financeiros > Recebimentos
icone: document-text-outline
situacao: funciona
relacionados: relatorio-de-boletos, recebimentos-por-conta, relatorio-de-lancamentos-detalhado, saldos-de-contas
palavras: recebimentos, relatórios, AP-071
rascunho: nao
---

Receitas pagas no período, pela data do pagamento, com atraso e conta bancária.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Recebimentos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela recebimentos
rota: /relatorios/recebimentos
condominio: residencial-jardim-das-acacias
legenda: Tela Recebimentos
1: menu | Recebimentos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Download") | Botão {{Download}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: De e Até
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De** e **Até**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Condomínio**, **Unidade**, **Descrição**, **Competência**, **Vencimento**, **Pagamento**, **Atraso**, **Valor** e **Conta bancária**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Download}} baixa os dados filtrados em PDF (com o cabeçalho da Connect, o condomínio, o título, a data e o número da página), em Excel ou em CSV.

## Ocultar unidade

Marque **Ocultar unidade** nos filtros e clique em {{Gerar}} para mostrar os valores sem dizer qual unidade pagou ou deve, por exemplo para mandar ao grupo dos moradores. A coluna Unidade sai da tela, da impressão e do {{Download}} (PDF, Excel ou CSV).

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de boletos](artigo:relatorio-de-boletos), [Recebimentos por conta](artigo:recebimentos-por-conta) e [Relatório de lançamentos detalhado](artigo:relatorio-de-lancamentos-detalhado).
