<!-- markdownlint-disable MD024 -->
# 0272 — Carteira de lançamentos fixos

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0027 (geração dos lançamentos fixos do mês);
  - 0186 (previsão de caixa, que usa os vigentes para projetar o saldo);
  - 0204 (reajuste) e 0271 (encerramento em lote dos que já acabaram);
  - cadastro de lançamentos fixos (AP-021), só como cadastro reusado.

## Contexto

O cadastro de lançamentos fixos (AP-021) lista modelo por modelo. A previsão de caixa (ADR 0186) usa os vigentes para
projetar o saldo. A geração (0027), o reajuste (0204) e o encerramento em lote (0271) agem sobre os modelos.

A administradora não vê, de uma vez:

- quantos modelos estão vigentes e quanto somam por mês de receita e de despesa;
- quantos terminam nos próximos meses (hora de renovar ou reajustar);
- quantos já acabaram e seguem ativos, e quantos começam depois;
- quantos vigentes ainda não geraram o lançamento da competência;
- quantos têm conta, centro de custo, conta bancária ou fornecedor inativo (a geração vai falhar).

Busquei `erp_recurring_entries` em `source/`, no README das ADRs e nos handoffs 02xx. Nenhum relatório resume a carteira; a
previsão de caixa mostra só o efeito no saldo.

## Decisão

Rota nova: `GET /api/v1/reports/recurring-portfolio?condominium_id=&competence=`, logo depois de
`POST /recurring-entries/close-ended` e antes do laço dos cadastros. O service é `RecurringPortfolio`, em
`Source\Services\Erp`.

### Reuso

- **Vigência:** a da geração (`RecurringEntries::appliesTo` e `isCompetence`).
- **Já gerado:** o vínculo do lançamento gerado (`recurring_entry_id` e `competence`), a mesma barreira da geração.
- **Vínculos:** as situações dos cadastros (só os ativos, sem os excluídos).

### Regra pura `classify()` e `summarize()`

Cada modelo (sem os excluídos) cai numa situação, na competência `c` (padrão o mês atual):

- `inactive`: situação Inativo;
- `future`: ativo, começa depois de `c`;
- `ended_active`: ativo, a última competência é anterior a `c` (o encerramento em lote pegaria);
- `ending_soon`: ativo e vigente, com a última competência até `c` + 2 meses (`soon_until`);
- `running`: ativo e vigente, sem fim ou com o fim depois disso.

Uma linha por situação, sempre as cinco, com `templates`, `receita_cents` e `despesa_cents` (o valor mensal). `totals` soma
as linhas.

- **`generation`:** entre os vigentes (`running` + `ending_soon`), os que já geraram o lançamento de `c` e os que faltam,
  somando `in_force`.
- **`attention`:** `inactive_link` (ativos com vínculo inativo) e `without_end` (vigentes sem última competência).

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.recurring_entries.view`, a mesma do cadastro. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** só situações, contagens e somas. Não saem descrição, fornecedor, conta ou observação.
- **Não toca** o portal do morador, o app, a sessão por token, o login nem o 2FA.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\RecurringPortfolioTest`:**
  - as cinco situações com as bordas (começa no mês, termina no mês, borda da janela);
  - a geração da competência e a atenção (vínculo inativo só nos ativos);
  - totais iguais à soma das linhas;
  - excluídos e de outro condomínio de fora;
  - lançamento de outra competência ou excluído não conta como gerado;
  - nenhuma descrição na resposta;
  - a validação, o escopo, o contrato, o controller e a rota.
- **`Integration\RecurringPortfolioTest`** (MariaDB, banco `*_test`):
  - as situações e o total com o cadastro;
  - a geração do mês pela própria geração (0027);
  - somente leitura e sem descrição;
  - os já acabados iguais à prévia do encerramento em lote (0271);
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda. A tela dos lançamentos fixos pode ganhar o quadro
  da carteira com os botões de gerar, reajustar e encerrar; quando ganhar, vem o artigo.
