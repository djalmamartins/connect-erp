# 0055 — Fila de envio dos avisos do ERP pelo container send

Data: 2026-11-08 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O ADR 0054 criou a outbox (`erp_notification_outbox`), e a chamada da lista de espera já grava
eventos pendentes nela. Faltava consumir esses eventos com os modelos de `container/send` (ADR 0008),
com retentativas e trilha de auditoria.

A coordenação fixou duas regras de segurança:
- fora de produção, o envio vai só para log ou para uma caixa de teste; nenhum e-mail real sai para
  morador, e o envio real exige configuração explícita de produção;
- credenciais SMTP vêm do ambiente.

O sistema já tem `mail_queue` e `Email::sendQueue()`, com tentativas, usados pelos avisos do
checklist e do 2FA.

## Decisão

- **`NotificationDispatcher`** consome a outbox. `tools/notifications-dispatch.php` roda pelo cron
  (só CLI), e `POST /api/v1/notifications/dispatch` processa sob demanda.
- **Modo de entrega** (`deliveryMode`, regra pura, coberta por teste):
  - `log` é o padrão: só registra no log, sem endereço; nada sai;
  - `sandbox` (`ERP_MAIL_DELIVERY=sandbox` e `ERP_MAIL_SANDBOX_TO` válido): enfileira na
    `mail_queue` sempre para a caixa de teste, com "[TESTE]" no assunto. Sem a caixa, cai para log;
  - `production` só com `APP_ENV=production` **e** `ERP_MAIL_DELIVERY=production`. Pedir production
    fora de produção vira sandbox (ou log).
  - Assim, **fora de produção nenhum e-mail vai para morador**, qualquer que seja a configuração.
- **Por evento:**
  - modelo de `container/send` (`waitlist-called`, com escape de todas as variáveis);
  - destinatários conforme o tipo: para `waitlist.called`, até 5 pessoas ativas da unidade com
    e-mail válido, proprietário primeiro;
  - sem destinatário, o evento é cancelado com motivo.
- **Trilha (`erp_notification_deliveries`, migration 0533):** uma linha por destinatário, com o
  e-mail em hash e mascarado (nunca em claro), o endereço efetivo no sandbox e o id da `mail_queue`.
  Uma retentativa não repete quem já recebeu.
- **Retentativas:** cada processamento conta uma tentativa. Em falha, o evento volta a pendente
  depois de 1, 5, 15, 60 e 240 minutos; na 5ª tentativa vira `failed`.
  `POST /api/v1/notifications/{id}/retry` reenvia por ação explícita, auditada. Tudo auditado.
- **Consulta:** `GET /api/v1/notifications` (por condomínio do escopo e situação) e
  `GET /api/v1/notifications/{id}` (com a trilha). Permissões `erp.notifications.view` e
  `erp.notifications.manage`.

## Pendência de segurança

- O `Email` e a `mail_queue` existentes leem as credenciais SMTP da tabela `settings`
  (`Boot/Settings.php`), não do ambiente. Esta fila não envia SMTP direto: no sandbox e na produção,
  ela só grava na `mail_queue`, e o envio fica no `Email::sendQueue`.
- Antes de ligar o modo production, o `Email` precisa ler as credenciais do ambiente (por exemplo
  `MOVESOS_SMTP_HOST`, `_USER`, `_PASS`), sobrepondo o `settings`. Registrado para decisão; não
  foi alterado aqui porque é núcleo compartilhado.

## Consequências

- Novos avisos entram em `NotificationOutbox::EVENTS`, com um modelo em `container/send` e a regra de
  destinatários no `NotificationDispatcher`.
- A frente de automação agenda o cron de `tools/notifications-dispatch.php` e do envio da `mail_queue`
  no lab, com `ERP_MAIL_DELIVERY=sandbox` apontando para a caixa de teste do container send.
