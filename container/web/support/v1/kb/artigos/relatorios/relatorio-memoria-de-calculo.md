---
codigo: AP-075
titulo: Relatório Memória de Cálculo
resumo: Correção, multa e juros de cada conta vencida na data de referência, pela regra de cobrança do condomínio.
categoria: relatorios
rota: /relatorios/relatorio-memoria-de-calculo
menu: Relatórios > Financeiros > Relatório Memória de Cálculo
icone: document-text-outline
situacao: funciona
palavras: relatório memória de cálculo, relatórios, AP-075
rascunho: nao
---

## Para que serve

Correção, multa e juros de cada conta vencida na data de referência, pela regra de cobrança do condomínio. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/calculation`.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Relatório Memória de Cálculo]].

```tela relatorio-memoria-de-calculo
rota: /relatorios/relatorio-memoria-de-calculo
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório Memória de Cálculo
1: menu | Relatório Memória de Cálculo no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: Data de referência, Unidade
4: main table thead | Colunas: Unidade, Descrição, Competência, Vencimento, Atraso, Principal, Correção, Multa, Juros, Total
```

>! Este relatório usa os dados de **um condomínio só**. Escolha o condomínio na barra superior; com "Todos os condomínios", a tela pede a escolha.

## Como gerar o relatório

1. **Data de referência**: o dia em que os encargos são calculados; o padrão é hoje.
2. **Unidade**: limita a uma unidade.
3. Clique em {{Gerar}}.
4. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Unidade**
- **Descrição**
- **Competência**
- **Vencimento**
- **Atraso**
- **Principal**
- **Correção**
- **Multa**
- **Juros**
- **Total**

A tela mostra a regra de cobrança usada (multa, juros e índice) e a fórmula. Mês sem taxa do índice cadastrada fica sem correção e aparece no aviso.

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.entries.view** e **erp.agreements.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
