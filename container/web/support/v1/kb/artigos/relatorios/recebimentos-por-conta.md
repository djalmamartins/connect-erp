---
codigo: AP-072
titulo: Recebimentos por conta
resumo: Receitas pagas no período agrupadas por conta do plano ou por conta bancária, com quantos foram pagos com atraso.
categoria: relatorios
rota: /relatorios/recebimentos-por-conta
menu: Relatórios > Financeiros > Recebimentos por Conta
icone: document-text-outline
situacao: funciona
relacionados: recebimentos, saldos-de-contas, relatorio-de-boletos, registro-de-pagamentos
palavras: recebimentos por conta, relatórios, AP-072
rascunho: nao
---

Receitas pagas no período agrupadas por conta do plano ou por conta bancária, com quantos foram pagos com atraso.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Recebimentos por Conta]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela recebimentos-por-conta
rota: /relatorios/recebimentos-por-conta
condominio: residencial-jardim-das-acacias
legenda: Tela Recebimentos por Conta
1: menu | Recebimentos por Conta no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: De, Até e Agrupar por
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De**, **Até** e **Agrupar por**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Condomínio**, **Conta**, **Recebimentos**, **Pagos com atraso** e **Valor**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de recebimentos](artigo:recebimentos), [Saldos das contas bancárias](artigo:saldos-de-contas) e [Relatório de boletos](artigo:relatorio-de-boletos).
