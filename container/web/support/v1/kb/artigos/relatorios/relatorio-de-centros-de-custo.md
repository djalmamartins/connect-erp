---
codigo: AP-049
titulo: Relatório de centros de custo
resumo: Previsto (pela competência), realizado (pago) e em aberto de cada centro de custo.
categoria: relatorios
rota: /relatorios/relatorio-de-centros-de-custo
menu: Relatórios > Financeiros > Relatório de centros de custo
icone: document-text-outline
situacao: funciona
palavras: relatório de centros de custo, relatórios, AP-049
rascunho: nao
---

## Para que serve

Previsto (pela competência), realizado (pago) e em aberto de cada centro de custo. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/cost-centers`.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Relatório de centros de custo]].

```tela relatorio-de-centros-de-custo
rota: /relatorios/relatorio-de-centros-de-custo
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de centros de custo
1: menu | Relatório de centros de custo no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: Competência inicial e final
4: main table thead | Colunas: Condomínio, Natureza, Código, Centro de custo, Previsto, Realizado, Em aberto
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **Competência inicial e final**: a faixa de competências (no máximo 24); o padrão vai de janeiro até o mês atual.
2. Clique em {{Gerar}}.
3. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Condomínio**
- **Natureza**
- **Código**
- **Centro de custo**
- **Previsto**
- **Realizado**
- **Em aberto**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.entries.view** e **erp.cost_centers.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
