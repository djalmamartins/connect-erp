---
codigo: AP-076
titulo: Relatório de transferências
resumo: Transferências entre contas bancárias no período. As canceladas aparecem, mas ficam fora do total.
categoria: relatorios
rota: /relatorios/relatorio-de-transferencias
menu: Relatórios > Financeiros > Relatório de transferências
icone: document-text-outline
situacao: funciona
relacionados: relatorio-memoria-de-calculo, relatorio-de-previsao-orcamentaria, registro-de-pagamentos, saldos-de-contas
palavras: relatório de transferências, relatórios, AP-076
rascunho: nao
---

Transferências entre contas bancárias no período. As canceladas aparecem, mas ficam fora do total.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Relatório de transferências]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela relatorio-de-transferencias
rota: /relatorios/relatorio-de-transferencias
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de transferências
1: menu | Relatório de transferências no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De, Até e Canceladas
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De**, **Até** e **Canceladas**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

> Na tela: *Nada neste filtro Não há registros para os filtros escolhidos.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Memória de cálculo dos encargos](artigo:relatorio-memoria-de-calculo), [Relatório de previsão orçamentária](artigo:relatorio-de-previsao-orcamentaria) e [Registro de pagamentos](artigo:registro-de-pagamentos).
