# 0113 — Limites da memória de cálculo

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão do ADR 0089 (AP-075)

## Contexto

Revisão de `PaymentReports` e `GET /api/v1/reports/{payments,calculation,transfers}`. O escopo por condomínio,
as permissões, o limite de período, o teto de linhas, o CSV seguro e o limite de exportações estavam certos.

A memória de cálculo aceitava qualquer data de referência (`as_of`). Cada conta vencida gera um passo por mês
de índice entre o vencimento e o `as_of`, até 600 meses (`LateCharges::indexMonths`). Com `as_of=2070-01-01`,
cada uma das até 20 mil linhas viria com 600 passos e 600 meses "sem taxa". A resposta passaria de 12 milhões
de passos, o que esgota a memória do PHP. Basta ter `erp.entries.view` e `erp.agreements.view` para pedir isso.

## Decisão

1. `PaymentReports::asOf()` (regra pura) valida a data e recusa mais de `MAX_AHEAD_DAYS = 60` dias depois de
   hoje (422). Simular o pagamento numa data próxima continua possível, e depois disso não há índice publicado.
2. A soma dos passos de índice de todas as linhas tem teto `MAX_INDEX_STEPS = 120000` (422, "escolha uma
   unidade"). O teto é conferido durante a montagem, antes de crescer a resposta.

## Consequências e lacunas

- Datas passadas continuam livres, porque servem para conferir cobranças antigas.
- O teto de 120 mil passos foi estimado (cerca de 50 MB de resposta), sem medir no servidor.
- Os relatórios de pagamentos e de transferências não mudaram.
