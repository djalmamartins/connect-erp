# 0039 — Inadimplência e acordos (AP-033/034)

Data: 2026-11-09 · Status: aceito (rodada autônoma, por delegação do Djalma)

## Contexto

Depois das faturas (ADR 0033), dos boletos (ADR 0035) e dos relatórios (ADR 0037), falta a cobrança
do que venceu: ver quem deve e renegociar em parcelas. As fichas AP-033 (Acordos) e AP-034 (Parcelas
de Acordo) ainda são scaffold. A regra de multa e juros não foi aprovada
(`docs/banking/BANKING_INTEGRATIONS.md`, decisões pendentes). As regras abaixo são PROPOSTAS.

## Decisão

- **Inadimplência = contas a receber válidas, em aberto e vencidas** (vencimento antes da data de
  referência; vence hoje ainda não conta), agrupadas por unidade. Faixas de atraso: 1-30, 31-60, 61-90
  e 90+ dias. É calculada a cada pedido, sem tabela.
- **Acordo** (`erp_agreements`, migration 0519), de uma unidade:
  - entram as contas vencidas escolhidas, em aberto, da mesma unidade, fora de outro acordo, sem
    boleto ativo e de competência aberta. Competência fechada exige reabrir o fechamento (ADR 0031),
    que fica auditado. Assim a foto do mês fechado não muda.
  - `total = original + encargos − desconto`. Multa, juros e desconto são valores informados por
    quem faz o acordo; não há cálculo automático até a regra ser aprovada.
  - As parcelas são mensais e iguais, de 1 a 60; os centavos que sobram vão para a primeira. O dia
    da primeira se repete, limitado ao fim do mês (`Agreements::plan`, regra pura).
- **Na mesma transação:**
  - as contas originais ficam canceladas (`status inactive`, `agreement_role 'original'`), e a
    tabela `erp_agreement_originals` guarda o histórico;
  - cada parcela vira uma conta a receber nova, pelo `RegistryService` (mesmas regras dos lançamentos,
    inclusive a trava do fechamento), na conta de receita escolhida e com a unidade
    (`agreement_role 'installment'`, chave única por número de parcela).
  - As parcelas são pagas como qualquer conta a receber: baixa manual ou boleto com retorno (ADR 0035).
- **Trava:** a trava de origem do ADR 0033 passa a valer também para `agreement_id`. Parcela e conta
  renegociada não mudam de valor, unidade, competência ou situação pelo cadastro, nem são excluídas.
  A baixa continua livre. A fatura com conta renegociada não cancela antes do acordo.
- **Cancelar o acordo** exige motivo e só vale sem parcela paga, sem boleto ativo em parcela e com as
  competências abertas. As parcelas ficam canceladas, e as contas originais voltam a valer, livres
  para outro acordo.
- **Andamento:** parcelas pagas, valor pago, valor em aberto e `settled` (todas pagas), calculados
  dos lançamentos.
- **Permissões** `erp.agreements.view` e `erp.agreements.manage` (migration 0521). Escopo por
  condomínio (404 fora dele). Tudo auditado.
- **API:** `GET /api/v1/delinquency`, `GET/POST /api/v1/agreements`, `GET /api/v1/agreements/{id}` e
  `POST /api/v1/agreements/{id}/cancel`.

## Consequências

- O relatório de caixa (ADR 0037) passa a mostrar o que entra pelas parcelas. O balancete por
  competência mostra a dívida original cancelada e as parcelas nas competências delas.
- Ficam para depois: cálculo automático de multa e juros, quebra de acordo por atraso de parcela e
  cobrança judicial (AP-039), quando houver regras aprovadas.
- A frente de layout monta as telas AP-033 e AP-034 sobre a API.
