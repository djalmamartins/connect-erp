---
codigo: AP-054
titulo: Demonstrativo por situação
resumo: Receitas e despesas das competências separadas por situação: pago, a vencer, vencido e cancelado.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/demonstrativo-situacao
menu: Prestação de contas > Demonstrativo Situação
icone: document-text-outline
situacao: funciona
relacionados: demonstrativo-analitico, extrato-conta, demonstrativo-contas, balancete-verificacao
palavras: demonstrativo situação, prestação de contas, AP-054
rascunho: nao
---

Receitas e despesas das competências separadas por situação: pago, a vencer, vencido e cancelado.

## Onde fica

No menu lateral, abra [[Prestação de contas > Demonstrativo Situação]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela demonstrativo-situacao
rota: /prestacao-de-contas/demonstrativo-situacao
condominio: residencial-jardim-das-acacias
legenda: Tela Demonstrativo Situação
1: menu | Demonstrativo Situação no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Competência inicial e Competência final
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Competência inicial** e **Competência final**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Natureza**, **Situação**, **Lançamentos** e **Valor**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Prestação de contas**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Demonstrativo analítico](artigo:demonstrativo-analitico), [Extrato da conta bancária](artigo:extrato-conta) e [Demonstrativo por conta](artigo:demonstrativo-contas).
