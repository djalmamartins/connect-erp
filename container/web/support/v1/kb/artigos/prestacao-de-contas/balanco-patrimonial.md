---
codigo: AP-081
titulo: Balanço patrimonial
resumo: Posição do patrimônio do condomínio no período: saldos das contas, direitos e obrigações.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/balanco-patrimonial
menu: Prestação de contas > Balanço Patrimonial
icone: document-text-outline
situacao: exemplo
relacionados: balancete-anual, demonstracao-do-resultado, balancete-verificacao, livro-razao
palavras: balanço patrimonial, prestação de contas, AP-081
rascunho: nao
---

Posição do patrimônio do condomínio no período: saldos das contas, direitos e obrigações.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Prestação de contas > Balanço Patrimonial]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela balanco-patrimonial
rota: /prestacao-de-contas/balanco-patrimonial
condominio: residencial-jardim-das-acacias
legenda: Tela Balanço Patrimonial
1: menu | Balanço Patrimonial no menu lateral
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

>> Veja também: [Balancete anual](artigo:balancete-anual), [Demonstração do resultado (DRE)](artigo:demonstracao-do-resultado) e [Balancete de verificação](artigo:balancete-verificacao).
