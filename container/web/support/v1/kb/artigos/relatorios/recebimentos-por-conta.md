---
codigo: AP-072
titulo: Relatório Recebimentos por Conta
resumo: Receitas pagas no período, pela data do pagamento.
categoria: relatorios
rota: /relatorios/recebimentos-por-conta
menu: Relatórios > Financeiros > Recebimentos por Conta
icone: document-text-outline
situacao: funciona
palavras: recebimentos por conta, relatórios, AP-072
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Recebimentos por Conta]].

```tela recebimentos-por-conta
rota: /relatorios/recebimentos-por-conta
condominio: residencial-jardim-das-acacias
legenda: Tela Recebimentos por Conta
1: menu | Recebimentos por Conta no menu lateral
2: main :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main form[method=get] | Filtros: De, Até, Agrupar por
5: main table thead | Lista com Condomínio, Conta, Recebimentos, Pagos com atraso e Valor
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como gerar o relatório

1. Escolha de, até e agrupar por.
2. Clique em {{Gerar}}.
3. Confira o resultado na tela.

### O que cada coluna mostra

- **Condomínio**
- **Conta**
- **Recebimentos**
- **Pagos com atraso**
- **Valor**

## Imprimir ou exportar

Use {{Imprimir}} e {{Exportar CSV}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

