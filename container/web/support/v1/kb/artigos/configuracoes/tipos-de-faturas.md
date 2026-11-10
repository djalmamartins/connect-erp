---
codigo: AP-168
titulo: Tipos de faturas
resumo: O que é cobrado das unidades (taxa condominial, fundo de reserva, consumo) e a conta de receita de cada cobrança, com valor padrão e recorrência. Sem condomínio, o tipo vale para todos.
categoria: configuracoes
rota: /configuracoes/tipos-de-faturas
menu: Configurações > Financeiro e parâmetros > Tipos de Faturas
icone: list-outline
situacao: funciona
relacionados: faturas, fracao-ideal, parametros, indices
palavras: tipos de faturas, configurações, AP-168
rascunho: nao
---

O que é cobrado das unidades (taxa condominial, fundo de reserva, consumo) e a conta de receita de cada cobrança, com valor padrão e recorrência. Sem condomínio, o tipo vale para todos.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Tipos de Faturas]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela tipos-de-faturas
rota: /configuracoes/tipos-de-faturas
condominio: residencial-jardim-das-acacias
legenda: Tela Tipos de Faturas
1: menu | Tipos de Faturas no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Novo tipo de fatura") | Botão {{Novo tipo de fatura}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Recorrência e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Novo tipo de fatura}}, no alto da lista.
2. Preencha os campos obrigatórios: **Nome**, **Conta de receita**, **Recorrência** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela tipos-de-faturas-novo-tipo-de-fatura
rota: /configuracoes/tipos-de-faturas/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: novo tipo de fatura
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="condominium_id"] | Condomínio
2: main form:not([method=get]) [name="name"] | Nome (obrigatório)
3: main form:not([method=get]) [name="chart_account_id"] | Conta de receita (obrigatório)
4: main form:not([method=get]) [name="recurrence"] | Recorrência (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio**: Vazio: tipo padrão da Connect (todos os condomínios).
- **Nome** (obrigatório): Ex.: Taxa condominial, Fundo de reserva.
- **Conta de receita** (obrigatório): Conta analítica de receita do plano de contas.
- **Recorrência** (obrigatório): Opções: Mensal e Avulsa.
- **Valor padrão**: Opcional.
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Recorrência** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Nome**, **Condomínio**, **Recorrência**, **Valor padrão** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Faturas](artigo:faturas), [Preencher a fração ideal e a área das unidades](artigo:fracao-ideal) e [Parâmetros gerais](artigo:parametros).
