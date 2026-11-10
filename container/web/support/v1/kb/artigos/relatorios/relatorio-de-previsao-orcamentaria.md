---
codigo: AP-084
titulo: Relatório de previsão orçamentária
resumo: Compara o previsto no orçamento com o realizado de cada conta, com a diferença e a execução em porcentagem.
categoria: relatorios
rota: /relatorios/relatorio-de-previsao-orcamentaria
menu: Relatórios > Financeiros > Relatório de previsão orçamentária
icone: document-text-outline
situacao: exemplo
relacionados: relatorio-de-transferencias, relatorio-memoria-de-calculo, registro-de-pagamentos, saldos-de-contas
palavras: relatório de previsão orçamentária, relatórios, AP-084
rascunho: nao
---

Compara o previsto no orçamento com o realizado de cada conta, com a diferença e a execução em porcentagem.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Relatório de previsão orçamentária]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela relatorio-de-previsao-orcamentaria
rota: /relatorios/relatorio-de-previsao-orcamentaria
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de previsão orçamentária
1: menu | Relatório de previsão orçamentária no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main .erp-page-head__actions :is(a,button):has-text("Exportar") | Botão {{Exportar}}
4: main :is(form[method=get], .erp-filters) | Filtros: Condomínio, De, Até e Critério
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Condomínio**, **De**, **Até**, **Critério** e **Pesquisar**.

## O que o relatório mostra

Colunas: **Conta**, **Previsto**, **Realizado**, **Diferença** e **Execução**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Exportar}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.
- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de transferências](artigo:relatorio-de-transferencias), [Memória de cálculo dos encargos](artigo:relatorio-memoria-de-calculo) e [Registro de pagamentos](artigo:registro-de-pagamentos).
