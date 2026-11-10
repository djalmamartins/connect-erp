---
codigo: AP-096
titulo: Relatório de Endereços
resumo: Endereço e contato de cada condomínio do seu escopo.
categoria: relatorios
rota: /relatorios/relatorio-de-enderecos
menu: Relatórios > Gerais > Relatório de Endereços
icone: document-text-outline
situacao: funciona
palavras: relatório de endereços, relatórios, AP-096
rascunho: nao
---

## Para que serve

Endereço e contato de cada condomínio do seu escopo. Os números vêm dos lançamentos e cadastros do sistema, os mesmos da API `GET /api/v1/reports/addresses`.

## Onde fica

No menu lateral, abra [[Relatórios > Gerais > Relatório de Endereços]].

```tela relatorio-de-enderecos
rota: /relatorios/relatorio-de-enderecos
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de Endereços
1: menu | Relatório de Endereços no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main table thead | Colunas: Condomínio, CEP, Logradouro, Número, Complemento, Bairro, Cidade, UF, Telefone, E-mail
```

> **Dica:** este relatório lista todos os condomínios que a sua conta pode ver, mesmo com um condomínio escolhido na barra superior.

## Como gerar o relatório

1. Abra a página: o relatório já aparece com a situação de hoje.
2. Confira os totais no topo (quando o relatório tem valores) e as linhas na tabela.

### O que cada coluna mostra

- **Condomínio**
- **CEP**
- **Logradouro**
- **Número**
- **Complemento**
- **Bairro**
- **Cidade**
- **UF**
- **Telefone**
- **E-mail**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado ao conselho ou à assembleia. {{Exportar CSV}} baixa a mesma consulta em planilha (separador ponto e vírgula); cada exportação fica registrada na auditoria, com no máximo 30 por hora por pessoa.

## Quem pode usar

A tela aparece para quem tem a permissão **erp.condominiums.view**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta.
