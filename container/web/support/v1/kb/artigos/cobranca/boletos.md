---
codigo: AP-035
titulo: Boletos
resumo: Boletos das contas a receber das unidades: registro no banco, consulta, cancelamento e baixa pelo arquivo de retorno.
categoria: cobranca
rota: /cobranca/boletos
menu: Cobrança > Boletos
icone: list-outline
relacionados: faturas, arquivo-de-retorno, retornos, acordos
situacao: funciona
palavras: boletos, cobrança, AP-035
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Cobrança > Boletos]].

```tela boletos
rota: /cobranca/boletos
condominio: residencial-jardim-das-acacias
legenda: Tela Boletos
1: menu | Boletos no menu lateral
2: main :is(a,button):has-text("Emitir boleto") | Botão {{Emitir boleto}}
3: main form[method=get] | Filtros: Situação
4: main table thead | Lista com Nosso número, Vencimento, Valor, Linha digitável e Situação
```

## Emitir um boleto

O boleto sai de uma **conta a receber em aberto** (gerada pela fatura) e de uma **conta bancária de cobrança**. O valor e o vencimento vêm do lançamento.

1. Clique em {{Emitir boleto}}.
2. Escolha a **Conta bancária** de cobrança.
3. Em **Buscar conta a receber**, digite a descrição ou a competência (ex.: `2026-11`) e escolha o lançamento.
4. Clique em {{Emitir}}.

```tela boletos-emitir
rota: /cobranca/boletos
condominio: residencial-jardim-das-acacias
legenda: Emitir o boleto de uma conta a receber
antes: clicar main :is(a,button):has-text("Emitir boleto")
antes: esperar 500
recorte: dialog[open]
1: #chg-account | Conta bancária de cobrança
2: #chg-search | Buscar pela descrição ou competência
3: #chg-entry | Conta a receber em aberto
4: dialog[open] button[type=submit] | Botão {{Emitir}}
```

> Pedir o boleto de novo para o mesmo lançamento devolve o mesmo boleto, sem duplicar a cobrança.

>! Enquanto o banco estiver em modo de teste, os boletos são de homologação e não valem para pagamento.

O pagamento é baixado automaticamente quando o [arquivo de retorno](artigo:arquivo-de-retorno) do banco é importado.


## Como usar

Os botões no alto da tela são {{Emitir boleto}}.

1. Ajuste os filtros (Situação).
2. Clique em {{Filtrar}}.
3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.

### O que cada coluna mostra

- **Nosso número**
- **Vencimento**
- **Valor**
- **Linha digitável**
- **Situação**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cobrança**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

