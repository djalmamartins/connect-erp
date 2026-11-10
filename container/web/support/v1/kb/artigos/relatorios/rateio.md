---
codigo: AP-062
titulo: Relatório Rateio
resumo: Despesas da competência rateadas pela fração ideal de cada unidade, sem perder centavo.
categoria: relatorios
rota: /relatorios/rateio
menu: Relatórios > Financeiros > Rateio
icone: document-text-outline
situacao: funciona
palavras: rateio, relatórios, AP-062
rascunho: nao
---

## Para que serve

Despesas da competência rateadas pela fração ideal de cada unidade, sem perder centavo. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/apportionment`.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Rateio]].

```tela rateio
rota: /relatorios/rateio
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório Rateio
1: menu | Rateio no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: Competência
4: main table thead | Colunas: Unidade, Fração ideal, Valor
```

>! Este relatório usa os dados de **um condomínio só**. Escolha o condomínio na barra superior; com "Todos os condomínios", a tela pede a escolha.

## Como gerar o relatório

1. **Competência**: o mês das despesas rateadas; o padrão é o mês atual.
2. Clique em {{Gerar}}.
3. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Unidade**
- **Fração ideal**
- **Valor**

>! Se alguma unidade ativa estiver sem fração ideal, o rateio não é calculado e a tela lista as unidades que faltam. Corrija em [[Configurações > Financeiro e parâmetros > Fração Ideal]].

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.entries.view** e **erp.units.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
