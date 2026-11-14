<!-- markdownlint-disable MD024 -->
# 0284 — Situação das importações

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0060 e seguintes (importações: extrato OFX, lançamentos, contas a pagar, frações, inadimplentes, leituras e NF-e, com
    prévia e aplicação).

## Contexto

As importações passam por uma prévia e, depois, são aplicadas. A lista de importações mostra uma por vez.

A administradora não vê, de uma vez:

- quantas prévias ficaram paradas sem aplicar (o arquivo foi conferido e esquecido), e há quanto tempo;
- quantas linhas tiveram erro, e em quais importações;
- quando cada tipo foi aplicado pela última vez.

Busquei `erp_imports` em `source/`, as rotas `/reports/*` e o README das ADRs 0200 a 0283. Nenhum relatório resume as
importações. O patrimônio (`erp_assets`) também não tem relatório, mas é a página AP-019, que não toco.

## Decisão

Rota nova: `GET /api/v1/reports/imports?condominium_id=`, logo depois de `POST /users/revoke-condominium-access`. O
service é `ImportsOverview`, em `Source\Services\Erp`.

### Reuso

- **Tipos e rótulos:** `Imports::KINDS`. Um tipo novo de importação entra no relatório sem mudança aqui.
- **Situações:** prévia e aplicada, a enum `status` de `erp_imports`, como a `Imports::apply` usa. O teste unit confere
  que `STATUSES` bate com a enum da migration.
- **Relógio:** o do banco (`SELECT NOW()`), porque `created_at` foi gravado por ele.

### Regra pura `summarize()`

- **`rows`:** uma linha por tipo de `Imports::KINDS`, sempre todos, com `imports`, `previewed` e `applied` (que somam
  `imports`), `rows` (linhas lidas), `errors` (linhas com erro), `with_errors` (importações com algum erro) e
  `last_applied_on`. `totals` soma as linhas; `last_applied_on` é a mais recente. `unknown` conta os de tipo ou situação
  fora da lista.
- **`pending_by_age`:** as prévias ainda não aplicadas por dias desde o envio, nas faixas de `AGES` (até 7, de 8 a 30,
  mais de 30). A soma é `totals.previewed`.
- **Ids:** `pending_ids` (da prévia mais antiga para a mais nova) e `with_errors_ids`, até 200 cada, com `truncated`.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.imports.view`, as mesmas da lista de importações. Sem uma delas, 403
  (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não aplica, não audita e não envia nada.
- **Dados que saem:** tipos, contagens, datas e ids. Não saem nome de arquivo, caminho, hash, conteúdo do resumo
  (`summary_json`) nem quem enviou ou aplicou.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\ImportsOverviewTest`:**
  - `STATUSES` bate com a enum da migration;
  - as faixas de idade vindas de `AGES`, nos limites;
  - prévias e aplicadas por tipo, linhas, erros e última aplicação, com totais iguais à soma das linhas;
  - outro condomínio e tipo desconhecido de fora;
  - os ids em ordem e o corte em 200;
  - nenhum nome de arquivo, caminho, hash nem resumo;
  - tipos vindos de `Imports::KINDS`, sem quantidade nem lista fixa;
  - a validação, o escopo, o contrato, o controller, a rota e a OpenAPI.
- **`Integration\ImportsOverviewTest`** (MariaDB, banco `*_test`):
  - importações em `erp_imports`, envelhecidas pelo relógio do banco;
  - somente leitura, sem nome de arquivo, caminho nem hash;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
