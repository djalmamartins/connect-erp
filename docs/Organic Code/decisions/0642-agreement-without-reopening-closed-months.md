# 0642 — Acordo sem reabrir mês fechado, acordo sem IPCA e faixa de tarifa "10,001"

- Status: Aceito
- Data: 22/11/2026
- Relacionados: ADR 0039/0040 (acordos e regra de cobrança), 0045 (leituras e consumo), 0179 (prestação publicada),
  0258 (cobertura das tarifas), 0640 (condomínios de teste do rateio, que mostraram os três pontos).
- Numeração: faixa avulsa 0600–0799; 0641 é do aviso do chamado no app do morador (outra thread). Conferidos índice da
  main e PRs abertos em 22/11.

## Contexto
A auditoria dos condomínios de teste (ADR 0640) deixou três pontos para o Djalma decidir. Respostas dele (22/11):
1. "Não pode mudar uma prestação de conta fechada." Hoje o acordo com conta de mês fechado obrigava a reabrir o mês,
   fazer o acordo, fechar de novo e publicar outra versão da prestação (3 reaberturas por condomínio na simulação).
2. Boleto atrasado continua só com multa e juros (sem IPCA). Pediu um botão para tirar o IPCA na hora do acordo.
3. Tarifa "0 a 10 / 10,001 a 20": tratar o 10,001 como continuação e explicar na página.

## Decisão
1. **Acordo nunca reabre mês fechado.** Para cada conta de competência fechada que entra no acordo:
   - a conta fica ativa no mês dela, como estava, e é quitada por **encontro de contas** na data do acordo
     (`paid_on` = data do acordo, sem conta bancária, `agreement_role = 'original'`);
   - no mês do acordo entra uma **despesa de compensação** do mesmo valor na conta **2.92 "Cotas renegociadas em acordo"**
     (despesa analítica do plano; sem ela o acordo é recusado com a orientação), quitada no mesmo dia e sem banco
     (`document_number = ACO-{n}-COMP`, `agreement_id` do acordo);
   - as parcelas continuam como receita nos meses em que vencem.
   Resultado: o mês fechado, a foto do fechamento e a prestação publicada não mudam; a receita antiga fica no mês dela,
   a compensação a anula no mês do acordo e as parcelas entram depois, sem contar nada duas vezes; nenhum dinheiro
   aparece nos bancos. A inadimplência numa data antes do acordo continua mostrando a dívida. Conta de mês aberto
   segue como antes (cancelada e ligada ao acordo).
   - O mês do acordo precisa estar aberto. `agreed_on` (opcional, padrão hoje, nunca futuro) existe para a API e para os
     dados de teste; num mês fechado é recusado.
   - Cancelar o acordo devolve a conta de mês fechado para "em aberto" (só o pagamento muda) e cancela a compensação;
     se o mês do acordo já fechou, o cancelamento pede reabrir esse mês, como as parcelas.
   - "Pago sem banco" na conferência antes do fechamento ignora os lançamentos de acordo.
2. **Acordo sem IPCA**: caixa "Sem correção pelo IPCA (só multa e juros)" no modal do acordo;
   `GET /api/v1/agreements/suggestion?...&without_correction=1` recalcula os encargos sem o índice.
   O IPCA é o índice padrão porque, desde a Lei 14.905/2024 (Código Civil, art. 389, parágrafo único), ele corrige a
   dívida quando a convenção não define outro; multa até 2% e juros da convenção ou 1% a.m. (art. 1.336, § 1º).
3. **Faixas de tarifa**: a faixa que começa até 0,01 depois do fim da anterior (10 → 10,001 ou 10,01) continua a
   anterior, no cálculo da leitura (`MeterReadings::BAND_STEP`) e na conferência de buracos (`TariffCoverage`).
   Explicação no topo da tela Tarifas, na dica do campo "De" e no artigo da Central de Ajuda.

## Consequências
- Auditoria dos 9 condomínios: 588 conferências, todas OK, **nenhum mês reaberto** e nenhuma prestação republicada
  (novas conferências: compensação = contas de mês fechado; versão 2+ de prestação = 0; meses reabertos = 0).
- Relatórios que somam "recebido" pela data do pagamento mostram, no mês do acordo, a quitação por compensação e a
  despesa de compensação do mesmo valor (sem banco); o líquido é zero e o saldo dos bancos não muda.
- Sem migration: usa as colunas que já existem.

## Pendências (PENDENTE)
- A conta 2.92 precisa ser criada no plano de contas de produção antes do primeiro acordo de mês fechado.
- Boleto atrasado continua sem IPCA (decisão do Djalma).
