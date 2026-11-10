---
codigo: AP-040
titulo: Emitir recibos de pagamento
resumo: Emite o recibo das contas já pagas de uma unidade, escolhidas uma a uma. Cada conta entra em um só recibo válido; se cancelar o recibo, a conta fica livre para outro.
categoria: financeiro
rota: /financeiro/recibos
menu: Financeiro > Contas e pagamentos > Recibos
icone: list-outline
situacao: funciona
relacionados: faturas, pagamentos-bancos, contas-a-receber, contas-a-pagar
palavras: recibos, financeiro, AP-040
rascunho: nao
---

Emite o recibo das contas já pagas de uma unidade, escolhidas uma a uma. Cada conta entra em um só recibo válido; se cancelar o recibo, a conta fica livre para outro.

## Onde fica

No menu lateral, abra [[Financeiro > Contas e pagamentos > Recibos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela recibos
rota: /financeiro/recibos
condominio: residencial-jardim-das-acacias
legenda: Tela Recibos
1: menu | Recibos no menu lateral
2: main :is(form[method=get], .erp-filters) | Filtros: Unidade
3: main form:not([method=get]) | Conferir autenticidade
```

## Conferir autenticidade

Digite o código impresso no documento para abrir o original.

1. Preencha **Código de verificação**.
2. Clique em {{Conferir}}.

```tela recibos-form
rota: /financeiro/recibos
condominio: residencial-jardim-das-acacias
legenda: Conferir autenticidade
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="code"] | Código de verificação (obrigatório)
2: main form:not([method=get]) button:has-text("Conferir") | Botão {{Conferir}}
```

### O que preencher

- **Código de verificação** (obrigatório): Exemplo: ABCD-EFGH-JKMN-PQRS.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Unidade**.
Depois clique em {{Ver contas pagas}}. Para voltar à lista completa, limpe os filtros.

> Todo recibo tem um código de verificação: quem recebeu pode conferir a autenticidade no bloco **Conferir autenticidade**.

> Na tela: *Escolha a unidade acima e emita o primeiro.*

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Faturas](artigo:faturas), [Pagamentos pelo banco](artigo:pagamentos-bancos) e [Contas a receber](artigo:contas-a-receber).
