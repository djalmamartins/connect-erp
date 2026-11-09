# 0054 — Painéis do condomínio e da unidade, e outbox de notificações

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

O Painel do condomínio (AP-002) e o Painel da unidade (AP-003) ainda são demonstração. Os dados já
existem em serviços separados:
- dashboard operacional (ADR 0053);
- saldos bancários (ADR 0024) e fechamento (ADR 0031);
- inadimplência e encargos (ADR 0039 e 0040);
- documentos da unidade (ADR 0044).

A coordenação também pediu que a chamada da lista de espera (ADR 0053) vire um aviso pendente,
consumido depois pela fila de envio, sem enviar nada agora. Faixa de ADR desta frente: 0054 a 0059.

## Decisão

- **`PanelSummaries`**, somente leitura, calculado a cada pedido, sem tabela:
  - **condomínio (`GET /api/v1/panels/condominium/{id}`):**
    - ocupação: unidades, ocupadas por morador ativo, vagas e taxa;
    - financeiro: saldo das contas bancárias, inadimplência com encargos e faixas, situação do
      fechamento e resultado da competência atual;
    - operacional: os cards e alertas do dashboard para o condomínio.
  - **unidade (`GET /api/v1/panels/unit/{id}`):**
    - dados da unidade e as pessoas ativas;
    - financeiro: vencidas com encargos, a vencer, acordos ativos ou quebrados;
    - os 10 últimos documentos emitidos;
    - operacional: entregas aguardando, reservas futuras, ocorrências abertas, veículos e animais.
  - Exige `erp.access` e `erp.units.view`. Fora do escopo = 404.
- **Outbox de notificações (`erp_notification_outbox`, migration 0532):**
  - o evento é gravado na mesma transação da ação, então some se a ação desfizer;
  - `event_key` único torna o registro idempotente;
  - fica `pending`, com tentativas, próxima tentativa e último erro, para a fila consumir;
  - o payload é limpo: só escalares, sem chaves com nome de segredo, cada texto com até 500
    caracteres;
  - eventos aceitos: `waitlist.called`. A chamada da lista de espera grava o evento com morador,
    unidade, área, data e reserva.
  - **Nada é enviado neste ciclo.**
- O `Panel.php` não foi alterado (há mudanças da frente de layout nele); as telas AP-002 e AP-003
  ficam com a frente de layout, sobre a API.

## Consequências

- O próximo ciclo desta frente é a fila de envio do container `send` dentro do ERP: consome a outbox
  com os modelos de `container/send`, retentativas com espera e trilha de auditoria.
- Novos tipos de aviso entram na lista `NotificationOutbox::EVENTS` e na fila, um de cada vez.
