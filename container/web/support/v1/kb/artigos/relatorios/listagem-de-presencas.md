---
codigo: AP-088
titulo: Listagem de presenças
resumo: As presenças registradas no período.
categoria: relatorios
rota: /relatorios/listagem-de-presencas
menu: Relatórios > Gerais > Listagem de presenças
icone: document-text-outline
situacao: exemplo
relacionados: listagem-de-pessoas, relatorio-de-condominios, relatorio-de-unidades, relatorio-de-ocorrencias
palavras: listagem de presenças, relatórios, AP-088
rascunho: nao
---

As presenças registradas no período.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Listagem de presenças]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela listagem-de-presencas
rota: /relatorios/listagem-de-presencas
condominio: residencial-jardim-das-acacias
legenda: Tela Listagem de presenças
1: menu | Listagem de presenças no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main .erp-page-head__actions :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Condomínio, De, Até e Critério
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Condomínio**, **De**, **Até**, **Critério** e **Pesquisar**.

## O que o relatório mostra

Colunas: **Data**, **Condomínio**, **Descrição**, **Responsável** e **Situação**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Exportar}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.
- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Listagem de pessoas](artigo:listagem-de-pessoas), [Relatório de condomínios](artigo:relatorio-de-condominios) e [Relatório de unidades](artigo:relatorio-de-unidades).
