---
codigo: AP-079
titulo: Balancete de verificação
resumo: Lançamentos válidos por conta do plano na competência: o previsto, o que já foi pago ou recebido e o que segue em aberto.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/balancete-verificacao
menu: Prestação de contas > Balancete Verificação
icone: document-text-outline
situacao: funciona
relacionados: extrato-conta, balancete-anual, demonstrativo-situacao, balanco-patrimonial
palavras: balancete verificação, prestação de contas, AP-079
rascunho: nao
---

Lançamentos válidos por conta do plano na competência: o previsto, o que já foi pago ou recebido e o que segue em aberto.

## Onde fica

No menu lateral, abra [[Prestação de contas > Balancete Verificação]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela balancete-verificacao
rota: /prestacao-de-contas/balancete-verificacao
condominio: residencial-jardim-das-acacias
legenda: Tela Balancete Verificação
1: menu | Balancete Verificação no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De (competência) e Até (competência)
4: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De (competência)** e **Até (competência)**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Conta**, **Previsto**, **Realizado** e **Em aberto**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Prestação de contas**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Extrato da conta bancária](artigo:extrato-conta), [Balancete anual](artigo:balancete-anual) e [Demonstrativo por situação](artigo:demonstrativo-situacao).
