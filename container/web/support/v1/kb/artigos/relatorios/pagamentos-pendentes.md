---
codigo: AP-093
titulo: Relatório Pagamentos Pendentes
resumo: Contas a pagar em aberto até a data limite. As vencidas ficam marcadas.
categoria: relatorios
rota: /relatorios/pagamentos-pendentes
menu: Relatórios > Gerais > Pagamentos Pendentes
icone: document-text-outline
situacao: funciona
palavras: pagamentos pendentes, relatórios, AP-093
rascunho: nao
---

## Para que serve

Contas a pagar em aberto até a data limite. As vencidas ficam marcadas. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/pending-payments`.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Pagamentos Pendentes]].

```tela pagamentos-pendentes
rota: /relatorios/pagamentos-pendentes
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório Pagamentos Pendentes
1: menu | Pagamentos Pendentes no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: Vencimento até
4: main table thead | Colunas: Condomínio, Descrição, Fornecedor, Conta, Documento, Vencimento, Vencida, Valor
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **Vencimento até**: a data limite; o padrão é hoje mais 30 dias.
2. Clique em {{Gerar}}.
3. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Condomínio**
- **Descrição**
- **Fornecedor**
- **Conta**
- **Documento**
- **Vencimento**
- **Vencida**
- **Valor**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.entries.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
