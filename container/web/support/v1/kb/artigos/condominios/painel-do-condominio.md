---
codigo: AP-002
titulo: Painel do condomínio
resumo: Ocupação, financeiro e rotina do condomínio em 09/11/2026. Os números vêm dos cadastros e lançamentos.
categoria: condominios
rota: /condominios/painel-do-condominio
menu: Condomínios > Painel do condomínio
icone: speedometer-outline
situacao: funciona
palavras: painel do condomínio, condomínios, AP-002
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Condomínios > Painel do condomínio]].

```tela painel-do-condominio
rota: /condominios/painel-do-condominio
condominio: residencial-jardim-das-acacias
legenda: Tela Painel do condomínio
1: menu | Painel do condomínio no menu lateral
2: main :is(a,button):has-text("Ficha do condomínio") | Botão {{Ficha do condomínio}}
3: main .erp-card | Unidades
```

## O que a tela mostra

- **Unidades**
- **Saldo em bancos**
- **Inadimplência**
- **Resultado de 11/2026**
- **Alertas**
- **Inadimplência por atraso**
- **Contas bancárias**
- **Rotina**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Condomínios**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

