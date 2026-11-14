<!-- markdownlint-disable MD024 -->
# 0281 — Avisos pendentes parados cancelados em lote

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0054 e 0055 (outbox e fila de avisos, com o reenvio de um evento);
  - 0059 (cancelamento de comunicado, que cancela os eventos pendentes dele);
  - 0280 (saúde da fila, que aponta os pendentes mais antigos em `oldest_pending_ids`).

## Contexto

A saúde da fila (ADR 0280) mostra os avisos que esperam há mais tempo. Um aviso pendente há semanas sai de repente
quando a fila volta, com informação velha: o envio parou, o modelo mudou ou o condomínio trocou de rotina.

Busquei `cancelled` e `erp_notification_outbox` em `source/`. A fila não tem ação de cancelar. O único cancelamento é o
do comunicado (`Announcements::cancel`), que cancela os eventos pendentes dele. O `NotificationDispatcher::retry` volta
um cancelado à fila.

## Decisão

Rota nova: `POST /api/v1/notifications/cancel-stale`, logo depois de `GET /reports/notification-queue` e antes das rotas
`/notifications/{id}`. O service é `NotificationStaleCancellation`, em `Source\Services\Erp`.

### Reuso

- **Cancelamento:** o mesmo que o `Announcements::cancel` faz na fila. O `status` vira `cancelled`, o `last_error` recebe
  a mensagem do sistema e o `processed_at` fica com a hora atual, só para quem ainda está `pending`. Não há método da
  fila para isso, então o `UPDATE` fica no service novo.
- **Reversível:** o `NotificationDispatcher::retry` volta um cancelado à fila, se for preciso.
- **Nada é enviado nem apagado:** o conteúdo do evento fica como está.

### Lote

- **Sem `event_ids`:** entram os avisos pendentes do condomínio parados há mais de `days` dias (padrão 7, de 1 a 365).
  Parado é quem tem o mais recente entre a criação e o `available_at` até o limite: um aviso agendado para depois não está
  parado. Só o `pending` entra; o `processing` pode estar com o worker naquele instante.
- **Com `event_ids`:** entram só os informados. A regra pura `problem()` recusa:
  - aviso não encontrado no condomínio;
  - não pendente;
  - mais novo que o limite.
- **Relógio:** o do banco (`SELECT NOW()`), porque `created_at` e `available_at` foram gravados por ele.
- **Tudo ou nada:** uma transação com os avisos do condomínio travados (`FOR UPDATE`). Um recusado desfaz todos (422,
  com `_form` e `_event`). Um aviso que muda de situação no meio, 409.
- **Prévia:** `dry_run` passa por tudo e desfaz.
- **Motivo:** obrigatório, de 5 a 255 caracteres. Até 500 avisos por vez.
- **Auditoria:** um registro `cancel_stale` por aviso (situação anterior, dias, motivo e tamanho do lote), só quando
  grava.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.notifications.view` e `erp.notifications.manage`, as mesmas do reenvio na tela
  da fila. Sem uma delas, 403 (fail-closed). CSRF pelo `ApiAuthenticated`.
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422.
- **Dados que saem:** ids, tipo, contagem por tipo, dias e o limite. `count` é a soma por tipo. Não saem destinatário,
  e-mail, conteúdo do evento nem mensagem de erro.
- **Não toca** o portal do morador, o e-mail, o app, a sessão por token, o login nem o 2FA.
- **Sem migration e sem tabela nova.**

## Consequências

- **`Unit\NotificationStaleCancellationTest`:**
  - a regra pura: o limite no passado, o sinal certo dos dias, o parado pelo mais recente entre criação e `available_at`;
  - as recusas;
  - a validação e o escopo;
  - as recusas, uma por caso, com tudo desfeito e sem conteúdo nem erro na mensagem;
  - a prévia, o limite em dias e o cancelamento só dos parados deste condomínio, com o conteúdo do evento intacto;
  - nenhum e-mail nem entrega;
  - tipos vindos de `NotificationOutbox::EVENTS`, sem quantidade nem lista fixa;
  - o contrato, o controller, a rota e a OpenAPI.
- **`Integration\NotificationStaleCancellationTest`** (MariaDB, banco `*_test`):
  - eventos gravados pelo `NotificationOutbox::record` e envelhecidos pelo relógio do banco;
  - a prévia, as recusas com tudo desfeito e o cancelamento gravado, com auditoria;
  - a `mail_queue` e as entregas sem nenhuma linha nova;
  - rodar de novo não muda nada, e o reenvio da fila volta o cancelado;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
