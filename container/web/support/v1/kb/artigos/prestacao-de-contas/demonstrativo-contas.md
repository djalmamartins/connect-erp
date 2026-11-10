---
codigo: AP-052
titulo: Demonstrativo por conta
resumo: Receitas e despesas por conta do plano: o previsto, o realizado e o que segue em aberto. Bom para ver onde o condomínio gastou mais que o previsto.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/demonstrativo-contas
menu: Prestação de contas > Demonstrativo Contas
icone: document-text-outline
situacao: funciona
relacionados: demonstrativo, demonstrativo-analitico, listagem-de-prestacoes-de-contas, demonstrativo-situacao
palavras: demonstrativo contas, prestação de contas, AP-052
rascunho: nao
---

Receitas e despesas por conta do plano: o previsto, o realizado e o que segue em aberto. Bom para ver onde o condomínio gastou mais que o previsto.

## Onde fica

No menu lateral, abra [[Prestação de contas > Demonstrativo Contas]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela demonstrativo-contas
rota: /prestacao-de-contas/demonstrativo-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Demonstrativo Contas
1: menu | Demonstrativo Contas no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Competência inicial e Competência final
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Competência inicial** e **Competência final**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Código**, **Conta**, **Natureza**, **Lançamentos**, **Previsto**, **Realizado** e **Em aberto**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Prestação de contas**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Demonstrativo (regime de caixa)](artigo:demonstrativo), [Demonstrativo analítico](artigo:demonstrativo-analitico) e [Listagem de prestações de contas](artigo:listagem-de-prestacoes-de-contas).
