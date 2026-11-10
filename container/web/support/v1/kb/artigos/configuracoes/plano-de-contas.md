---
codigo: AP-160
titulo: Plano de contas
resumo: As contas de receita e despesa usadas em todos os lançamentos e relatórios. Sem condomínio, a conta faz parte do plano padrão da Connect e vale para todos; com condomínio, é uma conta só dele.
categoria: configuracoes
rota: /configuracoes/plano-de-contas
menu: Configurações > Financeiro e parâmetros > Plano de Contas
icone: list-outline
situacao: funciona
relacionados: configuracoes-demonstrativo, saldo-inicial-plano-de-contas, financeiro, saldo-inicial-centro-de-custo
palavras: plano de contas, configurações, AP-160
rascunho: nao
---

As contas de receita e despesa usadas em todos os lançamentos e relatórios. Sem condomínio, a conta faz parte do plano padrão da Connect e vale para todos; com condomínio, é uma conta só dele.

## Onde fica

No menu lateral, abra [[Configurações > Financeiro e parâmetros > Plano de Contas]]. Confira o condomínio na barra superior: a tela mostra só os dados dele.

```tela plano-de-contas
rota: /configuracoes/plano-de-contas
condominio: residencial-jardim-das-acacias
legenda: Tela Plano de Contas
1: menu | Plano de Contas no menu lateral
2: main .erp-page-head__actions :is(a,button):has-text("Nova conta") | Botão {{Nova conta}}
3: main :is(form[method=get], .erp-filters) | Filtros: Pesquisar, Condomínio, Natureza e Situação
4: main table thead | Colunas da lista
```

## Como cadastrar

1. Clique em {{Nova conta}}, no alto da lista.
2. Preencha os campos obrigatórios: **Código**, **Natureza**, **Nome da conta**, **Tipo** e **Situação**. Os outros podem ficar para depois.
3. Clique em {{Salvar}}.
4. O registro aparece na lista. Se faltar algo, o campo fica marcado em vermelho com a explicação.

```tela plano-de-contas-nova-conta
rota: /configuracoes/plano-de-contas/novo
condominio: residencial-jardim-das-acacias
legenda: Formulário: nova conta
recorte: main form:not([method=get])
1: main form:not([method=get]) [name="code"] | Código (obrigatório)
2: main form:not([method=get]) [name="nature"] | Natureza (obrigatório)
3: main form:not([method=get]) [name="name"] | Nome da conta (obrigatório)
4: main form:not([method=get]) [name="kind"] | Tipo (obrigatório)
5: main form:not([method=get]) [name="status"] | Situação (obrigatório)
6: main form:not([method=get]) button:has-text("Salvar") | Botão {{Salvar}}
```

### O que preencher

- **Condomínio**: Vazio: plano padrão da Connect (todos os condomínios).
- **Código** (obrigatório): Ex.: 2.01.003.
- **Natureza** (obrigatório): Opções: Receita, Despesa, Ativo, Passivo e Patrimônio líquido.
- **Nome da conta** (obrigatório).
- **Tipo** (obrigatório): Opções: Sintética (agrupa) e Analítica (recebe lançamentos).
- **Observações**.
- **Situação** (obrigatório): Opções: Ativo e Inativo.

## Pesquisar e filtrar

Use os filtros no alto da lista: **Pesquisar**, **Condomínio**, **Natureza** e **Situação**.
Depois clique em {{Filtrar}}. Para voltar à lista completa, limpe os filtros.

## O que a lista mostra

Colunas: **Nome da conta**, **Condomínio**, **Código**, **Natureza**, **Tipo** e **Situação**.

## Ações de cada linha

- Clique no nome (primeira coluna) para abrir o registro.
- **Editar** abre o cadastro para alterar. Salve para gravar a mudança, que fica no histórico.

> O plano padrão da Connect já vem com as contas mais comuns: **1 Receitas** (taxa condominial, fundo de reserva, multas e juros, rateios, rendimentos, locação de áreas comuns) e **2 Despesas** (pessoal, água, energia, **2.04 Manutenção e conservação** com elétrica, hidráulica, elevadores, jardinagem e pintura, limpeza, seguros, administração, tarifas bancárias e impostos). Ajuste os nomes ou crie contas do condomínio quando precisar.

> As contas 7 (Ativo), 8 (Passivo) e 9 (Patrimônio Líquido) são do Balanço Patrimonial e não aparecem nos lançamentos de receita e despesa.

> Contas sintéticas agrupam as analíticas e só somam nos relatórios; os lançamentos vão nas analíticas.

>! Conta com lançamentos não deve ser excluída: inative para ela sair das listas sem perder o histórico.

## Quem pode usar

A tela aparece para quem tem acesso ao módulo **Configurações**. O administrador libera o acesso no cadastro do usuário, em [[Studio > Usuários]]. Cada pessoa só vê os condomínios vinculados à própria conta, e toda gravação fica registrada no histórico (quem fez, quando e o que mudou).

>> Veja também: [Modelo do demonstrativo](artigo:configuracoes-demonstrativo), [Saldo inicial do plano de contas](artigo:saldo-inicial-plano-de-contas) e [Configurações financeiras](artigo:financeiro).
