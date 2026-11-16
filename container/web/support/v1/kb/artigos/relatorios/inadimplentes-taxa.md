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
2: main .erp-page-head__actions :is(a,button):has-text("Download") | Botão {{Download}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Data de referência e Vencidas a partir de
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Data de referência** e, se quiser, **Vencidas a partir de**. Com a data inicial em branco, o relatório traz todas as contas vencidas até a data de referência; preenchida, só as que venceram a partir dela.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Condomínio**, **Taxa (conta)**, **Unidades**, **Contas**, **Valor**, **Encargos** e **Total**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Download}} baixa os dados filtrados em PDF (com o cabeçalho da Connect, o condomínio, o título, a data e o número da página), em Excel ou em CSV.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Inadimplentes detalhado (conta a conta)](artigo:inadimplentes-detalhado), [Inadimplentes por responsável](artigo:inadimplentes-morador) e [Inadimplentes por unidade](artigo:inadimplentes-unidade).
