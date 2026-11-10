---
codigo: AP-083
titulo: Livro razão
resumo: O movimento de cada conta do plano no período: saldo anterior, cada lançamento a débito ou crédito e o saldo corrido. Informe o código de uma conta para ver só ela.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/livro-razao
menu: Prestação de contas > Livro Razão
icone: document-text-outline
situacao: funciona
relacionados: demonstracao-do-resultado, balanco-patrimonial, balancete-anual, balancete-verificacao
palavras: livro razão, prestação de contas, AP-083
rascunho: nao
---

O movimento de cada conta do plano no período: saldo anterior, cada lançamento a débito ou crédito e o saldo corrido. Informe o código de uma conta para ver só ela.

## Onde fica

No menu lateral, abra [[Prestação de contas > Livro Razão]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela livro-razao
rota: /prestacao-de-contas/livro-razao
condominio: residencial-jardim-das-acacias
legenda: Tela Livro Razão
1: menu | Livro Razão no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: De, Até, Base e Conta do plano (código)
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De**, **Até**, **Base** e **Conta do plano (código)**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Código**, **Conta**, **Data**, **Histórico**, **Débito**, **Crédito** e **Saldo**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Prestação de contas**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Demonstração do resultado (DRE)](artigo:demonstracao-do-resultado), [Balanço patrimonial](artigo:balanco-patrimonial) e [Balancete anual](artigo:balancete-anual).
