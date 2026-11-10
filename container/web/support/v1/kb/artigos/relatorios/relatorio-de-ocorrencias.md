---
codigo: AP-090
titulo: Relatório de ocorrências
resumo: As ocorrências registradas no período, com prioridade, situação e o chamado aberto, se houver.
categoria: relatorios
rota: /relatorios/relatorio-de-ocorrencias
menu: Relatórios > Gerais > Relatório de ocorrências
icone: document-text-outline
situacao: funciona
relacionados: relatorio-de-condominios, administracao, listagem-de-presencas, parecer
palavras: relatório de ocorrências, relatórios, AP-090
rascunho: nao
---

As ocorrências registradas no período, com prioridade, situação e o chamado aberto, se houver.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Relatório de ocorrências]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela relatorio-de-ocorrencias
rota: /relatorios/relatorio-de-ocorrencias
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de ocorrências
1: menu | Relatório de ocorrências no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: De, Até e Situação
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De**, **Até** e **Situação**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Condomínio**, **Unidade**, **Assunto**, **Prioridade**, **Data**, **Situação** e **Chamado**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de condomínios](artigo:relatorio-de-condominios), [Relatório de administração](artigo:administracao) e [Listagem de presenças](artigo:listagem-de-presencas).
