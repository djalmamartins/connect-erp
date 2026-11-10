---
codigo: AP-056
titulo: Relatório Extrato Conta
resumo: Movimentos pagos e transferências da conta bancária, com o saldo anterior e o saldo corrido.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/extrato-conta
menu: Prestação de contas > Extrato Conta
icone: document-text-outline
situacao: funciona
palavras: extrato conta, prestação de contas, AP-056
rascunho: nao
---

## Para que serve

Movimentos pagos e transferências da conta bancária, com o saldo anterior e o saldo corrido. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/account-statement`.

## Onde fica

No menu lateral, abra [[Prestação de contas > Extrato Conta]].

```tela extrato-conta
rota: /prestacao-de-contas/extrato-conta
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório Extrato Conta
1: menu | Extrato Conta no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: Conta bancária, De e Até (datas)
4: main table thead | Colunas: Data, Histórico, Valor, Saldo
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **Conta bancária**: a conta do extrato.
2. **De e Até (datas)**: o período em datas; o padrão vai de 1º de janeiro até hoje.
3. Clique em {{Gerar}}.
4. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Data**
- **Histórico**
- **Valor**
- **Saldo**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.entries.view** e **erp.bank_accounts.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
