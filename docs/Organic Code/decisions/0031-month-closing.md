# 0031 — Fechamento mensal (AP-030): resumo da competência e trava dos lançamentos

Data: 2026-11-09 · Status: aceito (ciclo autônomo da frente financeira, por delegação do Djalma; aguarda integração no container)

## Contexto

Com lançamentos (ADR 0022), visões de contas a pagar e a receber (0024) e lançamentos fixos (0027),
falta garantir que o mês prestado em contas não mude depois. A página AP-030 "Fechamento Mensal"
existia sem dados. A ficha segue PENDENTE, e as regras são PROPOSTAS.

## Decisão

- **Tabela `erp_month_closings`** (migration `20261108_0511`): uma linha por condomínio e
  competência (`UNIQUE`), situação `closed` ou `reopened`, foto dos totais no fechamento
  (receitas, despesas, quanto estava em aberto, resumo por conta e por conta bancária em JSON),
  quem fechou e quando, quem reabriu, quando e por quê. Permissões `erp.closing.view` e
  `erp.closing.manage` (migration `0512`).
- **Serviço `Source\Services\Erp\MonthClosing`**:
  - `status`: resumo calculado agora (lançamentos válidos da competência) e a situação
    (`open`, `closed`, `reopened`);
  - `close`: grava a foto e fecha (recusa se já está fechada; a linha é travada com `FOR UPDATE`);
  - `reopen`: exige motivo (5 a 255 caracteres) e só reabre o que está fechado;
  - escopo por condomínio (`CondominiumScope::assertAccess`). Fechar e reabrir são auditados.
- **Trava no motor** (`closing_lock` => `competence`, em `entries`, `payables` e `receivables`):
  - não é possível criar, alterar ou excluir um lançamento de uma competência fechada, nem mover
    um lançamento para dentro ou para fora dela. Na gravação, o erro aparece no campo
    competência; na exclusão, é `RegistryLocked` (409 `closed_competence` na API, aviso na tela);
  - a geração dos lançamentos fixos (ADR 0027) numa competência fechada cai em `failed`.
- **API** (`docs/api/openapi.yaml`): `GET /api/v1/closings?condominium_id=&competence=`,
  `POST /api/v1/closings` e `POST /api/v1/closings/reopen`. Fora do escopo responde 404, conflito
  de situação responde 409, dados inválidos 422.

## Consequências

- A foto do fechamento não muda depois. O resumo atual (`summary` em `status`) pode divergir dela
  se a competência for reaberta e alterada; um novo fechamento grava a foto nova.
- A tela de fechamento (botões e resumo) é da frente de layout. A API já existe.
- Testes: `tests/legacy/Unit/MonthClosingTest.php` e
  `tests/legacy/Integration/MonthClosingIntegrationTest.php`.
