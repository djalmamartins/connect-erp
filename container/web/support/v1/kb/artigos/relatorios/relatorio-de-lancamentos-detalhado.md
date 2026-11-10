---
codigo: AP-069
titulo: Relatório de lançamentos detalhado
resumo: Os lançamentos das competências com fornecedor, centro de custo, conta bancária, documento e origem. Use para conferência e auditoria.
categoria: relatorios
rota: /relatorios/relatorio-de-lancamentos-detalhado
menu: Relatórios > Financeiros > Relatório de lançamentos (detalhado)
icone: document-text-outline
situacao: funciona
relacionados: relatorio-de-lancamentos, relatorio-de-boletos, rateio-por-conta, recebimentos
palavras: relatório de lançamentos (detalhado), relatórios, AP-069
rascunho: nao
---

Os lançamentos das competências com fornecedor, centro de custo, conta bancária, documento e origem. Use para conferência e auditoria.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Relatório de lançamentos (detalhado)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela relatorio-de-lancamentos-detalhado
rota: /relatorios/relatorio-de-lancamentos-detalhado
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de lançamentos (detalhado)
1: menu | Relatório de lançamentos (detalhado) no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Competência inicial, Competência final, Natureza e Situação
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Competência inicial**, **Competência final**, **Natureza** e **Situação**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Condomínio**, **Natureza**, **Descrição**, **Código**, **Unidade**, **Fornecedor**, **Centro de custo**, **Conta bancária**, **Documento**, **Origem**, **Competência**, **Vencimento**, **Pagamento**, **Situação** e **Valor**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de lançamentos](artigo:relatorio-de-lancamentos), [Relatório de boletos](artigo:relatorio-de-boletos) e [Rateio por conta](artigo:rateio-por-conta).
