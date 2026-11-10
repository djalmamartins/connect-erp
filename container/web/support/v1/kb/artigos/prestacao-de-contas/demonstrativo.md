---
codigo: AP-051
titulo: Demonstrativo (regime de caixa)
resumo: A prestação de contas pelo regime de caixa: o que foi recebido e pago no período, por conta do plano e por conta bancária, conciliado com os saldos. É o relatório que normalmente vai para o síndico e o conselho.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/demonstrativo
menu: Prestação de contas > Demonstrativo
icone: document-text-outline
situacao: funciona
relacionados: listagem-de-prestacoes-de-contas, demonstrativo-contas, prestacao-de-contas, demonstrativo-analitico
palavras: demonstrativo, prestação de contas, AP-051
rascunho: nao
---

A prestação de contas pelo regime de caixa: o que foi recebido e pago no período, por conta do plano e por conta bancária, conciliado com os saldos. É o relatório que normalmente vai para o síndico e o conselho.

## Onde fica

No menu lateral, abra [[Prestação de contas > Demonstrativo]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela demonstrativo
rota: /prestacao-de-contas/demonstrativo
condominio: residencial-jardim-das-acacias
legenda: Tela Demonstrativo
1: menu | Demonstrativo no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De e Até
4: main table thead | Colunas da lista
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De** e **Até**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## O que o relatório mostra

Colunas: **Conta**, **Saldo anterior**, **Entradas**, **Saídas** e **Saldo final**.
Os totais aparecem no rodapé da tabela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

> Feche o mês antes de imprimir o demonstrativo, para os números não mudarem depois.

> Na tela: *Conciliado O resultado pelo plano de contas é igual ao movimento das contas bancárias no período.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Prestação de contas**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Listagem de prestações de contas](artigo:listagem-de-prestacoes-de-contas), [Demonstrativo por conta](artigo:demonstrativo-contas) e [Prestação de contas](artigo:prestacao-de-contas).
