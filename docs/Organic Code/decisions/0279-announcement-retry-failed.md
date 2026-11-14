<!-- markdownlint-disable MD024 -->
# 0279 — Envios com falha dos comunicados de volta à fila, em lote

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0054 e 0055 (outbox e fila de avisos, com o reenvio de um evento);
  - 0059 (comunicação pela outbox);
  - 0278 (situação dos comunicados, que aponta os comunicados com falha em `failed_ids`).

## Contexto

A situação dos comunicados (ADR 0278) mostra os comunicados com envio com falha na outbox. Um comunicado para todas as
unidades tem um evento por unidade. Reenviar hoje é abrir cada evento da fila de avisos (ADR 0055) e pedir o reenvio, um a
um.

Busquei `retry`, `failed` e `erp_notification_outbox` em `source/`. O `NotificationDispatcher::retry` já volta um evento
com falha ou cancelado para a fila, com a auditoria dele. Nenhuma rota faz isso por comunicado ou em lote.

## Decisão

Rota nova: `POST /api/v1/announcements/retry-failed`, logo depois de `GET /reports/notice-board` e antes das rotas
`/announcements/{id}`. O service é `AnnouncementRetry`, em `Source\Services\Erp`.

### Reuso

- **Reenvio:** cada evento com falha volta à fila pelo `NotificationDispatcher::retry`, o mesmo da tela da fila: `status`
  `pending`, `attempts` zerado, `available_at` agora e `last_error` vazio, com a auditoria do evento.
- **Situação do comunicado:** `AnnouncementBoard::state`, a regra de publicado, agendado e cancelado do relatório (ADR
  0278). A regra saiu do `summarize()` para esse método público, sem mudar o relatório.
- **Nada é enviado aqui:** o envio continua com o worker da fila e com as travas de ambiente de sempre (fora de produção,
  só log ou caixa de teste). O lote não cria e-mail nem entrega.

### Lote

- **Sem `announcement_ids`:** entram os comunicados publicados (ativos e já saídos) do condomínio com algum evento com
  falha.
- **Com `announcement_ids`:** entram só os informados. A regra pura `problem()` recusa:
  - comunicado não encontrado no condomínio;
  - cancelado;
  - agendado (os envios saem na data marcada);
  - sem envio com falha.
- **Tudo ou nada:** uma transação com os comunicados e os eventos com falha do condomínio travados (`FOR UPDATE`). Um
  recusado desfaz todos (422, com `_form` e `_announcement`).
- **Prévia:** `dry_run` passa por tudo e desfaz, inclusive a auditoria do reenvio de cada evento, que vai na mesma
  transação.
- **Motivo:** obrigatório, de 5 a 255 caracteres. Até 500 comunicados por vez.
- **Auditoria:** a do `NotificationDispatcher::retry` por evento e um registro `retry_failed` por comunicado (eventos,
  motivo e tamanho do lote), só quando grava.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.announcements.view` e `erp.announcements.manage`, as mesmas do cancelamento de
  comunicado, e também `erp.notifications.manage`, a do reenvio na tela da fila de avisos (o lote usa o mesmo reenvio e não
  pode abrir um caminho com menos permissão). Sem uma delas, 403 (fail-closed). CSRF pelo `ApiAuthenticated`.
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida, 422. Um evento que muda de situação durante o pedido, 409.
- **Dados que saem:** ids, tipo e contagens. Totais `count` e `events` iguais à soma das linhas. Não saem assunto, texto,
  unidade, destinatário, e-mail ou erro do envio.
- **Não toca** o portal do morador, o e-mail, o app, a sessão por token, o login nem o 2FA.
- **Sem migration e sem tabela nova.**

## Consequências

- **`Unit\AnnouncementRetryTest`:**
  - a regra pura;
  - a validação e o escopo;
  - as recusas, uma por caso, com tudo desfeito e sem assunto, texto ou erro na mensagem;
  - a prévia e a volta à fila pelo `NotificationDispatcher::retry`, com o agendado, o cancelado, outro condomínio e outra
    origem da outbox de fora;
  - nenhum e-mail nem entrega criados (o envio de teste lança erro se for chamado);
  - tipos vindos de `AnnouncementBoard::KINDS`, sem quantidade nem lista fixa;
  - o contrato, o controller, a rota e a OpenAPI.
- **`Integration\AnnouncementRetryTest`** (MariaDB, banco `*_test`):
  - eventos da outbox e um cancelamento de verdade (`Announcements::cancel`);
  - a prévia, as recusas com tudo desfeito e o reenvio gravado, com a auditoria do evento e a do comunicado;
  - a `mail_queue` e as entregas sem nenhuma linha nova;
  - rodar de novo não muda nada;
  - o escopo.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
