---
codigo: AP-060
titulo: Inadimplentes por taxa
resumo: O atraso separado por tipo de cobrança (conta do plano), como taxa condominial, fundo de reserva ou consumo.
categoria: relatorios
rota: /relatorios/inadimplentes-taxa
menu: Relatórios > Financeiros > Inadimplentes (Taxa)
icone: document-text-outline
situacao: funciona
relacionados: inadimplentes-detalhado, inadimplentes-morador, inadimplentes-unidade, rateio
palavras: inadimplentes (taxa), relatórios, AP-060
rascunho: nao
---

O atraso separado por tipo de cobrança (conta do plano), como taxa condominial, fundo de reserva ou consumo.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Inadimplentes (Taxa)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela inadimplentes-taxa
rota: /relatorios/inadimplentes-taxa
condominio: residencial-jardim-das-acacias
legenda: Tela Inadimplentes (Taxa)
1: menu | Inadimplentes (Taxa) no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Data de referência
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Data de referência**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Condomínio**, **Taxa (conta)**, **Unidades**, **Contas**, **Valor**, **Encargos** e **Total**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Inadimplentes detalhado (conta a conta)](artigo:inadimplentes-detalhado), [Inadimplentes por responsável](artigo:inadimplentes-morador) e [Inadimplentes por unidade](artigo:inadimplentes-unidade).
