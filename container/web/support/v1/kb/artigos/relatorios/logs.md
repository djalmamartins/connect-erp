---
codigo: AP-097
titulo: Relatório Logs
resumo: Registro de auditoria do ERP no período. Só aparece para quem acessa todos os condomínios.
categoria: relatorios
rota: /relatorios/logs
menu: Relatórios > Gerais > Logs
icone: document-text-outline
situacao: funciona
palavras: logs, relatórios, AP-097
rascunho: nao
---

## Para que serve

Registro de auditoria do ERP no período. Só aparece para quem acessa todos os condomínios. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/logs`.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Logs]].

```tela logs
rota: /relatorios/logs
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório Logs
1: menu | Logs no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: De e Até (datas), Ação
4: main table thead | Colunas: Data, Usuário, Ação, Entidade, Registro, Severidade, IP, Contexto
```

> **Dica:** este relatório lista todos os condomínios que a sua conta pode ver, mesmo com um condomínio escolhido na barra superior.

## Como gerar o relatório

1. **De e Até (datas)**: o período em datas; o padrão vai de 1º de janeiro até hoje.
2. **Ação**: opções: todas, criação, alteração, exclusão, exportação.
3. Clique em {{Gerar}}.
4. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Data**
- **Usuário**
- **Ação**
- **Entidade**
- **Registro**
- **Severidade**
- **IP**
- **Contexto**

>! Dados pessoais aparecem mascarados (documento, e-mail, telefone e IP), conforme a LGPD.

>! Os logs não têm condomínio: só quem acessa todos os condomínios consegue ver este relatório.

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.audit.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
