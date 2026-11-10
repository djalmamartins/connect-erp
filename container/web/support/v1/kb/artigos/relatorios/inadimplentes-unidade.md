---
codigo: AP-058
titulo: Inadimplentes por unidade
resumo: Cada unidade em atraso na data de referência, com o número de contas, valor, encargos, a conta mais antiga e o maior atraso. É a lista para a cobrança.
categoria: relatorios
rota: /relatorios/inadimplentes-unidade
menu: Relatórios > Financeiros > Inadimplentes (Unidade)
icone: document-text-outline
situacao: funciona
relacionados: inadimplentes-geral, inadimplentes-detalhado, comparativo, inadimplentes-taxa
palavras: inadimplentes (unidade), relatórios, AP-058
rascunho: nao
---

Cada unidade em atraso na data de referência, com o número de contas, valor, encargos, a conta mais antiga e o maior atraso. É a lista para a cobrança.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Inadimplentes (Unidade)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela inadimplentes-unidade
rota: /relatorios/inadimplentes-unidade
condominio: residencial-jardim-das-acacias
legenda: Tela Inadimplentes (Unidade)
1: menu | Inadimplentes (Unidade) no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Data de referência
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Data de referência**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Condomínio**, **Unidade**, **Contas**, **Valor**, **Encargos**, **Total**, **Mais antiga** e **Maior atraso**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Inadimplentes por condomínio](artigo:inadimplentes-geral), [Inadimplentes detalhado (conta a conta)](artigo:inadimplentes-detalhado) e [Comparativo entre períodos](artigo:comparativo).
