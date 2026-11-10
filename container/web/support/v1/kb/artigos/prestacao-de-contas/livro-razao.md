---
codigo: AP-083
titulo: Relatório Livro Razão
resumo: Movimento de cada conta do plano no período: saldo anterior, cada lançamento com débito ou crédito e o saldo corrido.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/livro-razao
menu: Prestação de contas > Livro Razão
icone: document-text-outline
situacao: funciona
palavras: livro razão, prestação de contas, AP-083
rascunho: nao
---

## Para que serve

Movimento de cada conta do plano no período: saldo anterior, cada lançamento com débito ou crédito e o saldo corrido. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/ledger`.

## Onde fica

No menu lateral, abra [[Prestação de contas > Livro Razão]].

```tela livro-razao
rota: /prestacao-de-contas/livro-razao
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório Livro Razão
1: menu | Livro Razão no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: De e Até (datas), Base, Conta do plano (código)
4: main table thead | Colunas: Código, Conta, Data, Histórico, Débito, Crédito, Saldo
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **De e Até (datas)**: o período em datas; o padrão vai de 1º de janeiro até hoje.
2. **Base**: opções: competência, caixa (pagamento).
3. **Conta do plano (código)**: opcional; mostra só essa conta e as contas abaixo dela, ex.: 2.01.
4. Clique em {{Gerar}}.
5. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Código**
- **Conta**
- **Data**
- **Histórico**
- **Débito**
- **Crédito**
- **Saldo**

Na **Base**, "Competência" usa o mês da competência do lançamento e "Caixa" usa a data do pagamento. O período vai até 366 dias.

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.entries.view** e **erp.chart.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
