---
codigo: AP-137
titulo: Cobrança
resumo: Aviso de débitos em aberto para as unidades com conta vencida. Só recebe quem autorizou a cobrança por e-mail, e a unidade avisada nos últimos 7 dias fica de fora.
categoria: comunicacao
rota: /comunicacao/cobranca
menu: Comunicação > Mala direta > Cobrança
icone: list-outline
situacao: funciona
palavras: cobrança, comunicação, AP-137
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Comunicação > Mala direta > Cobrança]].

```tela cobranca
rota: /comunicacao/cobranca
condominio: residencial-jardim-das-acacias
legenda: Tela Cobrança
1: menu | Cobrança no menu lateral
2: main form[method=get] | Filtros: Data de referência
3: main table thead | Lista com Marcar todas as unidades, Unidade, Contas, Em aberto, Vencimento mais antigo, Atraso e Aviso
```

## Como usar

1. Ajuste os filtros (Data de referência).
2. Clique em {{Filtrar}}.
3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.

### O que cada coluna mostra

- **Marcar todas as unidades**
- **Unidade**
- **Contas**
- **Em aberto**
- **Vencimento mais antigo**
- **Atraso**
- **Aviso**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Comunicação**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

