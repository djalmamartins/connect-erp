---
codigo: AP-094
titulo: Listagem de prestações de contas
resumo: Mostra os fechamentos mensais de cada competência, com o resultado e as reaberturas. Use para saber quais meses já foram fechados e se algum foi reaberto.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/listagem-de-prestacoes-de-contas
menu: Prestação de contas > Listagem de prestações de contas
icone: document-text-outline
situacao: funciona
relacionados: fechamento-mensal, prestacao-de-contas, demonstrativo, demonstrativo-contas
palavras: listagem de prestações de contas, prestação de contas, AP-094
rascunho: nao
---

Mostra os fechamentos mensais de cada competência, com o resultado e as reaberturas. Use para saber quais meses já foram fechados e se algum foi reaberto.

## Onde fica

No menu lateral, abra [[Prestação de contas > Listagem de prestações de contas]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela listagem-de-prestacoes-de-contas
rota: /prestacao-de-contas/listagem-de-prestacoes-de-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Listagem de prestações de contas
1: menu | Listagem de prestações de contas no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: Competência inicial e Competência final
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **Competência inicial** e **Competência final**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

> Na tela: *Nada neste filtro Não há registros para os filtros escolhidos.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Prestação de contas**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Fechar o mês (fechamento mensal)](artigo:fechamento-mensal), [Prestação de contas](artigo:prestacao-de-contas) e [Demonstrativo (regime de caixa)](artigo:demonstrativo).
