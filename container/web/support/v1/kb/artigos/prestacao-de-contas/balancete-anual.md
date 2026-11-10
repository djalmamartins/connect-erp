---
codigo: AP-080
titulo: Balancete anual
resumo: Receitas e despesas de cada conta do plano mês a mês no ano, com as contas sintéticas somadas e o resultado de cada mês. Escolha a base (competência ou caixa).
categoria: prestacao-de-contas
rota: /prestacao-de-contas/balancete-anual
menu: Prestação de contas > Balancete Anual
icone: document-text-outline
situacao: funciona
relacionados: balancete-verificacao, balanco-patrimonial, extrato-conta, demonstracao-do-resultado
palavras: balancete anual, prestação de contas, AP-080
rascunho: nao
---

Receitas e despesas de cada conta do plano mês a mês no ano, com as contas sintéticas somadas e o resultado de cada mês. Escolha a base (competência ou caixa).

## Onde fica

No menu lateral, abra [[Prestação de contas > Balancete Anual]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela balancete-anual
rota: /prestacao-de-contas/balancete-anual
condominio: residencial-jardim-das-acacias
legenda: Tela Balancete Anual
1: menu | Balancete Anual no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Ano e Base
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Ano** e **Base**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Código**, **Conta**, **Natureza**, **JAN**, **FEV**, **MAR**, **ABR**, **MAI**, **JUN**, **JUL**, **AGO**, **SET**, **OUT**, **NOV**, **DEZ** e **Total**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Prestação de contas**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Balancete de verificação](artigo:balancete-verificacao), [Balanço patrimonial](artigo:balanco-patrimonial) e [Extrato da conta bancária](artigo:extrato-conta).
