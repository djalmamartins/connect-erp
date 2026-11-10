---
codigo: AP-114
titulo: Aprovar reservas pendentes
resumo: Reservas que aguardam aprovação. Confirme ou recuse cada pedido; o horário recusado fica livre para outra reserva, e quem está na lista de espera é avisado.
categoria: operacional
rota: /operacional/agendamentos-pendentes
menu: Operacional > Rotina > Agendamentos Pendentes
icone: list-outline
situacao: funciona
relacionados: reservas-areas-comuns, lista-de-espera, ocorrencias, seguros
palavras: agendamentos pendentes, operacional, AP-114
rascunho: nao
---

Reservas que aguardam aprovação. Confirme ou recuse cada pedido; o horário recusado fica livre para outra reserva, e quem está na lista de espera é avisado.

## Onde fica

No menu lateral, abra [[Operacional > Rotina > Agendamentos Pendentes]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela agendamentos-pendentes
rota: /operacional/agendamentos-pendentes
condominio: residencial-jardim-das-acacias
legenda: Tela Agendamentos Pendentes
1: menu | Agendamentos Pendentes no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Todas as reservas") | Botão {{Todas as reservas}}
3: main table thead | Colunas da lista
```

## Outros botões da tela

- {{Todas as reservas}}: abre a lista completa de reservas.

## O que a lista mostra

Colunas: **Data**, **Área comum**, **Unidade e morador** e **Taxa**.

## Ações de cada linha

- **Abrir** mostra os detalhes do item.
- **Confirmar** aprova o pedido, e o morador é avisado.
- **Recusar** nega o pedido. Informe o motivo para o morador entender.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Operacional**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Reservas de áreas comuns](artigo:reservas-areas-comuns), [Lista de espera das áreas comuns](artigo:lista-de-espera) e [Registrar ocorrências](artigo:ocorrencias).
