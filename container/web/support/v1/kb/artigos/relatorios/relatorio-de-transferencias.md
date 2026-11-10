---
codigo: AP-076
titulo: Relatório de transferências
resumo: Transferências entre contas bancárias no período. As canceladas aparecem, mas ficam fora do total.
categoria: relatorios
rota: /relatorios/relatorio-de-transferencias
menu: Relatórios > Financeiros > Relatório de transferências
icone: document-text-outline
situacao: funciona
palavras: relatório de transferências, relatórios, AP-076
rascunho: nao
---

## Para que serve

Transferências entre contas bancárias no período. As canceladas aparecem, mas ficam fora do total. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/transfers`.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Relatório de transferências]].

```tela relatorio-de-transferencias
rota: /relatorios/relatorio-de-transferencias
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de transferências
1: menu | Relatório de transferências no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: De e Até (datas), Canceladas
4: main table thead | Colunas: Condomínio, Data, Origem, Destino, Descrição, Valor, Situação, Motivo do cancelamento
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **De e Até (datas)**: o período em datas; o padrão vai de 1º de janeiro até hoje.
2. **Canceladas**: opções: mostrar, esconder.
3. Clique em {{Gerar}}.
4. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Condomínio**
- **Data**
- **Origem**
- **Destino**
- **Descrição**
- **Valor**
- **Situação**
- **Motivo do cancelamento**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.entries.view** e **erp.bank_accounts.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
