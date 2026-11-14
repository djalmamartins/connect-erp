<!-- markdownlint-disable MD024 -->
# 0280 — Saúde da fila de avisos

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0054 e 0055 (outbox e fila de avisos, com as entregas por modo);
  - 0072 (tela da fila de avisos, CN-005);
  - 0279 (envios com falha dos comunicados de volta à fila).

## Contexto

A fila de avisos guarda os eventos da outbox (`erp_notification_outbox`) e como cada um foi entregue
(`erp_notification_deliveries`). A tela da fila (CN-005) mostra até 200 avisos, um a um.

A administradora não vê, de uma vez:

- quantos eventos estão em cada situação e de cada tipo;
- há quanto tempo os pendentes esperam;
- quantas tentativas os com falha gastaram;
- de que cadastro vêm os eventos e por qual modo as entregas saíram.

Busquei `erp_notification_outbox` e `erp_notification_deliveries` em `source/` e no README das ADRs. Nenhum relatório
resume a fila.

## Decisão

Rota nova: `GET /api/v1/reports/notification-queue?condominium_id=`, logo depois de `POST /announcements/retry-failed`. O
service é `NotificationQueueHealth`, em `Source\Services\Erp`.

### Reuso

- **Situações, tipos e modos:** os da tela da fila (`NotificationQueue::STATUSES`, `EVENTS` e `MODES`, com os rótulos
  dela) e os tipos registrados pela outbox (`NotificationOutbox::EVENTS`). Um tipo novo na outbox entra no relatório sem
  mudança aqui.
- **Escopo:** os eventos e as entregas contam pelo condomínio do evento.
- **Relógio:** o tempo de espera usa o relógio do banco (`created_at` e `NOW()` no mesmo fuso).

### Regra pura `summarize()`

- **`rows`:** uma linha por tipo de `NotificationOutbox::EVENTS`, sempre todos, com `events` e uma contagem por situação
  de `STATUSES`, que somam `events`. `totals` soma as linhas. `unknown_events` conta os de tipo ou situação fora da lista,
  que ficam fora das linhas.
- **`pending_by_age`:** os pendentes e em processamento por tempo desde a criação, nas faixas de `AGES` (até 1 hora, até 1
  dia, mais de 1 dia). A soma é a de pendentes e em processamento das linhas.
- **`attempts`:** quantos com falha, a média (duas casas) e o máximo de tentativas deles.
- **`failed_ids` e `oldest_pending_ids`:** até 200 cada, com `failed_truncated` e `pending_truncated`. Os com falha vão do
  mais antigo ao mais novo; os pendentes, do que espera há mais tempo.
- **`subjects`:** eventos por cadastro de origem (`subject_table`), em ordem alfabética, somando os eventos das linhas.
- **`deliveries`:** entregas registradas por modo de `MODES`, sempre todos, com o total.

### Acesso e dados

- **Permissão:** exige `erp.access` e `erp.notifications.view`, as mesmas da tela da fila. Sem uma delas, 403
  (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** tipos, situações, modos, cadastros de origem, contagens e ids. Não saem destinatário, e-mail,
  telefone, assunto, texto, conteúdo do evento (`payload_json`) nem mensagem de erro (`last_error`).
- **Não toca** o portal do morador, o e-mail, o app, a sessão por token, o login nem o 2FA.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\NotificationQueueHealthTest`:**
  - as faixas de espera vindas de `AGES`, nos limites, e as situações de espera dentro de `STATUSES`;
  - eventos por tipo e situação, com totais iguais à soma das linhas, e o tipo desconhecido de fora;
  - a espera dos pendentes, as tentativas dos com falha, os ids em ordem e o corte em 200;
  - origens e entregas por modo, com outro condomínio de fora;
  - nenhum destinatário, conteúdo nem erro;
  - a validação, o escopo, o contrato, o controller, a rota e a OpenAPI.
- **`Integration\NotificationQueueHealthTest`** (MariaDB, banco `*_test`):
  - eventos gravados pelo `NotificationOutbox::record` e uma entrega;
  - somente leitura, sem destinatário, conteúdo nem erro;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda. A tela da fila pode ganhar o quadro; quando
  ganhar, vem o artigo.
