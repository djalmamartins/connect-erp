---
codigo: AP-051
titulo: Relatório Demonstrativo
resumo: Prestação de contas pelo regime de caixa: o que foi recebido e pago no período, por conta do plano e por conta bancária, conciliado com os saldos.
categoria: prestacao-de-contas
rota: /prestacao-de-contas/demonstrativo
menu: Prestação de contas > Demonstrativo
icone: document-text-outline
situacao: funciona
palavras: demonstrativo, prestação de contas, AP-051
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Prestação de contas > Demonstrativo]].

```tela demonstrativo
rota: /prestacao-de-contas/demonstrativo
condominio: residencial-jardim-das-acacias
legenda: Tela Demonstrativo
1: menu | Demonstrativo no menu lateral
2: main :is(a,button):has-text("Imprimir") | Botão {{Imprimir}}
3: main form[method=get] | Filtros: De, Até
4: main table thead | Lista com Conta, Saldo anterior, Entradas, Saídas e Saldo final
```

## Como gerar o relatório

1. Escolha de e até.
2. Clique em {{Gerar}}.
3. Confira o resultado na tela.

### O que cada coluna mostra

- **Conta**
- **Saldo anterior**
- **Entradas**
- **Saídas**
- **Saldo final**

## Imprimir ou exportar

Use {{Imprimir}} para levar o resultado para a reunião, o conselho ou a planilha.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Prestação de contas**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

