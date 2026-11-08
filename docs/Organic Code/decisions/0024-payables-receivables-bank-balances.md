# 0024 — Contas a pagar e a receber (AP-022/023) como visões dos lançamentos e saldo por conta bancária

Data: 2026-11-08 · Status: aceito (ciclo autônomo da frente financeira, por delegação do Djalma; aguarda integração no container)

## Contexto

Os lançamentos (ADR 0022) são a única fonte das receitas e despesas. As páginas AP-022 "Contas a
Pagar" e AP-023 "Contas a Receber" existiam sem dados. Ter uma tabela própria para elas criaria
uma segunda verdade sobre o mesmo dinheiro.

## Decisão

- **Sem tabela nova**: `payables` (AP-022, `/financeiro/contas-a-pagar`, API `/api/v1/payables`) e
  `receivables` (AP-023, `/financeiro/contas-a-receber`, API `/api/v1/receivables`) são
  definições do motor sobre `erp_entries`, derivadas da definição `entries`.
- Dois recursos genéricos novos no motor:
  - `base_where`: filtro fixo aplicado em listagem, leitura, edição e exclusão (aqui, natureza da
    visão e `paid_on IS NULL`, ou seja, em aberto);
  - `fixed`: valores sempre gravados pela visão, que a entrada não consegue trocar (aqui, a
    natureza).
- **Baixa**: preencher "Pago em" (com a conta bancária) grava o pagamento e o lançamento sai da
  visão. Ele continua em Lançamentos. A conta do plano de contas listada já vem filtrada pela
  natureza da visão.
- **Permissões**: as mesmas de lançamentos (`erp.entries.view/manage`). Não há migration nem
  permissão nova neste passo.
- **Saldo por conta bancária**: `Source\Services\Erp\BankBalances` calcula, sem gravar, saldo
  inicial + receitas pagas − despesas pagas, só com lançamentos válidos (`active`, não excluídos)
  pagos a partir da data do saldo inicial, no escopo do usuário. Exposto em
  `GET /api/v1/bank-accounts/balances` (exige `erp.bank_accounts.view` e `erp.entries.view`,
  filtro opcional `condominium_id`), documentado em `docs/api/openapi.yaml`. A rota é registrada
  antes do laço dos cadastros, para `/bank-accounts/{id}` não capturar "balances".

## Consequências

- "Vencido" é derivado (`due_on` anterior a hoje, com `paid_on` vazio). A listagem ordena por
  vencimento. O destaque visual dos vencidos fica com a frente de layout.
- O saldo pode ficar negativo. Extrato por período e fechamento mensal (AP-030) vão usar a
  mesma regra de `BankBalances`.
- Testes: `tests/legacy/Unit/PayablesBalancesDefinitionTest.php` e
  `tests/legacy/Integration/PayablesBalancesTest.php`.
