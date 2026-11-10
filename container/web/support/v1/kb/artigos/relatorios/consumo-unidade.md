---
codigo: AP-046
titulo: Relatório de consumo por unidade
resumo: Consumo de cada unidade por tipo de medidor nas competências escolhidas.
categoria: relatorios
rota: /relatorios/consumo-unidade
menu: Relatórios > Financeiros > Consumo (Unidade)
icone: document-text-outline
situacao: funciona
relacionados: consumo-geral, relatorio-de-acordos, taxas-de-acordos, relatorio-de-centros-de-custo
palavras: consumo (unidade), relatórios, AP-046
rascunho: nao
---

Consumo de cada unidade por tipo de medidor nas competências escolhidas.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Consumo (Unidade)]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela consumo-unidade
rota: /relatorios/consumo-unidade
condominio: residencial-jardim-das-acacias
legenda: Tela Consumo (Unidade)
1: menu | Consumo (Unidade) no menu lateral
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

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Relatório de consumo geral](artigo:consumo-geral), [Relatório de acordos](artigo:relatorio-de-acordos) e [Taxas de acordos](artigo:taxas-de-acordos).
