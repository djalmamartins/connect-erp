---
codigo: AP-087
titulo: Listagem de Pessoas
resumo: Pessoas ativas por unidade e vínculo.
categoria: relatorios
rota: /relatorios/listagem-de-pessoas
menu: Relatórios > Gerais > Listagem de Pessoas
icone: document-text-outline
situacao: funciona
palavras: listagem de pessoas, relatórios, AP-087
rascunho: nao
---

## Para que serve

Pessoas ativas por unidade e vínculo. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/people`.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Listagem de Pessoas]].

```tela listagem-de-pessoas
rota: /relatorios/listagem-de-pessoas
condominio: residencial-jardim-das-acacias
legenda: Tela Listagem de Pessoas
1: menu | Listagem de Pessoas no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: Vínculo
4: main table thead | Colunas: Condomínio, Unidade, Vínculo, Nome, Documento, E-mail, Telefone, Cobrança por e-mail, Comunicados
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **Vínculo**: opções: todos, proprietário, inquilino, morador, responsável financeiro.
2. Clique em {{Gerar}}.
3. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Condomínio**
- **Unidade**
- **Vínculo**
- **Nome**
- **Documento**
- **E-mail**
- **Telefone**
- **Cobrança por e-mail**
- **Comunicados**

>! Dados pessoais aparecem mascarados (documento, e-mail, telefone e IP), conforme a LGPD.

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.residents.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
