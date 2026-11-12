<!-- markdownlint-disable MD024 -->
# 0227 — Reatribuição em lote dos chamados

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:**
  - ADR 0142 (conversa e histórico do chamado);
  - 0226 (tempo de atendimento);
  - 0221 (mesmo padrão: ids opcionais, tudo ou nada, prévia e auditoria).

## Contexto

Quando alguém da equipe do Help Desk sai de férias ou muda de carteira, os chamados abertos dele em um condomínio
precisam ir para outra pessoa. No Help Desk, a troca é chamado por chamado (Studio, ação `update`). A ação em lote do
Help Desk (`bulk`) só muda a situação.

## Decisão

Rota nova: `POST /api/v1/tickets/reassign` com `{condominium_id, from_user_id, to_user_id, reason, ticket_ids?,
dry_run?}`. O service é `TicketReassignment`, em `Source\Services\Support`, ao lado do `TicketService`.

### Reuso

- **Situações em aberto:** as do `TicketService` (aberto, em atendimento e aguardando cliente).
- **Quem pode ser responsável:** a mesma regra da lista de responsáveis do Help Desk (nível da equipe, 5 ou mais).
- **Histórico do chamado:** o mesmo da edição de um chamado: `studio_support_ticket_events` com `event_type` igual a
  `assigned_to` e os valores antigo e novo.
- **Só o responsável muda:** situação, prioridade e prazo ficam como estão.
- **Nenhum aviso é enviado:** nem e-mail nem notificação. A edição de um chamado também não avisa a troca de
  responsável.

### Quais chamados entram

- **Sem `ticket_ids`:** os chamados em aberto do condomínio atribuídos a `from_user_id`.
- **Com `ticket_ids`:** só os informados. A regra pura `problem()` recusa o chamado:
  - não encontrado no condomínio;
  - concluído (resolvido ou fechado);
  - de outro responsável.

### Gravação

- **Tudo ou nada:** numa transação com os chamados travados (`FOR UPDATE`), um recusado desfaz todos. O 422 traz `_form`
  e `_ticket`.
- **Troca condicionada:** o `UPDATE` exige que o responsável ainda seja o antigo. Se o chamado mudou no meio do caminho,
  o lote é desfeito.
- **Prévia:** `dry_run` passa por tudo e desfaz no fim.
- **Auditoria:** um registro `reassign` por chamado (de, para e motivo), só quando grava.
- **Motivo:** obrigatório, de 5 a 255 caracteres.
- **Limite:** até 500 chamados por vez.
- **Novo responsável:** precisa ser outra pessoa, da equipe.

### Acesso e dados

- **Permissão:** exige `studio.access` e `support.manage`, as mesmas da edição de chamados no Help Desk; sem uma delas,
  403 (fail-closed).
- **Escopo (ADR-002):** além da permissão, o condomínio precisa estar no escopo do usuário; fora dele, 404.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** id, prioridade, situação e prazo dos chamados, e os ids dos responsáveis. Não saem protocolo,
  assunto, mensagem, solicitante ou nome de responsável.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\TicketReassignmentTest`:**
  - a regra pura;
  - validação: o mesmo responsável, alguém fora da equipe e um usuário inexistente;
  - escopo (404);
  - a prévia no SQLite (só os abertos do responsável no condomínio, sem gravar e sem dado do chamado);
  - as recusas, sem transação aberta e sem gravar;
  - contrato: só o responsável muda e sem aviso;
  - controller e rota.
- **`Integration\TicketReassignmentTest`** (MariaDB, banco `*_test`):
  - chamados abertos pelo `TicketService::open`;
  - a prévia;
  - a reatribuição dos abertos, com o resolvido e o de outro responsável de fora;
  - situação e prioridade intactas;
  - os eventos `assigned_to` no histórico e a auditoria `reassign`;
  - nenhum e-mail ou notificação novos;
  - os ids com um fechado desfazendo o lote;
  - escopo.
- **Sem tela nova neste patch.** O Help Desk pode ganhar "Passar chamados para…" na ação em lote; quando ganhar, vem o
  artigo da Central de Ajuda.
