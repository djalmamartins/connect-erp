---
codigo: AP-061
titulo: Relatório Inadimplentes (Morador)
resumo: Contas a receber vencidas e em aberto na data de referência, com os encargos sugeridos pela regra de cobrança de cada condomínio.
categoria: relatorios
rota: /relatorios/inadimplentes-morador
menu: Relatórios > Financeiros > Inadimplentes (Morador)
icone: document-text-outline
situacao: funciona
palavras: inadimplentes (morador), relatórios, AP-061
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Relatórios > Financeiros > Inadimplentes (Morador)]].

```tela inadimplentes-morador
rota: /relatorios/inadimplentes-morador
condominio: residencial-jardim-das-acacias
legenda: Tela Inadimplentes (Morador)
1: menu | Inadimplentes (Morador) no menu lateral
2: main :is(a,button):has-text("Exportar CSV") | Botão {{Exportar CSV}}
3: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
4: main form[method=get] | Filtros: Data de referência
5: main table thead | Lista com Condomínio, Unidade, Responsável, Documento, Telefone, E-mail, Contas, Total e Maior atraso
```

> **Dica:** escolha o condomínio na barra superior para ver só os dados dele. Com "Todos os condomínios", a tela soma tudo o que a sua conta pode ver.

## Como gerar o relatório

1. Escolha data de referência.
2. Clique em {{Gerar}}.
3. Confira o resultado na tela.

### O que cada coluna mostra

- **Condomínio**
- **Unidade**
- **Responsável**
- **Documento**
- **Telefone**
- **E-mail**
- **Contas**
- **Total**
- **Maior atraso**

## Imprimir ou exportar

Use {{Imprimir}} e {{Exportar CSV}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Relatórios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

