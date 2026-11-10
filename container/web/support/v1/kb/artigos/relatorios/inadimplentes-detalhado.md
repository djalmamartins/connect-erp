---
codigo: AP-059
titulo: Inadimplentes detalhado (conta a conta)
resumo: Cada conta vencida e em aberto na data de referência, com competência, vencimento, dias de atraso, valor e encargos.
categoria: relatorios
rota: /relatorios/inadimplentes-detalhado
menu: Relatórios > Financeiros > Inadimplentes (Detalhado)
icone: document-text-outline
situacao: funciona
relacionados: inadimplentes-unidade, inadimplentes-taxa, inadimplentes-geral, inadimplentes-morador
palavras: inadimplentes (detalhado), relatórios, AP-059
rascunho: nao
---

Cada conta vencida e em aberto na data de referência, com competência, vencimento, dias de atraso, valor e encargos.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Inadimplentes (Detalhado)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela inadimplentes-detalhado
rota: /relatorios/inadimplentes-detalhado
condominio: residencial-jardim-das-acacias
legenda: Tela Inadimplentes (Detalhado)
1: menu | Inadimplentes (Detalhado) no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Data de referência
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Data de referência**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Condomínio**, **Unidade**, **Descrição**, **Competência**, **Vencimento**, **Atraso**, **Valor**, **Encargos** e **Total**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Inadimplentes por unidade](artigo:inadimplentes-unidade), [Inadimplentes por taxa](artigo:inadimplentes-taxa) e [Inadimplentes por condomínio](artigo:inadimplentes-geral).
