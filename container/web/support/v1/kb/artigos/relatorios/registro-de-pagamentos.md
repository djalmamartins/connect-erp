---
codigo: AP-074
titulo: Relatório Registro de Pagamentos
resumo: Contas pagas no período, pela data do pagamento, com a conta bancária e o fornecedor.
categoria: relatorios
rota: /relatorios/registro-de-pagamentos
menu: Relatórios > Financeiros > Registro de Pagamentos
icone: document-text-outline
situacao: funciona
palavras: registro de pagamentos, relatórios, AP-074
rascunho: nao
---

## Para que serve

Contas pagas no período, pela data do pagamento, com a conta bancária e o fornecedor. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/payments`.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Registro de Pagamentos]].

```tela registro-de-pagamentos
rota: /relatorios/registro-de-pagamentos
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório Registro de Pagamentos
1: menu | Registro de Pagamentos no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: De e Até (datas), Natureza
4: main table thead | Colunas: Condomínio, Natureza, Pago em, Vencimento, Descrição, Documento, Conta, Conta bancária, Fornecedor, CPF/CNPJ, Unidade, Valor
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **De e Até (datas)**: o período em datas; o padrão vai de 1º de janeiro até hoje.
2. **Natureza**: opções: despesas, receitas, todas.
3. Clique em {{Gerar}}.
4. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Condomínio**
- **Natureza**
- **Pago em**
- **Vencimento**
- **Descrição**
- **Documento**
- **Conta**
- **Conta bancária**
- **Fornecedor**
- **CPF/CNPJ**
- **Unidade**
- **Valor**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.entries.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
