<!-- markdownlint-disable MD024 -->
# 0288 — Situação das cobranças extras e multas avulsas

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0057 e 0080 (atalhos de lançamento: cobrança extra e multa avulsa, com uma conta a receber por unidade);
  - 0039 (acordos: a conta original fica cancelada e ligada ao acordo).

## Contexto

Uma cobrança extra ou uma multa avulsa gera uma conta a receber por unidade, ligada pela `extra_charge_id`. A lista de
cobranças mostra uma por vez, com as contas dela.

A administradora não vê, de uma vez, por tipo:

- quanto foi lançado e quanto já entrou;
- quanto está em aberto e quanto já venceu, e desde quando;
- quanto virou acordo;
- quais cobranças têm conta vencida.

Busquei `erp_extra_charges` em `source/`, as rotas `/reports/*` e o README das ADRs 0200 a 0287. Nenhum relatório resume
as cobranças extras e as multas.

## Decisão

Rota nova: `GET /api/v1/reports/extra-charges?condominium_id=`, logo depois de `POST /banners/deactivate-ended`. O service
é `ExtraChargeOverview`, em `Source\Services\Erp`.

### Reuso

- **Tipos e rótulos:** `EntryShortcuts::CHARGE_KINDS`. O teste unit confere que batem com a enum `kind` da migration.
- **Ligação e situação das contas:** `erp_entries.extra_charge_id`, a baixa (`paid_on`) e o acordo (`agreement_id`, com a
  conta original cancelada), como as contas a receber já usam.
- **Permissão:** a mesma da lista de cobranças extras (`EntryShortcuts::INVOICES`, `erp.invoices.view`).
- **Relógio:** o dia do banco (`SELECT NOW()`), para comparar com os vencimentos gravados.

### Regra pura

- **`state()`** de uma conta da cobrança:
  - recebida: ativa e baixada;
  - vencida: ativa, em aberto e vencida antes de hoje;
  - em aberto: ativa, em aberto e vencendo hoje ou depois;
  - em acordo: cancelada e ligada a um acordo;
  - de fora: cancelada sem acordo.
- **`summarize()`:**
  - `rows`: uma linha por tipo, sempre todos, com as cobranças ativas (`charges`), as canceladas (`cancelled`) e, só das
    contas das cobranças ativas, a quantidade e o valor por situação de `STATES`, que somam `entries` e `amount_cents`, e
    o vencimento mais antigo em atraso;
  - `totals` soma as linhas;
  - `overdue_ids`: cobranças com conta vencida, da que venceu antes, até 200, com `truncated`.
- **Ficam de fora:** conta apagada, conta de outro condomínio e conta cuja cobrança é de outro condomínio.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.invoices.view`. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** tipos, contagens, valores em centavos, datas e ids. Não saem descrição, motivo, unidade nem quem
  lançou ou cancelou.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\ExtraChargeOverviewTest`:**
  - as situações nos limites do dia;
  - contagens e valores por tipo, com totais iguais à soma das linhas e situações somando contas e valores;
  - a cobrança cancelada contada à parte e sem valores;
  - conta cancelada sem acordo, apagada, sem cobrança e de outro condomínio de fora;
  - os ids das vencidas em ordem e o corte em 200;
  - nenhuma descrição, motivo nem unidade;
  - tipos vindos de `EntryShortcuts::CHARGE_KINDS`, conferidos com a enum, sem quantidade nem lista fixa;
  - a validação, o escopo, o contrato, o controller, a rota e a OpenAPI.
- **`Integration\ExtraChargeOverviewTest`** (MariaDB, banco `*_test`):
  - cobranças em `erp_extra_charges` e contas em `erp_entries`, com os vencimentos pelo dia do banco;
  - somente leitura, sem descrição nem motivo;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
