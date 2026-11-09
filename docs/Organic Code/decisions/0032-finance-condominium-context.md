# 0032 — Financeiro no contexto do condomínio da barra superior

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O ADR 0030 tornou o condomínio da barra superior o contexto global do ERP, com slug na URL. As
telas do financeiro feitas pelo motor de cadastros (lançamentos, lançamentos fixos, contas a pagar e
a receber, contas bancárias, centros de custo, plano de contas e tipos de faturas) já filtravam por
esse contexto, mas com dois problemas:

- nos cadastros de escopo `optional`, o contexto escondia o padrão da Connect (linhas sem
  condomínio): com o Alpha escolhido, o plano de contas mostrava só as contas próprias do Alpha,
  embora ele use as do padrão;
- o fechamento mensal (AP-030, ADR 0031) só existia na API, sem tela, e a API exige
  `condominium_id`.

## Decisão

- **Listas do ERP com o padrão junto.** A tela de cadastro passa `include_standard` ao
  `RegistryService::list()`. Com um condomínio em contexto, os cadastros de escopo `optional`
  mostram as linhas desse condomínio e as do padrão da Connect. Os cadastros de escopo `required`
  não mudam, e linhas de outros condomínios continuam fora. A API não muda: `condominium_id`
  continua filtrando só as linhas do condomínio, e o dashboard (`count()`) segue o ADR 0030.
- **Fechamento mensal na tela AP-030** (`/financeiro/fechamento-mensal`), sempre pelo contexto:
  - `MonthClosing::overview(user, ?condominio, competência)`: com um condomínio, só ele; em
    "Todos", cada condomínio do escopo do usuário, mesmo os que não têm lançamentos. Para cada um,
    traz a situação (aberta, fechada ou reaberta), receitas, despesas, quanto está em aberto e o
    resultado, mais os totais. Reaberta conta como aberta, porque aceita lançamentos. Condomínio
    fora do escopo lança `CondominiumAccessDenied`.
  - `Panel::pageView()` entrega à página `closing`, `closingCompetence` (de `?competencia=AAAA-MM`;
    inválida ou ausente = mês atual) e `canManageClosing`. `closing` é `null` sem a permissão
    `erp.closing.view` ou sem banco; nesse caso a tela continua com a demonstração.
  - `POST /financeiro/fechamento-mensal/fechar` e `/reabrir` (motivo em `reason`):
    - pedem CSRF e a permissão `erp.closing.manage`, e seguem o padrão PRG, com aviso na volta;
    - o condomínio vem do contexto, nunca do formulário;
    - em "Todos", a ação é recusada com o aviso "Escolha um condomínio na barra superior";
    - o serviço confere o escopo de novo.
- **Saldos e geração dos lançamentos fixos** já aceitam `null` = todo o escopo
  (`BankBalances::forUser`, `RecurringEntries::generate`). As telas mandam o condomínio do contexto
  à API (`data-condominium`), e "Todos" manda vazio. A API continua sem ler o contexto (ADR 0030).

## Consequências

- A frente de layout monta a tela AP-030 sobre `$closing`, `$closingCompetence` e
  `$canManageClosing`, com os formulários de fechar e reabrir apontando para as rotas acima.
- Um cadastro novo de escopo `optional` ganha o comportamento sem configuração.
- Sem migration nova e sem permissão nova.
