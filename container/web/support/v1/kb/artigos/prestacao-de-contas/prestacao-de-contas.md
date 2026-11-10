---
codigo: AP-101
titulo: Prestação de contas
resumo: Monta a prestação de contas do condomínio no período: saldo anterior, entradas, saídas e saldo final de cada conta.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/prestacao-de-contas
menu: Prestação de contas > Prestação de contas
icone: document-text-outline
situacao: exemplo
relacionados: listagem-de-prestacoes-de-contas, demonstrativo, demonstrativo-contas, demonstrativo-analitico
palavras: prestação de contas, AP-101
rascunho: nao
---

Monta a prestação de contas do condomínio no período: saldo anterior, entradas, saídas e saldo final de cada conta.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Prestação de contas > Prestação de contas]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela prestacao-de-contas
rota: /prestacao-de-contas/prestacao-de-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Prestação de contas
1: menu | Prestação de contas no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main .erp-page-head__actions :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Condomínio, De, Até e Critério
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Condomínio**, **De**, **Até**, **Critério** e **Pesquisar**.

## O que o relatório mostra

Colunas: **Conta**, **Saldo anterior**, **Entradas**, **Saídas** e **Saldo final**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Exportar}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.
- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Prestação de contas**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Listagem de prestações de contas](artigo:listagem-de-prestacoes-de-contas), [Demonstrativo (regime de caixa)](artigo:demonstrativo) e [Demonstrativo por conta](artigo:demonstrativo-contas).
