# 0069 — Dados dos gráficos: saldo por conta bancária filtrado por condomínio e com teto de séries

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma) · Revisão: `docs/security/REVIEW_API_FINANCEIRO.md` (oitava rodada)

## Contexto

A revisão dos dados dos gráficos (ADR 0083) conferiu o escopo por condomínio, o CSV e o custo das
consultas. Os gráficos não exportam CSV; respondem JSON com até 24 meses. Seis dos sete gráficos
agregam com `GROUP BY` e filtram `condominium_id` na tabela principal. As contas do plano e as áreas
comuns já saem com as 8 maiores séries e o resto em "Outras". O consumo de uma unidade fora do
escopo responde igual ao de uma unidade inexistente. Ficaram dois pontos no gráfico de contas
bancárias (AP-108).

## Decisão

- **Filtro de condomínio dentro da subconsulta.** O saldo por conta montava uma tabela derivada com
  os lançamentos pagos e as transferências de **todos** os condomínios no período, e só depois
  filtrava pela conta bancária do escopo. Isso não vazava dados, porque o filtro final é pela conta,
  mas varria a base inteira, e um lançamento ou transferência de outro condomínio que apontasse para
  a conta (dado inconsistente) entraria no saldo dela. Agora cada parte da subconsulta filtra
  `condominium_id` do próprio lançamento ou transferência, usando os índices que já existem.
- **Teto de séries.** O gráfico devolvia uma série por conta bancária, sem limite: sem condomínio
  escolhido, podiam ser centenas. Agora usa `ChartReports::top` como os outros, com as 8 contas de
  maior movimento e o resto em "Outras". Como saldo tem sinal, a ordem é pela soma dos valores
  absolutos (`top(..., byMagnitude: true)`), para uma conta com saída grande não cair em "Outras".

## Consequências

- O JSON do AP-108 pode trazer a série `others` ("Outras"), como AP-103 e AP-109.
- `ChartReportsTest::testTopByMagnitudeKeepsLargeNegativeBalances` e
  `ChartReportsIntegrationTest::testBankAccountsCountOnlyMovementsOfTheSameCondominium` cobrem as
  regras.
- Esta é a última da faixa 0060–0069 do Preparar; a próxima é 0100.
