<!-- markdownlint-disable MD024 -->
# 0401 — Contas ainda ativas de faturas canceladas inativadas em lote

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0400 (conferência das faturas: o `still_active` da fatura cancelada);
  - 0033 (faturas: `Invoices::cancel` inativa as contas da fatura emitida);
  - 0039 (acordo);
  - 0100 (fechamento mensal: a competência fechada não muda);
  - 0283 a 0299 (o desenho das ações em lote).

## Contexto

A conferência das faturas (ADR 0400) aponta como `still_active` o item de fatura cancelada cuja conta a receber segue
ativa. `Invoices::cancel` inativa as contas da fatura emitida. Mas uma conta reativada depois pelo cadastro, ou gravada fora
do fluxo, fica cobrando a unidade por uma fatura que não vale mais. Corrigir era uma por uma, pelo cadastro de lançamentos.

Busquei as chaves estrangeiras para `erp_entries`. Seis tabelas ligam uma conta a algo que não deve mudar por baixo:

- `erp_agreement_originals` (acordo);
- `erp_bank_charges` (boleto);
- `erp_legal_case_entries` (cobrança judicial);
- `erp_unit_document_entries` (documento da unidade);
- `erp_bank_statement_lines` (linha de extrato);
- `erp_check_copies` (cópia de cheque).

## Decisão

Rota nova: `POST /api/v1/invoices/deactivate-cancelled-entries {condominium_id, entry_ids?, reason, dry_run?}`, logo depois
de `GET /reports/invoice-reconciliation`. O service é `InvoiceEntryCleanup`, em `Source\Services\Erp`.

### Reuso

- **A mesma gravação de `Invoices::cancel`:** status `inactive` e `updated_by` na conta ligada ao item por
  `invoice_item_id`. O teste unit confere o código do cancelamento.
- **As mesmas recusas do cancelamento:** conta paga, conta em acordo e competência fechada
  (`RegistryService::isClosed`, que trava a leitura do fechamento dentro da transação, ADR 0100).
- **Cadastro de lançamentos** (`RegistryService::definition('entries')`): a tabela e a permissão.

### Regra

- **Só contas de faturas canceladas do próprio condomínio.** A conta de fatura emitida ou em preparação nunca entra.
- **Sem `entry_ids`:** entram as contas ativas, não excluídas, sem recusa. As outras ficam de fora e são contadas:
  - `skipped_paid`: a paga;
  - `skipped_linked`: a em acordo ou ligada a uma das seis tabelas;
  - `skipped_closed`: a de competência fechada.
- **Com `entry_ids`:** só as informadas. A regra pura `problem()` recusa:
  - conta não encontrada entre as de faturas canceladas do condomínio;
  - já inativa;
  - paga;
  - em acordo ou ligada;
  - de competência fechada.
- **Tudo ou nada:** uma transação com as contas travadas (`FOR UPDATE`). Uma recusada desfaz todas (422, `_form` e
  `_entry`). O `UPDATE` repete as condições (`status = 'active'`, sem pagamento, sem acordo); se não mudar uma linha, 409.
- **`dry_run`:** passa por tudo e desfaz, sem auditoria.
- **Auditoria:** um `update` por conta (status, fatura, motivo e tamanho do lote), só quando grava.
- **Limites:** até 500 por vez; motivo de 5 a 255.

### Integração bancária

As seis tabelas só são lidas para saber se há ligação (`SELECT DISTINCT entry_id`). Nenhum boleto, extrato, credencial ou
integração bancária é lido além disso, gravado ou chamado. O teste unit confere que o service não grava em `erp_invoices` nem
em `erp_bank_*`.

### Acesso e dados

- **Permissão:** `erp.access`, `erp.invoices.view` e `erp.invoices.manage` (como no cancelamento), e `erp.entries.view`
  e `erp.entries.manage`. Sem uma delas, 403 (fail-closed). CSRF pelo `ApiAuthenticated`.
- **Escopo (ADR-002):** fora do escopo, 404, antes de consultar. Entrada inválida, 422.
- **Dados que saem:** ids de contas e de faturas e a competência, mais os contadores de puladas, `invoices`, `count` e
  `changed`. Não saem unidade, morador, descrição, valor nem dado bancário.
- **Nada é apagado e nenhuma fatura muda.** A conta inativa pode ser reativada pelo cadastro, como antes.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\InvoiceEntryCleanupTest`:**
  - a regra pura;
  - a validação e o escopo;
  - as recusas, uma por caso (boleto, paga, acordo, cobrança judicial, já inativa, fechada, fatura emitida, excluída,
    outro condomínio), com tudo desfeito;
  - a prévia e a inativação só das contas seguras, com as outras contadas à parte;
  - nada apagado, nenhuma fatura alterada;
  - o caso que a conferência da 0400 aponta;
  - o contrato: as seis tabelas apontam a conta por `entry_id` nas migrations, e a gravação é a do cancelamento.
- **`Integration\InvoiceEntryCleanupTest`** (MariaDB, banco `*_test`): a prévia sem auditoria, a conta paga e a de
  competência fechada de fora, a recusa da conta de fatura emitida, a inativação com um registro por conta e as faturas
  intactas.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
- **Numeração:** a próxima do Avançar é a 0402 (faixa 0400–0599).
