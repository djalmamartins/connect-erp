---
codigo: AP-045
titulo: Relatório Consumo (Geral)
resumo: Leituras, consumo e valor por tipo de medidor nas competências escolhidas.
categoria: relatorios
rota: /relatorios/consumo-geral
menu: Relatórios > Financeiros > Consumo (Geral)
icone: document-text-outline
situacao: funciona
palavras: consumo (geral), relatórios, AP-045
rascunho: nao
---

## Para que serve

Leituras, consumo e valor por tipo de medidor nas competências escolhidas. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/consumption`.

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Consumo (Geral)]].

```tela consumo-geral
rota: /relatorios/consumo-geral
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório Consumo (Geral)
1: menu | Consumo (Geral) no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form.erp-filters | Filtros: Competência inicial e final
4: main table thead | Colunas: Condomínio, Tipo, Leituras, Consumo, Valor, Cobradas
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela junta tudo o que a sua conta pode ver.

## Como gerar o relatório

1. **Competência inicial e final**: a faixa de competências (no máximo 24); o padrão vai de janeiro até o mês atual.
2. Clique em {{Gerar}}.
3. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Condomínio**
- **Tipo**
- **Leituras**
- **Consumo**
- **Valor**
- **Cobradas**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.meter_readings.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
