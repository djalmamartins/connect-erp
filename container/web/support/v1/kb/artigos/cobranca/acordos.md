---
codigo: AP-033
titulo: Acordos
resumo: Contas a receber vencidas por unidade e os acordos feitos com elas. No acordo, as contas vencidas são trocadas por parcelas mensais.
categoria: cobranca
rota: /cobranca/acordos
menu: Cobrança > Acordos
icone: list-outline
relacionados: parcelas-de-acordo, boletos, faturas
situacao: funciona
palavras: acordos, cobrança, AP-033
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Cobrança > Acordos]].

```tela acordos
rota: /cobranca/acordos
condominio: residencial-jardim-das-acacias
legenda: Tela Acordos
1: menu | Acordos no menu lateral
2: main :is(a,button):has-text("Parcelas") | Botão {{Parcelas}}
3: main form[method=get] | Filtros: Acordos
4: main table thead | Lista com Unidade, Mais antiga, Atraso, Valor, Atualizado, Acordo, Parcelas e Situação
```

## Fazer um acordo

O acordo junta as contas em atraso de uma unidade e as divide em parcelas novas.

1. Na lista de inadimplentes, clique em {{Fazer acordo}} na unidade.
2. Confira as contas marcadas. Desmarque o que não entra no acordo.
3. Escolha a **Conta do plano** das parcelas, o número de **Parcelas** e o **1º vencimento**.
4. Se houver, informe **Encargos** e **Desconto**.
5. Clique em {{Fazer acordo}}.

```tela acordos-novo
rota: /cobranca/acordos
condominio: residencial-jardim-das-acacias
legenda: Acordo com as contas em atraso da unidade
antes: clicar main :is(a,button):has-text("Fazer acordo")
antes: esperar 500
recorte: dialog[open]
1: dialog[open] input[name=entry_ids] | Contas em atraso que entram no acordo
2: #agr-installments | Número de parcelas
3: #agr-first | Vencimento da 1ª parcela
4: #agr-fees | Encargos (multa e juros)
5: dialog[open] button[type=submit] | Botão {{Fazer acordo}}
```

> As contas do acordo saem da inadimplência e as parcelas aparecem em [Parcelas de acordo](artigo:parcelas-de-acordo).

>! O acordo é quebrado só manualmente, com motivo, quando uma parcela passa de 30 dias de atraso ou duas ficam em aberto.


## Como usar

Os botões no alto da tela são {{Parcelas}}.

1. Ajuste os filtros (Acordos).
2. Clique em {{Filtrar}}.
3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.

### O que cada coluna mostra

- **Unidade**
- **Mais antiga**
- **Atraso**
- **Valor**
- **Atualizado**
- **Acordo**
- **Parcelas**
- **Situação**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Cobrança**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

