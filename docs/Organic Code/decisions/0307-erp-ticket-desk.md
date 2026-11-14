# 0307 — Atender o chamado no ERP como no Help Desk

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:** ADR 0034 (todo chamado no Help Desk), 0142 (conversa no ERP), 0305 (ocorrência é chamado)
- **Numeração:** faixa avulsa 0300–0319.

## Contexto

Pedido do Djalma (14/11/2026): a tela de Chamados do ERP deve ser igual à do Help Desk de atendimento, com a
contagem de tempo e todas as medições, nas cores do ERP. Até aqui o ERP só abria chamados, listava e trocava
mensagens como solicitante. A triagem, o cronômetro, as notas internas e o lote existiam só no `Studio::tickets`,
escritos direto no controlador.

## Decisão

- **Serviço único `Support\TicketDesk`**: triagem (`update`), resposta ou nota interna com o tempo dedicado, anexos e
  "resolver após enviar" (`reply`), situação em lote (`bulkStatus`), números da fila (`metrics`), medições do chamado
  (`facts`), histórico (`events`), respostas rápidas (`templates`) e responsáveis (`agents`). O Help Desk (Studio) passa
  a usar o serviço para alterar, mudar em lote e responder, sem mudar a tela dele. Cada mudança grava o evento, avisa
  quem pediu (ADR 0142) e leva a situação à ocorrência (ADR 0305).
- **ERP, Operacional › Chamados**: contadores por situação, SLA vencido, vence hoje e sem responsável. Filtros de
  prioridade, responsável, prazo, origem e tipo. Colunas de atualizado, responsável e SLA. Na tela do chamado,
  quem tem `support.manage` vê o cronômetro (pausar e zerar), responder ou nota interna (que fica visível só para
  a equipe), respostas rápidas, anexos, a triagem, resolver ou reabrir, as medições (primeira resposta, prazo SLA,
  tempo dedicado, respostas) e o histórico. Quem não atende continua só respondendo como solicitante.
- **Rotas novas** (POST, com CSRF, limite e PRG): `/operacional/chamados/lote`, `/operacional/chamados/{id}/atender` e
  `/operacional/chamados/{id}/atendimento`. Tudo preso ao escopo do condomínio: o lote só altera os chamados que a
  lista do usuário mostra, e as ações num chamado conferem `findFor`.
- Cores e componentes do ERP (`erp-ticket-*` em `assets/css/erp.css`, cronômetro em `assets/js/erp.js`; bundles regerados).

## Lacunas

- A busca de artigos da Central no compositor e o cadastro de respostas rápidas ficam só no Help Desk.
- O cronômetro conta só com a aba visível e zera ao recarregar a página.
