---
codigo: AP-100
titulo: Relatórios enviados
resumo: Os relatórios enviados aos condôminos no período.
categoria: relatorios
rota: /relatorios/relatorios-enviados
menu: Relatórios > Gerais > Relatórios Enviados
icone: document-text-outline
situacao: exemplo
relacionados: boletos-enviados, pessoas-com-acesso, logs, relatorio-de-enderecos
palavras: relatórios enviados, relatórios, AP-100
rascunho: nao
---

Os relatórios enviados aos condôminos no período.

>! Esta tela ainda é uma **interface proposta com dados de exemplo**: os campos e colunas já aparecem, mas o que for gravado ou enviado por ela fica pendente até o módulo ser concluído. Use-a para conhecer o fluxo.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Relatórios Enviados]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela relatorios-enviados
rota: /relatorios/relatorios-enviados
condominio: residencial-jardim-das-acacias
legenda: Tela Relatórios Enviados
1: menu | Relatórios Enviados no menu lateral
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

>> Veja também: [Boletos enviados](artigo:boletos-enviados), [Pessoas com acesso aos condomínios](artigo:pessoas-com-acesso) e [Logs de auditoria](artigo:logs).
