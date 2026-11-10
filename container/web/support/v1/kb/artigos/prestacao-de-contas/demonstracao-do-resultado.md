---
codigo: AP-082
titulo: Demonstração do resultado (DRE)
resumo: Receitas menos despesas do período por conta do plano, com as contas sintéticas somadas e o resultado final. Escolha a base: competência ou caixa.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/demonstracao-do-resultado
menu: Prestação de contas > Demonstração do resultado
icone: document-text-outline
situacao: funciona
relacionados: balanco-patrimonial, livro-razao, balancete-anual, balancete-verificacao
palavras: demonstração do resultado, prestação de contas, AP-082
rascunho: nao
---

Receitas menos despesas do período por conta do plano, com as contas sintéticas somadas e o resultado final. Escolha a base: competência ou caixa.

## Onde fica

No menu lateral, abra [[Prestação de contas > Demonstração do resultado]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela demonstracao-do-resultado
rota: /prestacao-de-contas/demonstracao-do-resultado
condominio: residencial-jardim-das-acacias
legenda: Tela Demonstração do resultado
1: menu | Demonstração do resultado no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: De, Até e Base
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De**, **Até** e **Base**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Código**, **Conta**, **Natureza** e **Valor**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Prestação de contas**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Balanço patrimonial](artigo:balanco-patrimonial), [Livro razão](artigo:livro-razao) e [Balancete anual](artigo:balancete-anual).
