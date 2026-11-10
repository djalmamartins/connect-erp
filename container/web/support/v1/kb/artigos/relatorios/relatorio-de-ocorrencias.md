---
codigo: AP-090
titulo: Relatório de ocorrências
resumo: Ocorrências registradas no período, com a prioridade, a situação e o chamado.
categoria: relatorios
rota: /relatorios/relatorio-de-ocorrencias
menu: Relatórios > Gerais > Relatório de ocorrências
icone: document-text-outline
situacao: funciona
palavras: relatório de ocorrências, relatórios, AP-090
rascunho: nao
---

## Para que serve

Ocorrências registradas no período, com a prioridade, a situação e o chamado. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/occurrences`.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Relatório de ocorrências]].

```tela relatorio-de-ocorrencias
rota: /relatorios/relatorio-de-ocorrencias
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de ocorrências
1: menu | Relatório de ocorrências no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: De e Até (datas), Situação
4: main table thead | Colunas: Condomínio, Unidade, Assunto, Prioridade, Data, Situação, Chamado
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **De e Até (datas)**: o período em datas; o padrão vai de 1º de janeiro até hoje.
2. **Situação**: opções: todas, aberta, em andamento, concluída.
3. Clique em {{Gerar}}.
4. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Condomínio**
- **Unidade**
- **Assunto**
- **Prioridade**
- **Data**
- **Situação**
- **Chamado**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.occurrences.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
