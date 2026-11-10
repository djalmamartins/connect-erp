---
codigo: AP-075
titulo: Memória de cálculo dos encargos
resumo: Mostra como foram calculados correção, multa e juros de cada conta vencida na data de referência, pela regra de cobrança do condomínio. Use para responder ao morador que questiona o valor.
categoria: relatorios
rota: /relatorios/relatorio-memoria-de-calculo
menu: Relatórios > Financeiros > Relatório Memória de Cálculo
icone: document-text-outline
situacao: funciona
relacionados: indices, registro-de-pagamentos, relatorio-de-transferencias, saldos-de-contas
palavras: relatório memória de cálculo, relatórios, AP-075
rascunho: nao
---

Mostra como foram calculados correção, multa e juros de cada conta vencida na data de referência, pela regra de cobrança do condomínio. Use para responder ao morador que questiona o valor.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Relatório Memória de Cálculo]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela relatorio-memoria-de-calculo
rota: /relatorios/relatorio-memoria-de-calculo
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório Memória de Cálculo
1: menu | Relatório Memória de Cálculo no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Data de referência e Unidade
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Data de referência** e **Unidade**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Unidade**, **Descrição**, **Competência**, **Vencimento**, **Atraso**, **Principal**, **Correção**, **Multa**, **Juros** e **Total**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

> Na tela: *Regra de cobrança Multa 2,00%, juros 1,00% ao mês, índice IPCA. corrigido = principal × fator do índice; multa = corrigido × multa%; juros = corrigido × juros% ao mês × dias / 30. Meses sem taxa do índice (correção zero): 2026-09, 2026-10.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Índices e regra de cobrança](artigo:indices), [Registro de pagamentos](artigo:registro-de-pagamentos) e [Relatório de transferências](artigo:relatorio-de-transferencias).
