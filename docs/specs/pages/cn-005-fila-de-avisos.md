# CN-005 — Fila de avisos

Origem: frente de avisos do ERP (ADR 0054 e 0055). Não existe no inventário APControle; é página própria da Connect.
Rota: `/erp/configuracoes/fila-de-avisos` (grupo Configurações › Aplicativo e notificações). Decisão: ADR 0072.
API: `/api/v1/notifications`.

## Regras implementadas

| Regra | Situação |
|---|---|
| Lista os avisos da outbox do condomínio da barra superior (ou de todos do escopo), os 200 mais recentes | CONFIRMADO (serviço do ADR 0055) |
| Filtro por situação: pendente, processando, enviado, falhou, cancelado | CONFIRMADO |
| Mostra o modo de entrega atual: só registro, caixa de teste ou envio real | CONFIRMADO (ADR 0055) |
| A trilha mostra o destinatário mascarado; o endereço efetivo também aparece mascarado | CONFIRMADO (pedido da coordenação) |
| Reenviar só aviso com falha ou cancelado, com confirmação, CSRF e auditoria | CONFIRMADO |
| Consultar exige `erp.notifications.view`; reenviar exige `erp.notifications.manage` | CONFIRMADO |

## PENDENTE

- Botão "Processar agora" (a API `POST /api/v1/notifications/dispatch` já existe); hoje o processamento é pelo cron.
- Paginação além dos 200 avisos mais recentes.

## Estados

- Sem banco: "Fila indisponível".
- Sem avisos: estado vazio, com e sem filtro.
- Aviso fora do escopo em `?aviso=`: aviso de erro, lista continua.
