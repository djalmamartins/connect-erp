# 0072 — Tela da fila de avisos, com trilha mascarada e reenvio manual

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O ADR 0055 criou a fila de envio dos avisos do ERP: outbox, trilha de entrega e retentativas, com a
API `/api/v1/notifications`. Para administrar a fila, a coordenação pediu uma tela que:
- liste os avisos com a situação;
- mostre os destinatários sem expor e-mails;
- reenvie, por ação manual, os avisos que falharam.

## Decisão

- **Página própria da Connect: CN-005 "Fila de avisos"**, em Configurações › Aplicativo e
  notificações, na rota `/configuracoes/fila-de-avisos`. Ela entra no catálogo por
  `tools/erp-catalog.py`, e a ficha está em `docs/specs/pages/cn-005-fila-de-avisos.md`.
- **Controller `NotificationQueue`.** Os dados vêm do `NotificationDispatcher`, o mesmo serviço da
  API, e são lidos no servidor.
  - A lista mostra os 200 avisos mais recentes do condomínio da barra superior. Dá para filtrar
    por situação: pendente, processando, enviado, falhou ou cancelado.
  - Cada linha traz o tipo de aviso, o condomínio, as tentativas (de 5), o último erro e a próxima
    tentativa.
  - Um aviso mostra o modo de entrega atual: só registro, caixa de teste ou envio real. Assim fica
    claro se algum e-mail sai.
- **Trilha (`?aviso={id}`).** Mostra a situação, o modo, os destinatários e cada entrega.
  - O destinatário já vem mascarado do banco.
  - O endereço efetivo (a caixa de teste ou, em produção, o do morador) é mascarado na tela por
    `NotificationQueue::maskDetail`. Nenhum endereço aparece em claro.
- **Reenviar** (`POST /configuracoes/fila-de-avisos/{id}/reenviar`).
  - Só aparece para aviso com falha ou cancelado e pede confirmação.
  - Exige CSRF e `erp.notifications.manage`.
  - Usa o `NotificationDispatcher::retry`, que é auditado.
  - O aviso volta para a fila com as tentativas zeradas e sai no próximo processamento.
- **Permissões.** Para consultar, `erp.notifications.view`, que também esconde o item do menu sem
  a permissão. Para reenviar, `erp.notifications.manage`.

## Consequências

- O processamento continua pelo cron (ADR 0055). Um botão "Processar agora" pode usar a API
  `POST /api/v1/notifications/dispatch` quando for pedido.
- Não foi testado com MariaDB real. Os testes cobrem a renderização, a máscara e as rotas.
