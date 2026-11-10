---
codigo: AP-047
titulo: Relatório Relatório de acordos
resumo: Acordos criados no período, com as parcelas pagas, o que segue em aberto e o que venceu. Acordo cancelado aparece, mas fica fora das somas.
categoria: relatorios
rota: /relatorios/relatorio-de-acordos
menu: Relatórios > Financeiros > Relatório de acordos
icone: document-text-outline
situacao: funciona
palavras: relatório de acordos, relatórios, AP-047
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Relatório de acordos]].

```tela relatorio-de-acordos
rota: /relatorios/relatorio-de-acordos
condominio: residencial-jardim-das-acacias
legenda: Tela Relatório de acordos
1: menu | Relatório de acordos no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form[method=get] | Filtros: De, Até, Situação
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como gerar o relatório

1. Escolha de, até e situação.
2. Clique em {{Gerar}}.
3. Confira o resultado na tela.

### O que cada coluna mostra

- **Condomínio**
- **Unidade**
- **Acordo**
- **Situação**
- **Criado em**
- **Parcelas pagas**
- **Total**
- **Pago**
- **Em aberto**
- **Vencido**
- **Próximo vencimento**

## Imprimir ou exportar

Use {{Imprimir}} e {{Exportar CSV}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

