# 0105 — Documentos da unidade na data de referência e só com contas do condomínio da unidade

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão: `docs/security/REVIEW_API_FINANCEIRO.md` (14ª rodada)

## Contexto

A ADR 0044 diz que os documentos da unidade são gerados das contas "na data de referência". A revisão
de `UnitDocuments` encontrou três pontos.

1. **Data de referência.** A certidão, a declaração, a anuência e a quitação usavam a situação de hoje
   (`paid_on IS NULL`), e não a da data informada. Uma certidão com data de 01/10 saía "nada consta"
   para uma conta vencida em setembro e paga só em 15/10.
2. **Condomínio da conta.** As contas eram filtradas só por `unit_id`. Uma conta de outro condomínio
   apontando para a unidade (dado inconsistente) entrava no documento, o mesmo padrão corrigido nas
   ADRs 0101 e 0102.
3. **Cancelamento em paralelo.** Dois cancelamentos ao mesmo tempo gravavam dois registros de auditoria.

## Decisão

- Em aberto na data = ativa, sem exclusão e com `paid_on` vazio **ou posterior** à data de referência.
  A quitação conta como pagas só as contas com `paid_on` até a data de referência.
- Todas as consultas de contas dos documentos exigem `condominium_id` igual ao da unidade. O recibo já
  fazia isso (ADR 0062).
- `cancel` confere as linhas afetadas pelo `UPDATE ... AND status = 'issued'`. Se nenhuma linha mudou,
  responde "já está cancelado" e não audita.

## Consequências

- Documento com a data de hoje não muda.
- Lacuna: uma conta cancelada ou criada depois da data de referência ainda é lida pelo estado atual. Não
  há histórico de status nas contas para reconstruir isso.
- Teste: `UnitDocumentsIntegrationTest::testDocumentsFollowTheReferenceDateAndTheUnitCondominium`.
  Ele roda só no Mac, porque o CI roda apenas a suíte unitária. A corrida do cancelamento não tem teste
  automático.
