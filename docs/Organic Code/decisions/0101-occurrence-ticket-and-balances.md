# 0101 — Chamado da ocorrência sem órfão e saldo bancário só com o próprio condomínio

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão: `docs/security/REVIEW_API_FINANCEIRO.md` (décima rodada)

## Contexto

A revisão de `Occurrences`, `BankBalances` e `ReceiptCandidates` encontrou dois pontos.

1. `Occurrences::openTicket` conferia a ocorrência numa transação curta, soltava a trava, abria o
   chamado no Help Desk e só então gravava o vínculo. Dois pedidos ao mesmo tempo abriam dois chamados.
   O segundo vínculo falhava e respondia erro, mas o chamado dele ficava no Help Desk sem ocorrência.
2. `BankBalances::forUser` e `transferSql` somavam os lançamentos e as transferências que apontavam
   para a conta, sem conferir o condomínio do lançamento ou da transferência. As gravações validam a
   conta do mesmo condomínio, então isso só pesaria com dado inconsistente. Os gráficos (ADR 0069) já
   filtram.

## Decisão

- **Vínculo na transação do chamado.** O vínculo passa a ser gravado pelo `$afterInsert` do
  `TicketService::open`, na mesma transação do chamado e com a ocorrência travada (`FOR UPDATE`). Se a
  ocorrência já tiver chamado, o próprio chamado é desfeito, e nenhum aviso de abertura sai.
- **Saldo só do próprio condomínio.** O saldo das contas (AP-012) e as consultas que usam `transferSql`
  (conciliação do OFX e relatório financeiro) exigem que o lançamento e a transferência sejam do
  condomínio da conta.

## Consequências

- `EntryShortcutsTest` confere o filtro do `transferSql`.
- A corrida da ocorrência não tem teste automatizado. Reproduzi-la exige duas requisições ao mesmo
  tempo, e o `TicketService` é `final`, então não dá para injetar a espera. O caminho de um pedido só
  continua coberto por `OperationalRegistriesIntegrationTest::testOccurrenceOpensHelpDeskTicketOnce`.
- `ReceiptCandidates` não mudou: confere o escopo pela unidade, responde 404 e para em 120 contas.
