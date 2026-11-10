---
codigo: AP-085
titulo: Relatório de reservas
resumo: Reservas das áreas comuns no período, com a taxa e a situação.
categoria: relatorios
rota: /relatorios/relatorio-de-reservas
menu: Relatórios > Gerais > Relatório de reservas
icone: document-text-outline
situacao: funciona
palavras: relatório de reservas, relatórios, AP-085
rascunho: nao
---

## Para que serve

Reservas das áreas comuns no período, com a taxa e a situação. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/reservations`.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Relatório de reservas]].

```tela relatorio-de-reservas
rota: /relatorios/relatorio-de-reservas
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de reservas
1: menu | Relatório de reservas no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: De e Até (datas), Situação
4: main table thead | Colunas: Condomínio, Área, Unidade, Morador, Data, Das, Até, Taxa, Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **De e Até (datas)**: o período em datas; o padrão vai de 1º de janeiro até hoje.
2. **Situação**: opções: todas, aguardando aprovação, confirmada, cancelada.
3. Clique em {{Gerar}}.
4. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Condomínio**
- **Área**
- **Unidade**
- **Morador**
- **Data**
- **Das**
- **Até**
- **Taxa**
- **Situação**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.reservations.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
