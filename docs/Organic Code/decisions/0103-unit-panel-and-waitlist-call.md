# 0103 — Painel da unidade só com as contas dela e chamada da lista de espera travada

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão: `docs/security/REVIEW_API_FINANCEIRO.md` (12ª rodada)

## Contexto

A revisão de `PanelSummaries` (AP-002 e AP-003, ADR 0071) e `OperationalDashboard` (ADR 0050) encontrou dois pontos.

1. **Painel da unidade.** Para mostrar a dívida de uma unidade, o painel calculava a inadimplência do
   condomínio inteiro, com encargos de cada conta vencida, e depois filtrava a unidade na memória. O
   custo de abrir o painel de uma unidade crescia com o condomínio.
2. **Chamada da lista de espera.** `callWaitlist` não travava a reserva cancelada nem conferia se alguém
   já tinha sido chamado para ela. Dois cliques, ou dois atendentes, chamavam duas pessoas para a mesma vaga.

## Decisão

- `Agreements::delinquency` aceita `$unitId`. Com ele, a consulta lê só as contas da unidade, e o painel
  da unidade passa a usá-lo. O resultado continua o mesmo.
- `callWaitlist` trava a reserva (`FOR UPDATE`) e recusa chamar outra pessoa enquanto quem já foi chamado
  para aquela reserva estiver na lista (`active` ou `served`). Quando a pessoa chamada desiste
  (`inactive`), o próximo pode ser chamado.

## Consequências

- O painel do condomínio, o relatório de inadimplência e a tela de cobrança não mudam (sem `$unitId`).
- `OperationalDashboardIntegrationTest::testCallWaitlistOnlyRecordsTheCall` passa a cobrir a recusa da
  segunda chamada e a chamada do próximo depois da desistência.
- Na tela, chamar de novo agora mostra a mensagem de quem já foi chamado. Antes, a tela chamava o
  próximo da fila.
