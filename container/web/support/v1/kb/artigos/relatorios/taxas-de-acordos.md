---
codigo: AP-048
titulo: Taxas de acordos
resumo: Dívida original, encargos e descontos dos acordos criados no período, por condomínio. Encargos líquidos são os encargos menos os descontos.
categoria: relatorios
rota: /relatorios/taxas-de-acordos
menu: Relatórios > Financeiros > Taxas de Acordos
icone: document-text-outline
situacao: funciona
relacionados: relatorio-de-acordos, relatorio-de-centros-de-custo, consumo-unidade, comparativo
palavras: taxas de acordos, relatórios, AP-048
rascunho: nao
---

Dívida original, encargos e descontos dos acordos criados no período, por condomínio. Encargos líquidos são os encargos menos os descontos.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Taxas de Acordos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela taxas-de-acordos
rota: /relatorios/taxas-de-acordos
condominio: residencial-jardim-das-acacias
legenda: Tela Taxas de Acordos
1: menu | Taxas de Acordos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main :is(form[method=get], .erp-filters) | Filtros: De e Até
```

## Escolher o período e os filtros

Use os filtros no alto da lista: **De** e **Até**.
Depois clique em {{Gerar}}. O relatório mostra só o que os filtros pedem, e a impressão sai igual à tela.

## Imprimir ou exportar

- {{Imprimir}} abre a versão para impressão, com o cabeçalho do condomínio. Para guardar em PDF, escolha **Salvar como PDF** na janela de impressão.

> Na tela: *Nada neste filtro Não há registros no período escolhido.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de acordos](artigo:relatorio-de-acordos), [Relatório de centros de custo](artigo:relatorio-de-centros-de-custo) e [Relatório de consumo por unidade](artigo:consumo-unidade).
