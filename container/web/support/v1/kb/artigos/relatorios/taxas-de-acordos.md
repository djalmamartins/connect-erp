---
codigo: AP-048
titulo: Relatório Taxas de Acordos
resumo: Dívida original, encargos e descontos dos acordos criados no período, por condomínio. Encargos líquidos = encargos − descontos.
categoria: relatorios
rota: /relatorios/taxas-de-acordos
menu: Relatórios > Financeiros > Taxas de Acordos
icone: document-text-outline
situacao: funciona
palavras: taxas de acordos, relatórios, AP-048
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Taxas de Acordos]].

```tela taxas-de-acordos
rota: /relatorios/taxas-de-acordos
condominio: residencial-jardim-das-acacias
legenda: Tela Taxas de Acordos
1: menu | Taxas de Acordos no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form[method=get] | Filtros: De, Até
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como gerar o relatório

1. Escolha de e até.
2. Clique em {{Gerar}}.
3. Confira o resultado na tela.

### O que cada coluna mostra

- **Condomínio**
- **Acordos**
- **Cancelados**
- **Dívida original**
- **Encargos**
- **Descontos**
- **Encargos líquidos**
- **Total**

## Imprimir ou exportar

Use {{Imprimir}} e {{Exportar CSV}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

