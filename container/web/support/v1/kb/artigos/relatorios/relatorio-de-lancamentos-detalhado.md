---
codigo: AP-069
titulo: Relatório de lançamentos (detalhado)
resumo: Lançamentos das competências com fornecedor, centro de custo, conta bancária, documento e origem.
categoria: relatorios
rota: /relatorios/relatorio-de-lancamentos-detalhado
menu: Relatórios > Financeiros > Relatório de lançamentos (detalhado)
icone: document-text-outline
situacao: funciona
palavras: relatório de lançamentos (detalhado), relatórios, AP-069
rascunho: nao
---

## Para que serve

Lançamentos das competências com fornecedor, centro de custo, conta bancária, documento e origem. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/entries`.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Relatório de lançamentos (detalhado)]].

```tela relatorio-de-lancamentos-detalhado
rota: /relatorios/relatorio-de-lancamentos-detalhado
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de lançamentos (detalhado)
1: menu | Relatório de lançamentos (detalhado) no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: Competência inicial e final, Natureza, Situação
4: main table thead | Colunas: Condomínio, Natureza, Descrição, Código, Unidade, Fornecedor, Centro de custo, Conta bancária, Documento, Origem, Competência, Vencimento, Pagamento, Situação, Valor
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **Competência inicial e final**: a faixa de competências (no máximo 24); o padrão vai de janeiro até o mês atual.
2. **Natureza**: opções: todas, receitas, despesas.
3. **Situação**: opções: todas, pago, a vencer, vencido, cancelado.
4. Clique em {{Gerar}}.
5. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Condomínio**
- **Natureza**
- **Descrição**
- **Código**
- **Unidade**
- **Fornecedor**
- **Centro de custo**
- **Conta bancária**
- **Documento**
- **Origem**
- **Competência**
- **Vencimento**
- **Pagamento**
- **Situação**
- **Valor**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.entries.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
