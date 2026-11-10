---
codigo: AP-035
titulo: Boletos
resumo: Boletos das contas a receber das unidades: registro no banco, consulta, cancelamento e baixa pelo arquivo de retorno.
categoria: cobranca
rota: /cobranca/boletos
menu: Cobrança > Boletos
icone: list-outline
relacionados: faturas, arquivo-de-retorno, retornos, acordos, remessas, banco-inter
situacao: funciona
palavras: boletos, cobrança, AP-035, inter, pix, pdf, boleto híbrido
manual: sim
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

## Boletos pelo Banco Inter

Quando a conta bancária de cobrança é do Banco Inter e a integração está ligada (veja [Ligar a cobrança pelo Banco Inter](artigo:banco-inter)), o boleto é registrado direto no Inter:

- O boleto já sai **com Pix** (boleto híbrido). Na linha do boleto, {{Pix}} copia o Pix copia e cola e {{PDF}} abre o boleto do banco.
- O **pagador** é o responsável financeiro da unidade ou, se não houver, o proprietário. Ele precisa ter **CPF ou CNPJ** no cadastro, e o condomínio precisa ter o endereço completo (CEP, rua, cidade e UF). Sem isso, o boleto fica **Recusado** com o motivo, sem ir ao banco.
- Multa e juros vêm da régua de cobrança do condomínio.
- O registro no Inter leva alguns segundos. Se o boleto ficar **Aguardando banco**, clique em {{Consultar}}.
- A **baixa é automática**: quando o banco avisa um pagamento, o sistema consulta o boleto no Inter e dá baixa na conta a receber. Pagamento com valor diferente, ou de boleto já pago ou cancelado, vai para a fila de divergências em [Retornos](artigo:retornos), nunca é baixado sozinho.
- Boleto cancelado ou vencido sem pagamento no Inter aparece como **Cancelado** e libera um boleto novo para a mesma conta a receber.

>! Por enquanto o Inter está ligado só no **ambiente de testes do banco** (sandbox). Esses boletos não podem ser pagos.


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

