---
codigo: AP-137
titulo: Unidades em atraso e aviso automático de cobrança
resumo: Mostra as unidades com conta vencida. O aviso de atraso por e-mail agora é automático: sai com 10, 15, 30, 45 e 60 dias de atraso, só para quem autorizou cobrança por e-mail, e cada boleto é avisado uma vez em cada marco.
categoria: comunicacao
rota: /comunicacao/cobranca
menu: Comunicação > Mala direta > Cobrança
icone: list-outline
situacao: funciona
relacionados: agendada, atendimento-cobranca, enderecos
palavras: cobrança, comunicação, AP-137
rascunho: nao
---

Mostra as unidades com conta vencida. O aviso de atraso por e-mail agora é automático: sai com 10, 15, 30, 45 e 60 dias de atraso, só para quem autorizou cobrança por e-mail, e cada boleto é avisado uma vez em cada marco.

## Onde fica

No menu lateral, abra [[Comunicação > Mala direta > Cobrança]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela cobranca
rota: /comunicacao/cobranca
condominio: residencial-jardim-das-acacias
legenda: Tela Cobrança
1: menu | Cobrança no menu lateral
2: main :is(form[method=get], .erp-filters) | Filtros: Data de referência
3: main table thead | Colunas da lista
```

## Aviso automático por e-mail

O morador recebe por e-mail só o que a Connect decidiu (ADR 0601):

- o boleto **no dia do vencimento**, avisando que vence hoje;
- o **aviso de atraso com 10, 15, 30, 45 e 60 dias**;
- a **prestação de contas do mês**, entre os dias 5 e 10;
- o **convite de cadastro** e a **recuperação de senha**, quando ele pede.

O aviso de cobrança manual desta tela foi desligado. Os avisos de boleto saem sozinhos, uma vez por dia de manhã, e
cada boleto é avisado uma única vez em cada marco. Boleto pago deixa de ser avisado. Só recebe quem autorizou a
cobrança por e-mail no cadastro da pessoa.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Data de referência**.
Depois clique em {{Atualizar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Unidade**, **Contas**, **Em aberto**, **Vencimento mais antigo**, **Atraso** e **Aviso**.

## Para falar com uma unidade

Use o [Atendimento de cobrança](artigo:atendimento-cobranca) para registrar o contato, a promessa de pagamento e a próxima ação.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Comunicação**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Comunicados agendados](artigo:agendada), [Atender as unidades em atraso](artigo:atendimento-cobranca) e [Quem recebe os comunicados (endereços)](artigo:enderecos).
