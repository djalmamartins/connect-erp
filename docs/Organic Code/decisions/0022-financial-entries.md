# 0022 — Lançamentos (AP-020): receitas e despesas por condomínio

Data: 2026-11-08 · Status: aceito (ciclo autônomo da frente financeira, por delegação do Djalma; aguarda integração no container)

## Contexto

O núcleo financeiro já tem plano de contas (ADR 0019), tipos de faturas (0020), centros de custo
e contas bancárias (0021). A página AP-020 "Lançamentos" (Financeiro) existia sem dados. A ficha
AP-020 segue PENDENTE (auditoria somente leitura). Os campos e regras abaixo são PROPOSTOS.

## Decisão

- **Lançamentos usam o motor de cadastros** (definição `entries`, tabela `erp_entries`,
  migration `20261108_0506`; permissões `erp.entries.view/manage`, migration `0507`), escopo
  `required`: todo lançamento pertence a um condomínio.
- **Campos**:
  - natureza (receita ou despesa);
  - competência `AAAA-MM`, descrição, valor em centavos (maior que zero);
  - conta do plano de contas: **analítica**, ativa, do padrão ou do mesmo condomínio, e **da
    mesma natureza do lançamento**;
  - centro de custo e fornecedor (opcionais);
  - vencimento (obrigatório), data de pagamento, conta bancária, número do documento,
    observações.
- **Pago = `paid_on` preenchido**, e nesse caso a conta bancária é obrigatória. Ela precisa ser
  ativa e do mesmo condomínio.
- **Cancelar = situação "Cancelado"** (`inactive`). O registro e o histórico de auditoria ficam.
  A exclusão lógica continua disponível pelo motor.
- **Integridade**: conta contábil, centro de custo, conta bancária e fornecedor usados por
  lançamento ativo não podem ser excluídos (`RegistryInUse`, 409). As FKs são `ON DELETE RESTRICT`.
- **Recursos genéricos novos no motor**:
  - `ref_match`: uma coluna do registro apontado precisa ser igual a um campo deste registro
    (natureza da conta = natureza do lançamento);
  - `positive` em `money`: valor zero é recusado.
- O OpenAPI dos cadastros (`docs/api/registries.openapi.yaml`) inclui `/entries` e o 409 dos
  cadastros apontados. A descrição de `ref` para cadastros sem escopo (fornecedores) foi corrigida.

## Consequências

- Saldo por conta bancária, extrato e fechamento mensal (AP-030) vão ser calculados a partir dos
  lançamentos válidos. Ainda não há consolidação.
- Situação "em aberto / pago / vencido" é derivada (`paid_on` e `due_on`), não é coluna.
- Lançamentos fixos (AP-021), contas a pagar e a receber (AP-022, AP-023) e as telas "lançar
  receita/despesa" (AP-024, AP-025) devem ser visões ou atalhos sobre esta mesma tabela, para não
  criar uma segunda fonte de verdade.
- Testes: `tests/legacy/Unit/EntriesDefinitionTest.php` e `tests/legacy/Integration/EntriesTest.php`.
