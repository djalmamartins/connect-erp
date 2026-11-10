---
codigo: AP-021
titulo: Lançamentos fixos (recorrentes)
resumo: Receitas e despesas que se repetem todo mês, como contrato de limpeza ou salário. Cadastre uma vez e gere os lançamentos de cada competência sem digitar de novo. Gerar de novo a mesma competência não duplica.
categoria: financeiro
rota: /financeiro/lancamentos-fixos
menu: Financeiro > Lançamentos > Lançamentos fixos
icone: list-outline
situacao: funciona
relacionados: lancamentos, lancar-despesa, lancar-receita, lancar-desconto
palavras: lançamentos fixos, financeiro, AP-021, recorrente, mensal, repetir
rascunho: nao
---

Receitas e despesas que se repetem todo mês, como contrato de limpeza ou salário. Cadastre uma vez e gere os lançamentos de cada competência sem digitar de novo. Gerar de novo a mesma competência não duplica.

## Onde fica

No menu lateral, abra [[Financeiro > Lançamentos > Lançamentos fixos]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela lancamentos-fixos
rota: /financeiro/lancamentos-fixos
condominio: residencial-jardim-das-acacias
legenda: Tela Lançamentos fixos
1: menu | Lançamentos fixos no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo lançamento fixo") | Botão {{Novo lançamento fixo}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Natureza e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo lançamento fixo}}, no alto da lista.
2. Preencha os campos obrigatórios: **Condomínio**, **Natureza**, **Descrição**, **Valor**, **Conta do plano de contas**, **Dia do vencimento**, **Primeira competência** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela lancamentos-fixos-novo-lancamento-fixo
rota: /financeiro/lancamentos-fixos/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo lançamento fixo
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio (obrigatório)
2: main form:not([method=get]) [name="nature"] | Natureza (obrigatório)
3: main form:not([method=get]) [name="description"] | Descrição (obrigatório)
4: main form:not([method=get]) [name="amount_cents"] | Valor (obrigatório)
5: main form:not([method=get]) [name="chart_account_id"] | Conta do plano de contas (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio** (obrigatório).
- **Natureza** (obrigatório): Opções: Receita e Despesa.
- **Descrição** (obrigatório).
- **Valor** (obrigatório).
- **Conta do plano de contas** (obrigatório).
- **Centro de custo**.
- **Conta bancária**: Conta sugerida; o lançamento gerado nasce em aberto.
- **Fornecedor**.
- **Dia do vencimento** (obrigatório): 1 a 28.
- **Primeira competência** (obrigatório): AAAA-MM.
- **Última competência**: Vazio = sem fim.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Natureza** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Descrição**, **Condomínio**, **Natureza**, **Valor**, **Dia do vencimento**, **Primeira competência**, **Última competência** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Financeiro**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Lançamentos: receitas e despesas](artigo:lancamentos), [Lançar uma despesa](artigo:lancar-despesa) e [Lançar uma receita](artigo:lancar-receita).
