---
codigo: AP-088
titulo: Relatório Listagem de presenças
resumo: As unidades presentes na assembleia, com quem compareceu, procuração e direito a voto, para imprimir ou exportar em CSV.
categoria: relatorios
rota: /relatorios/listagem-de-presencas
menu: Relatórios > Gerais > Listagem de presenças
icone: document-text-outline
situacao: funciona
relacionados: assembleia-virtual, parecer
palavras: listagem de presenças, presença, assembleia, procuração, relatórios, AP-088
rascunho: nao
---

## Para que serve

Lista a presença registrada na assembleia: a unidade, quem esteve presente, se foi por procuração, se a unidade vota e a hora da entrada. Unidade com conta vencida em aberto na hora da presença aparece como **Não vota**.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Listagem de presenças]].

```tela listagem-de-presencas
rota: /relatorios/listagem-de-presencas
condominio: residencial-jardim-das-acacias
legenda: Tela Listagem de presenças
1: menu | Listagem de presenças no menu lateral
2: main [data-erp-assemblies] | Assembleia escolhida e as presenças
```

## Como usar

1. Em **Assembleia**, escolha a assembleia (em andamento ou encerrada) e clique em {{Abrir}}. A mais recente em andamento já vem escolhida.
2. Confira os totais: unidades, procurações e unidades sem direito a voto.
3. Use {{Imprimir}} ou {{Exportar CSV}}.

O valor vencido de cada unidade só aparece para quem tem a permissão **erp.agreements.view**, a mesma que mostra a inadimplência.

## Quem pode usar

Exige **erp.assemblies.view**. A exportação fica na auditoria e tem limite de exportações por hora.
