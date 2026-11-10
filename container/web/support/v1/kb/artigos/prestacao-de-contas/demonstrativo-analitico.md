---
codigo: AP-053
titulo: Demonstrativo analítico
resumo: Cada lançamento das competências escolhidas, agrupado pela conta do plano. Use quando o conselho pede o detalhe de uma conta.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/demonstrativo-analitico
menu: Prestação de contas > Demonstrativo Analítico
icone: document-text-outline
situacao: funciona
relacionados: demonstrativo-contas, demonstrativo-situacao, demonstrativo, extrato-conta
palavras: demonstrativo analítico, prestação de contas, AP-053
rascunho: nao
---

Cada lançamento das competências escolhidas, agrupado pela conta do plano. Use quando o conselho pede o detalhe de uma conta.

## Onde fica

No menu lateral, abra [[Prestação de contas > Demonstrativo Analítico]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela demonstrativo-analitico
rota: /prestacao-de-contas/demonstrativo-analitico
condominio: residencial-jardim-das-acacias
legenda: Tela Demonstrativo Analítico
1: menu | Demonstrativo Analítico no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Competência inicial e Competência final
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Competência inicial** e **Competência final**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Código**, **Conta**, **Natureza**, **Descrição**, **Unidade**, **Competência**, **Vencimento**, **Pagamento**, **Situação** e **Valor**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Prestação de contas**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Demonstrativo por conta](artigo:demonstrativo-contas), [Demonstrativo por situação](artigo:demonstrativo-situacao) e [Demonstrativo (regime de caixa)](artigo:demonstrativo).
