---
codigo: AP-073
titulo: Relatório Saldos de Contas
resumo: Saldo de cada conta bancária na data: o saldo do dia anterior, as entradas e saídas do dia e o saldo final.
categoria: relatorios
rota: /relatorios/saldos-de-contas
menu: Relatórios > Financeiros > Saldos de Contas
icone: document-text-outline
situacao: funciona
palavras: saldos de contas, relatórios, AP-073
rascunho: nao
---

## Para que serve

Saldo de cada conta bancária na data: o saldo do dia anterior, as entradas e saídas do dia e o saldo final. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/account-balances`.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Saldos de Contas]].

```tela saldos-de-contas
rota: /relatorios/saldos-de-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório Saldos de Contas
1: menu | Saldos de Contas no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: Data de referência
4: main table thead | Colunas: Condomínio, Conta bancária, Saldo anterior, Entradas, Saídas, Saldo final
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **Data de referência**: o dia em que os encargos são calculados; o padrão é hoje.
2. Clique em {{Gerar}}.
3. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Condomínio**
- **Conta bancária**
- **Saldo anterior**
- **Entradas**
- **Saídas**
- **Saldo final**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.entries.view** e **erp.chart.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
