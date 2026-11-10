---
codigo: AP-040
titulo: Recibos
resumo: Recibo de contas já pagas da unidade, escolhidas uma a uma. Cada conta entra em um recibo válido só; cancelar o recibo libera a conta para outro.
categoria: financeiro
rota: /financeiro/recibos
menu: Financeiro > Contas e pagamentos > Recibos
icone: list-outline
situacao: funciona
palavras: recibos, financeiro, AP-040
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Financeiro > Contas e pagamentos > Recibos]].

```tela recibos
rota: /financeiro/recibos
condominio: residencial-jardim-das-acacias
legenda: Tela Recibos
1: menu | Recibos no menu lateral
2: main form[method=get] | Filtros: Unidade
3: main .erp-card | Emitir
```

## Como usar

1. Ajuste os filtros (Unidade).
2. Clique em {{Filtrar}}.
3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

