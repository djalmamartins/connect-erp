---
codigo: AP-080
titulo: Relatório Balancete Anual
resumo: Receitas e despesas de cada conta do plano mês a mês no ano, com as contas sintéticas somadas e o resultado de cada mês.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/balancete-anual
menu: Prestação de contas > Balancete Anual
icone: document-text-outline
situacao: funciona
palavras: balancete anual, prestação de contas, AP-080
rascunho: nao
---

## Para que serve

Receitas e despesas de cada conta do plano mês a mês no ano, com as contas sintéticas somadas e o resultado de cada mês. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/annual-balance`.

## Onde fica

No menu lateral, abra [[Prestação de contas > Balancete Anual]].

```tela balancete-anual
rota: /prestacao-de-contas/balancete-anual
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório Balancete Anual
1: menu | Balancete Anual no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: Ano, Base
4: main table thead | Colunas: Código, Conta, Natureza, Jan, Fev, Mar, Abr, Mai, Jun, Jul, Ago, Set, Out, Nov, Dez, Total
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **Ano**: o ano do relatório.
2. **Base**: opções: competência, caixa (pagamento).
3. Clique em {{Gerar}}.
4. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Código**
- **Conta**
- **Natureza**
- **Jan**
- **Fev**
- **Mar**
- **Abr**
- **Mai**
- **Jun**
- **Jul**
- **Ago**
- **Set**
- **Out**
- **Nov**
- **Dez**
- **Total**

No rodapé ficam o total de receitas, o total de despesas e o resultado de cada mês. As contas sintéticas (em negrito) somam as analíticas abaixo delas. Na **Base**, "Competência" usa o mês da competência e "Caixa" usa o mês do pagamento.

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.entries.view** e **erp.chart.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
