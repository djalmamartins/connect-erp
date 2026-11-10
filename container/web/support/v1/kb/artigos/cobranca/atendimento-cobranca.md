---
codigo: AP-117
titulo: Atender as unidades em atraso
resumo: A fila de cobrança mostra as unidades em atraso na ordem de prioridade: primeiro as promessas de pagamento quebradas, depois as próximas ações vencidas, as unidades sem contato e as de maior atraso. Use todo dia para fazer os contatos.
categoria: cobranca
rota: /cobranca/atendimento-cobranca
menu: Cobrança > Atendimento Cobrança
icone: list-outline
situacao: funciona
relacionados: acordos, inadimplentes-unidade, parcelas-de-acordo
palavras: atendimento cobrança, cobrança, AP-117
rascunho: nao
---

A fila de cobrança mostra as unidades em atraso na ordem de prioridade: primeiro as promessas de pagamento quebradas, depois as próximas ações vencidas, as unidades sem contato e as de maior atraso. Use todo dia para fazer os contatos.

## Onde fica

No menu lateral, abra [[Cobrança > Atendimento Cobrança]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela atendimento-cobranca
rota: /cobranca/atendimento-cobranca
condominio: residencial-jardim-das-acacias
legenda: Tela Atendimento Cobrança
1: menu | Atendimento Cobrança no menu lateral
2: main table thead | Colunas da lista
```

## O que a lista mostra

Colunas: **Unidade**, **Em aberto**, **Último contato** e **Próxima ação**.

## Ações de cada linha

- **Atender** abre o atendimento da unidade, com as contas em aberto e o histórico de contatos.

## Como atender

1. Clique em {{Atender}} na unidade do topo da fila.
2. Veja as contas em aberto e o histórico de contatos.
3. Registre o contato: o que foi conversado, a promessa de pagamento (se houver) e a próxima ação.
4. A unidade volta para a fila na data da próxima ação.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cobrança**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Acordos](artigo:acordos), [Inadimplentes por unidade](artigo:inadimplentes-unidade) e [Parcelas de acordo](artigo:parcelas-de-acordo).
