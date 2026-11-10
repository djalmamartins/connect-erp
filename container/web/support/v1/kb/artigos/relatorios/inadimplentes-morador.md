---
codigo: AP-061
titulo: Inadimplentes por responsável
resumo: As unidades em atraso com o responsável financeiro, documento, telefone e e-mail, para fazer o contato.
categoria: relatorios
rota: /relatorios/inadimplentes-morador
menu: Relatórios > Financeiros > Inadimplentes (Morador)
icone: document-text-outline
situacao: funciona
relacionados: inadimplentes-taxa, rateio, inadimplentes-detalhado, rateio-comparativo
palavras: inadimplentes (morador), relatórios, AP-061
rascunho: nao
---

As unidades em atraso com o responsável financeiro, documento, telefone e e-mail, para fazer o contato.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Inadimplentes (Morador)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela inadimplentes-morador
rota: /relatorios/inadimplentes-morador
condominio: residencial-jardim-das-acacias
legenda: Tela Inadimplentes (Morador)
1: menu | Inadimplentes (Morador) no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Data de referência
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Data de referência**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Condomínio**, **Unidade**, **Responsável**, **Documento**, **Telefone**, **E-mail**, **Contas**, **Total** e **Maior atraso**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

>! Este relatório tem dados pessoais (LGPD). Não envie para fora do sistema e descarte as cópias impressas.

> Na tela: *Dados pessoais: o documento aparece mascarado, e a exportação fica registrada na auditoria.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Inadimplentes por taxa](artigo:inadimplentes-taxa), [Rateio das despesas](artigo:rateio) e [Inadimplentes detalhado (conta a conta)](artigo:inadimplentes-detalhado).
