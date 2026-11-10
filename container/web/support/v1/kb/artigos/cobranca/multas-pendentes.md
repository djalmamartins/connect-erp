---
codigo: AP-116
titulo: Multas Pendentes
resumo: Multas avulsas que a unidade ainda não pagou, com o vencimento e o que as motivou. A multa entra na cobrança da unidade como qualquer conta a receber.
categoria: cobranca
rota: /cobranca/multas-pendentes
menu: Cobrança > Multas Pendentes
icone: list-outline
situacao: funciona
palavras: multas pendentes, cobrança, AP-116
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Cobrança > Multas Pendentes]].

```tela multas-pendentes
rota: /cobranca/multas-pendentes
condominio: residencial-jardim-das-acacias
legenda: Tela Multas Pendentes
1: menu | Multas Pendentes no menu lateral
2: main :is(a,button):has-text("Lançar multa") | Botão {{Lançar multa}}
3: main .erp-card | Multas não pagas
```

## Como usar

Os botões no alto da tela são {{Lançar multa}}.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cobrança**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

