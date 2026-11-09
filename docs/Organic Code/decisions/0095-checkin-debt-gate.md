# 0095 — Check-in da assembleia sem o valor vencido

Data: 2026-11-09 · Status: aceito (revisado na 24ª rodada) · Continuação do ADR 0094

## Contexto

O ADR 0094 escondeu o valor vencido por unidade na lista de presença da assembleia para quem não tem
`erp.agreements.view`. O retorno do check-in (`Assemblies::checkIn`) ainda devolvia `overdue_cents` a
quem registra a presença.

Procurei outras respostas do serviço e do controller da assembleia que expusessem o valor:
- o voto devolve só o peso;
- o detalhe (`find`) devolve contagens;
- a ata não tem valores;
- os resultados não têm valores.

Só o check-in expunha.

## Decisão

- `checkIn()` ganha o parâmetro `$withDebt` (padrão false).
  - Sem ele, `overdue_cents` vem null.
  - O direito a voto (`eligible`) e o motivo (`reason`: "inadimplente", ou null para a unidade quite)
    voltam sempre, porque a mesa da assembleia precisa deles.
- O controller passa `Access::can('erp.agreements.view', $user)`, o mesmo gate do ADR 0094.
- O valor continua gravado em `erp_assembly_attendance` e aparece no relatório de presença só com a
  permissão.

## Consequências

- **Testes:**
  - a matriz estática (`NewRoutesPermissionMatrixTest`) passa a exigir o gate no check-in;
  - `CheckInDebtIntegrationTest` cobre o check-in sem a permissão (sem o valor) e com ela (com o valor);
  - o teste do ADR 0088 passa a conferir o valor no banco, não na resposta.
- **Sem migration.**
