---
codigo: AP-114
titulo: Agendamentos Pendentes
resumo: Reservas de áreas comuns que aguardam aprovação. Confirme ou recuse cada pedido; o horário recusado fica livre para outra reserva.
categoria: operacional
rota: /operacional/agendamentos-pendentes
menu: Operacional > Rotina > Agendamentos Pendentes
icone: list-outline
situacao: funciona
palavras: agendamentos pendentes, operacional, AP-114
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Agendamentos Pendentes]].

```tela agendamentos-pendentes
rota: /operacional/agendamentos-pendentes
condominio: residencial-jardim-das-acacias
legenda: Tela Agendamentos Pendentes
1: menu | Agendamentos Pendentes no menu lateral
2: main :is(a,button):has-text("Todas as reservas") | Botão {{Todas as reservas}}
3: main table thead | Lista com Data, Área comum, Unidade e morador e Taxa
```

## Como usar

Os botões no alto da tela são {{Todas as reservas}}.

### O que cada coluna mostra

- **Data**
- **Área comum**
- **Unidade e morador**
- **Taxa**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

