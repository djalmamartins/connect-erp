---
codigo: AP-116
titulo: Multas pendentes
resumo: As multas avulsas que a unidade ainda não pagou, com vencimento e motivo. A multa entra na cobrança como qualquer conta a receber.
categoria: cobranca
rota: /cobranca/multas-pendentes
menu: Cobrança > Multas Pendentes
icone: list-outline
situacao: funciona
relacionados: lancar-multa-avulsa, cobranca-judicial, retornos, arquivo-de-retorno
palavras: multas pendentes, cobrança, AP-116
rascunho: nao
---

As multas avulsas que a unidade ainda não pagou, com vencimento e motivo. A multa entra na cobrança como qualquer conta a receber.

## Onde fica

No menu lateral, abra [[Cobrança > Multas Pendentes]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela multas-pendentes
rota: /cobranca/multas-pendentes
condominio: residencial-jardim-das-acacias
legenda: Tela Multas Pendentes
1: menu | Multas Pendentes no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Lançar multa") | Botão {{Lançar multa}}
```

## Outros botões da tela

- {{Lançar multa}}: abre o formulário de multa avulsa.

## O que a tela mostra

- **Multas não pagas**

> Na tela: *As multas avulsas lançadas e ainda não pagas aparecem aqui.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cobrança**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Lançar uma multa avulsa](artigo:lancar-multa-avulsa), [Cobrança judicial](artigo:cobranca-judicial) e [Retornos importados e divergências](artigo:retornos).
