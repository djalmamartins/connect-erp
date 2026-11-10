---
codigo: AP-136
titulo: Registros de Entregas
resumo: O histórico das encomendas da portaria, com filtros e resumo, e a baixa da retirada com o nome de quem retirou.
categoria: operacional
rota: /operacional/registros-de-entregas
menu: Operacional > Portaria > Registros de Entregas
icone: cube-outline
situacao: funciona
relacionados: entregas
palavras: registros de entregas, encomendas, portaria, retirada, baixa, correios, AP-136
rascunho: nao
---

## Para que serve

Mostra as encomendas que chegaram na portaria: a unidade, o destinatário, a transportadora, o rastreio, há quantos dias esperam e quem retirou. A chegada é registrada em [Entregas](artigo:entregas); aqui se dá a **baixa da retirada**.

## Onde fica

No menu lateral, abra [[Operacional > Portaria > Registros de Entregas]].

```tela registros-de-entregas
rota: /operacional/registros-de-entregas
condominio: residencial-jardim-das-acacias
legenda: Tela Registros de Entregas
1: menu | Registros de Entregas no menu lateral
2: main form.erp-filters | Filtros: situação, período da chegada e unidade
3: main [data-erp-deliveries] | Resumo e lista das entregas
```

> **Dica:** escolha o condomínio na barra superior para filtrar por unidade. Com "Todos os condomínios", a lista traz todos os do seu escopo.

## Como usar

1. Ajuste os filtros: **Situação** (aguardando, retirada ou cancelada), o período da chegada (até 366 dias) e a **Unidade**.
2. Clique em {{Filtrar}}. O resumo mostra quantas aguardam, quantas foram retiradas, a média de dias até a retirada e a espera mais antiga.
3. Para registrar a retirada, clique em {{Dar baixa}} na linha, informe **quem retirou** e a **data da retirada** (entre a chegada e hoje) e confirme.

>! A baixa não volta atrás pela tela: confira o nome antes de confirmar. Só o nome fica guardado; foto e documento de quem retira não são guardados.

## Quem pode usar

Ver exige a permissão **erp.deliveries.view**; dar baixa, **erp.deliveries.manage**. A baixa fica na auditoria (a mudança de situação e a data).
