---
codigo: AP-056
titulo: Extrato da conta bancária
resumo: Movimentos pagos e transferências de uma conta bancária do condomínio, com o saldo anterior e o saldo dia a dia. Compare com o extrato do banco para conciliar.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/extrato-conta
menu: Prestação de contas > Extrato Conta
icone: document-text-outline
situacao: funciona
relacionados: extrato-bancario-ofx, saldos-de-contas, demonstrativo-situacao, balancete-verificacao
palavras: extrato conta, prestação de contas, AP-056
rascunho: nao
---

Movimentos pagos e transferências de uma conta bancária do condomínio, com o saldo anterior e o saldo dia a dia. Compare com o extrato do banco para conciliar.

## Onde fica

No menu lateral, abra [[Prestação de contas > Extrato Conta]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela extrato-conta
rota: /prestacao-de-contas/extrato-conta
condominio: residencial-jardim-das-acacias
legenda: Tela Extrato Conta
1: menu | Extrato Conta no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main :is(form[method=get], .erp-filters) | Filtros: Conta bancária, De e Até
5: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Conta bancária**, **De** e **Até**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Data**, **Histórico**, **Valor** e **Saldo**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.
- {{Exportar CSV}} baixa os dados filtrados em CSV, que abre no Excel ou no Google Planilhas.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Prestação de contas**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Conciliar o extrato bancário (OFX)](artigo:extrato-bancario-ofx), [Saldos das contas bancárias](artigo:saldos-de-contas) e [Demonstrativo por situação](artigo:demonstrativo-situacao).
