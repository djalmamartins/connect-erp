---
codigo: AP-023
titulo: Contas a receber
resumo: Receitas em aberto do condomínio, como taxas condominiais, multas e acordos, por vencimento. Os boletos saem daqui, e a baixa é feita pelo arquivo de retorno do banco ou à mão.
categoria: financeiro
rota: /financeiro/contas-a-receber
menu: Financeiro > Contas e pagamentos > Contas a Receber
icone: list-outline
situacao: funciona
relacionados: boletos, lancar-receita, lancar-desconto, contas-a-pagar
palavras: contas a receber, financeiro, AP-023, receita, receber, taxa condominial, baixa
rascunho: nao
---

Receitas em aberto do condomínio, como taxas condominiais, multas e acordos, por vencimento. Os boletos saem daqui, e a baixa é feita pelo arquivo de retorno do banco ou à mão.

## Onde fica

No menu lateral, abra [[Financeiro > Contas e pagamentos > Contas a Receber]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela contas-a-receber
rota: /financeiro/contas-a-receber
condominio: residencial-jardim-das-acacias
legenda: Tela Contas a Receber
1: menu | Contas a Receber no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova conta a receber") | Botão {{Nova conta a receber}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Nova conta a receber}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Competência**, **Descrição**, **Valor**, **Conta do plano de contas**, **Vencimento** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela contas-a-receber-nova-conta-a-receber
rota: /financeiro/contas-a-receber/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova conta a receber
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) :is(.erp-month:has([name="competence"]), [name="competence"]:not([type=hidden])) | Competência (obrigatório)
3: main form:not([method=get]) [name="description"] | Descrição (obrigatório)
4: main form:not([method=get]) [name="amount_cents"] | Valor (obrigatório)
5: main form:not([method=get]) [name="chart_account_id"] | Conta do plano de contas (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Competência** (obrigatório): mês e ano de referência, ex.: nov/2026. Clique no calendário ao lado do campo e escolha o mês, ou digite 11/2026.
- **Descrição** (obrigatório).
- **Valor** (obrigatório).
- **Conta do plano de contas** (obrigatório).
- **Centro de custo**.
- **Vencimento** (obrigatório).
- **Pago em**: Preencha para dar baixa.
- **Conta bancária**: Obrigatória quando o lançamento está pago.
- **Fornecedor**.
- **Unidade**: Unidade que deve a receita (faturas preenchem sozinhas).
- **Nº do documento**: Nota fiscal, recibo ou boleto.
- **Observações**.
- **Situação** (obrigatório): Opções: Válido e Cancelado.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Descrição**, **Condomínio**, **Competência**, **Valor**, **Vencimento**, **Pago em** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Boletos](artigo:boletos), [Lançar uma receita](artigo:lancar-receita) e [Lançar um desconto numa conta a receber](artigo:lancar-desconto).
