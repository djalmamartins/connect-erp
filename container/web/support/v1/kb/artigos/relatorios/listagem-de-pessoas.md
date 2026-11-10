---
codigo: AP-087
titulo: Listagem de pessoas
resumo: As pessoas ativas por unidade e vínculo, com contato e as preferências de cobrança e comunicado por e-mail.
categoria: relatorios
rota: /relatorios/listagem-de-pessoas
menu: Relatórios > Gerais > Listagem de Pessoas
icone: document-text-outline
situacao: funciona
relacionados: relatorio-de-unidades, listagem-de-presencas, relatorio-de-reservas, relatorio-de-condominios
palavras: listagem de pessoas, relatórios, AP-087
rascunho: nao
---

As pessoas ativas por unidade e vínculo, com contato e as preferências de cobrança e comunicado por e-mail.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Listagem de Pessoas]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela listagem-de-pessoas
rota: /relatorios/listagem-de-pessoas
condominio: residencial-jardim-das-acacias
legenda: Tela Listagem de Pessoas
1: menu | Listagem de Pessoas no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Vínculo
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Vínculo**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Condomínio**, **Unidade**, **Vínculo**, **Nome**, **Documento**, **E-mail**, **Telefone**, **Cobrança por e-mail** e **Comunicados**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

>! Este relatório tem dados pessoais (LGPD). Use só para o atendimento do condomínio.

> Na tela: *Dados pessoais: documento, e-mail, telefone e IP aparecem mascarados, e a exportação fica registrada na auditoria.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de unidades](artigo:relatorio-de-unidades), [Listagem de presenças](artigo:listagem-de-presencas) e [Relatório de reservas](artigo:relatorio-de-reservas).
