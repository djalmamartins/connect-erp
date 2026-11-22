# 0640 — Formas de rateio da fatura, multa e juros do boleto atrasado e condomínios de teste do rateio

- Status: Aceito
- Data: 22/11/2026
- Relacionados: ADR 0033 (faturas), 0035/0038 (boletos e retorno), 0040 (regra de cobrança), 0045 (leituras e consumo),
  0311 (unidade isenta de rateio), 0141 (Central de Ajuda), decisões padrão da issue #46 (multa 2%, juros 1% a.m. pro rata, IPCA).
- Numeração: faixa avulsa 0600–0799; 0639 é das propostas em Studio > Gestão (outra thread). Conferidos índice, main,
  claude-dev e PRs abertos em 22/11.

## Contexto
O Djalma pediu um condomínio de teste para cada tipo de rateio (fração ideal, igualitário, por unidade, consumo
individual, por bloco/torre, por vaga, percentual definido e personalizado), alimentados de 01/01/2026 a 22/11/2026
"como se estivesse funcionando normal" (faturas, boletos, despesas, consumo, acordos, fechamentos e prestação de contas),
e uma auditoria contábil de tudo.

Conferência do código antes de simular (CONFIRMADO):
1. A fatura mensal só sabia **2 dos 8 tipos**: valor fixo por unidade e fração ideal. Não havia percentual definido por
   unidade, divisão pelo número de unidades, por vagas, por bloco/torre nem personalizado.
2. **Boleto pago com atraso** (valor com multa e juros) virava divergência do retorno ("Valor pago diferente do título")
   e a conta da unidade ficava em aberto. Reproduzido na simulação: todo pagador atrasado continuava inadimplente.
3. **Pagamento atrasado depois do fechamento do mês da conta** era recusado ("Competência fechada"), tanto no retorno do
   banco quanto na baixa em lote e na baixa manual. Para receber um atrasado era preciso reabrir o mês antigo, e isso
   travava os fechamentos seguintes (regra de ordem do fechamento).
4. **Inadimplência numa data passada** (base do acordo e do relatório "na data") ignorava pagamentos feitos depois dessa
   data: em todos os fins de mês dos 9 condomínios mostrava R$ 0,00 em vez do valor que estava em aberto naquele dia
   (ex.: R$ 2.608,95 em 31/03 no Condomínio Por Fração Ideal).

## Decisão
1. **Formas de rateio da fatura** (`Invoices::METHODS`): `fixed` (valor por unidade, igualitário), `equal` (total ÷
   unidades), `fraction` (fração ideal), `percent` (novo campo da unidade **Percentual definido no rateio (%)**,
   `erp_units.apportion_percent`, precisa somar 100), `parking` (vagas ativas em Áreas vinculáveis; unidade sem vaga
   fica fora) e `custom` (valor de cada unidade, ou total + percentual de cada uma somando 100). Centavos pelo maior
   resto; a soma sempre fecha com o total. Regra pura em `Invoices::distribute()`.
2. **Participantes**: `all` (todas menos as isentas de rateio), `blocks` (só os blocos/torres informados) e `units` (só as
   unidades escolhidas; isenta escolhida entra). Forma, participantes, blocos e valor informado ficam gravados na fatura
   (`erp_invoices.method`, `participants`, `participant_blocks`, `requested_cents`) e na auditoria.
3. **Consumo individual** continua em Leituras e consumo (ADR 0045); não é forma da fatura.
4. **Boleto atrasado**: no retorno, valor pago acima do título depois do vencimento e até multa + juros pro rata da regra
   de cobrança (sem a correção do índice) + R$ 1,00 de arredondamento é aceito: a conta é baixada pelo valor dela e a
   diferença vira receita **"Multa e juros por atraso"** na conta **1.03** do plano, competência = mês do pagamento,
   documento `ENC-{nosso número}`. Sem a conta 1.03, vai para conferência. Fora da faixa, continua divergência.
5. **Baixa de conta de mês fechado**: só o pagamento (data, banco) pode entrar numa conta de competência fechada; o que
   precisa estar aberto é o **mês do pagamento**. Vale para o retorno do banco, a baixa em lote e a baixa manual
   (`RegistryService::isSettlementOnly`). A foto do fechamento antigo não muda; o recebimento aparece no caixa do mês em
   que entrou.
6. **Inadimplência na data** considera em aberto a conta paga depois da data (`paid_on IS NULL OR paid_on > data`).
7. **Condomínios de teste** (`tools/lab-rateios.php`, `ApportionmentSample`, `ApportionmentAudit`): 9 condomínios (os 8
   tipos e um misto), 310 unidades, com moradores, faturas, boletos no sandbox, Pix, atrasos com multa e juros, despesas,
   leituras de água e gás, transferência para o fundo de reserva, rendimento, acordo (um quebrado), 13 fechamentos e
   prestação de contas publicada por mês. Só roda com o guarda dos dados de demonstração (`ERP_DEMO_DATA=true`, ambiente
   lab/dev e banco igual a `ERP_DEMO_DB`); `--conferir` refaz só a auditoria e `--remover` apaga os 9 condomínios.

## Consequências
- A auditoria fecha as 552 conferências (rateios, consumo, boletos, multa e juros, acordos, bancos, fechamentos,
  prestações publicadas, inadimplência de hoje e de cada fim de mês, extrato, balancete, DRE e balanço).
- Migration `20261122_1400_invoice_apportionment` (com `.down.sql`): só colunas novas com padrão nulo; faturas antigas
  ficam com a forma vazia e o mesmo comportamento.
- API: `POST /api/v1/invoices` aceita `method`, `participants`, `blocks`, `unit_ids`, `unit_amounts`, `unit_percents`
  (OpenAPI atualizado). Tela Faturas com "Quem participa", blocos e lista de unidades para escolher e para o personalizado.

## Pendências e riscos (PENDENTE)
- **Acordo de meses fechados** exige reabrir esses meses e publicar a prestação de novo (3 reaberturas por condomínio na
  simulação). Funciona, mas dá trabalho; decidir se o acordo deve entrar no mês em que é feito.
- O banco não cobra a **correção pelo IPCA** no boleto atrasado, só multa e juros. O acordo cobra.
- Tarifa de consumo com faixas no formato "0–10 / 10,001–20" deixa 0,001 m³ por faixa sem cobrar (a tela de cobertura
  avisa). A convenção do sistema é faixa contígua (0–10 / 10–20).
- Prestação já publicada fica intacta (sha256), mas refeita hoje dá outro número em ~7 meses por condomínio, porque as
  "receitas da competência em aberto" mudam quando o atrasado paga. Esperado; registrado para quem comparar as duas.
- Não foi testado com banco real nem com o sandbox do Inter: o retorno usa o adaptador sandbox local.
