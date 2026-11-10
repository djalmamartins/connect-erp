---
codigo: AP-098
titulo: Relatório Pessoas com Acesso
resumo: Quem tem acesso a cada condomínio por concessão.
categoria: relatorios
rota: /relatorios/pessoas-com-acesso
menu: Relatórios > Gerais > Pessoas com Acesso
icone: document-text-outline
situacao: funciona
palavras: pessoas com acesso, relatórios, AP-098
rascunho: nao
---

## Para que serve

Quem tem acesso a cada condomínio por concessão. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/access-people`.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Pessoas com Acesso]].

```tela pessoas-com-acesso
rota: /relatorios/pessoas-com-acesso
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório Pessoas com Acesso
1: menu | Pessoas com Acesso no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main table thead | Colunas: Condomínio, Nome, E-mail, Situação, Concedido em
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. Abra a página: o relatório já aparece com a situação de hoje.
2. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Condomínio**
- **Nome**
- **E-mail**
- **Situação**
- **Concedido em**

>! Dados pessoais aparecem mascarados (documento, e-mail, telefone e IP), conforme a LGPD.

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.audit.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
