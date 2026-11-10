---
codigo: AP-099
titulo: Relatório Boletos Enviados
resumo: Avisos de boleto emitido que entraram na fila de envio no período.
categoria: relatorios
rota: /relatorios/boletos-enviados
menu: Relatórios > Gerais > Boletos Enviados
icone: document-text-outline
situacao: funciona
palavras: boletos enviados, relatórios, AP-099
rascunho: nao
---

## Para que serve

Avisos de boleto emitido que entraram na fila de envio no período. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/sent-boletos`.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Boletos Enviados]].

```tela boletos-enviados
rota: /relatorios/boletos-enviados
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório Boletos Enviados
1: menu | Boletos Enviados no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: De e Até (datas)
4: main table thead | Colunas: Condomínio, Unidade, Nosso número, Valor, Vencimento, Situação, Entrega, Destinatários, Processado em
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **De e Até (datas)**: o período em datas; o padrão vai de 1º de janeiro até hoje.
2. Clique em {{Gerar}}.
3. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Condomínio**
- **Unidade**
- **Nosso número**
- **Valor**
- **Vencimento**
- **Situação**
- **Entrega**
- **Destinatários**
- **Processado em**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.notifications.view** e **erp.bank_charges.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
