---
codigo: AP-089
titulo: Relatório de condomínios
resumo: Condomínios do seu escopo com unidades ativas, pessoas e contas bancárias.
categoria: relatorios
rota: /relatorios/relatorio-de-condominios
menu: Relatórios > Gerais > Relatório de condomínios
icone: document-text-outline
situacao: funciona
palavras: relatório de condomínios, relatórios, AP-089
rascunho: nao
---

## Para que serve

Condomínios do seu escopo com unidades ativas, pessoas e contas bancárias. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/condominiums`.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Relatório de condomínios]].

```tela relatorio-de-condominios
rota: /relatorios/relatorio-de-condominios
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de condomínios
1: menu | Relatório de condomínios no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main table thead | Colunas: Condomínio, CNPJ, Cidade, UF, Situação, Unidades, Pessoas, Contas bancárias
```

> **Dica:** este relatório lista todos os condomínios que a sua conta pode ver, mesmo com um condomínio escolhido na barra superior.

## Como gerar o relatório

1. Abra a página: o relatório já aparece com a situação de hoje.
2. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Condomínio**
- **CNPJ**
- **Cidade**
- **UF**
- **Situação**
- **Unidades**
- **Pessoas**
- **Contas bancárias**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.condominiums.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
