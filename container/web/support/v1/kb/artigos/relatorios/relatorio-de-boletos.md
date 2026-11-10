---
codigo: AP-070
titulo: Relatório de boletos
resumo: Boletos com vencimento no período, com o valor emitido e o pago.
categoria: relatorios
rota: /relatorios/relatorio-de-boletos
menu: Relatórios > Financeiros > Relatório de boletos
icone: document-text-outline
situacao: funciona
palavras: relatório de boletos, relatórios, AP-070
rascunho: nao
---

## Para que serve

Boletos com vencimento no período, com o valor emitido e o pago. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/bank-charges`.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Relatório de boletos]].

```tela relatorio-de-boletos
rota: /relatorios/relatorio-de-boletos
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de boletos
1: menu | Relatório de boletos no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: De e Até (datas), Situação
4: main table thead | Colunas: Condomínio, Nosso número, Situação, Unidade, Descrição, Conta bancária, Vencimento, Valor, Pagamento, Valor pago
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **De e Até (datas)**: o período em datas; o padrão vai de 1º de janeiro até hoje.
2. **Situação**: opções: todas, pendente, registrado, rejeitado, pago, cancelado.
3. Clique em {{Gerar}}.
4. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Condomínio**
- **Nosso número**
- **Situação**
- **Unidade**
- **Descrição**
- **Conta bancária**
- **Vencimento**
- **Valor**
- **Pagamento**
- **Valor pago**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.bank_charges.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
