# 0109 — Cancelamento de comunicado com o comunicado travado

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão da comunicação (ADR 0059)

## Contexto

Na ADR 0059, `Announcements::cancel` conferia se o comunicado estava ativo fora da transação e depois
atualizava sem conferir quantas linhas mudaram. Dois cancelamentos ao mesmo tempo passavam pela
conferência e gravavam duas trilhas de auditoria. O segundo também sobrescrevia o motivo e quem cancelou.

## Decisão

- Dentro da transação, `cancel` trava o comunicado (`FOR UPDATE`) e confere de novo se está ativo.
  Se não está, recusa com a mesma mensagem ("O comunicado já está cancelado.") e desfaz a transação.

## Consequências

- A resposta da API não muda.
- Lacuna: não há teste automático da corrida (exige duas conexões). O teste de integração cobre só a
  recusa sequencial e roda só no Mac.
