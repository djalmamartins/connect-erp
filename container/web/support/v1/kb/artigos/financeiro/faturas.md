---
codigo: AP-032
titulo: Faturas
resumo: Cobrança de cada tipo de fatura das unidades ativas do condomínio. Prepare, confira os valores por unidade e emita para gerar as contas a receber.
categoria: financeiro
rota: /financeiro/faturas
menu: Financeiro > Contas e pagamentos > Faturas
icone: list-outline
relacionados: boletos, contas-a-receber, acordos, fechamento-mensal
situacao: funciona
palavras: faturas, financeiro, AP-032
manual: sim
rascunho: nao
---

## Onde fica

No menu lateral, abra [[Financeiro > Contas e pagamentos > Faturas]].

```tela faturas
rota: /financeiro/faturas
condominio: residencial-jardim-das-acacias
legenda: Tela Faturas
1: menu | Faturas no menu lateral
2: main :is(a,button):has-text("Preparar fatura") | Botão {{Preparar fatura}}
3: main form[method=get] | Filtros: Competência, Situação
4: main table thead | Lista com Competência, Tipo, Valor e Situação
```

## Preparar a fatura do mês

A fatura é preparada para **todas as unidades ativas** do condomínio de uma vez. Nada é cobrado ainda: você confere unidade por unidade e só depois emite.

1. Confira o condomínio na barra superior.
2. Clique em {{Preparar fatura}}.
3. Escolha o **Tipo de fatura** (por exemplo, taxa condominial), a **Competência** e o **Vencimento**.
4. Em **Cálculo**, escolha como o valor é dividido. Deixe **Valor por unidade** vazio para usar o valor padrão do tipo.
5. Clique em {{Preparar}}.

```tela faturas-preparar
rota: /financeiro/faturas
condominio: residencial-jardim-das-acacias
legenda: Preparar a fatura de todas as unidades
antes: clicar main :is(a,button):has-text("Preparar fatura")
antes: esperar 500
recorte: dialog[open]
1: #inv-type | Tipo de fatura
2: #inv-competence | Mês de competência
3: #inv-due | Vencimento
4: #inv-method | Como o valor é calculado
5: #inv-amount | Valor por unidade (vazio = padrão do tipo)
6: dialog[open] button[type=submit] | Botão {{Preparar}}
```

> Preparar de novo a mesma competência e o mesmo tipo não duplica a fatura: o sistema mostra a que já existe.

## Conferir e emitir

Na lista, abra a fatura preparada. Confira o valor de cada unidade, ajuste o que for preciso e clique em **Emitir**. A emissão gera uma conta a receber para cada unidade, e a partir daí você pode [emitir os boletos](artigo:boletos).

>! Depois de emitida, a fatura não volta a ser rascunho. Para corrigir uma unidade, use [Lançar desconto](artigo:lancar-desconto) ou cancele a conta a receber dela.

>> Tudo sobre a cobrança: [Boletos](artigo:boletos) e [Acordos](artigo:acordos).

## Como usar

Os botões no alto da tela são {{Preparar fatura}}.

1. Ajuste os filtros (Competência e Situação).
2. Clique em {{Filtrar}}.
3. Confira a lista. Use as ações da linha para abrir, alterar ou cancelar um item.

### O que cada coluna mostra

- **Competência**
- **Tipo**
- **Valor**
- **Situação**

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

