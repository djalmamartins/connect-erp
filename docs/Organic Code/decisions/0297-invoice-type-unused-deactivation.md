<!-- markdownlint-disable MD024 -->
# 0297 — Tipos de faturas próprios sem uso inativados em lote

- **Status:** aceito
- **Data:** 2026-11-14
- **Relacionados:**
  - 0296 (situação dos tipos de faturas: os tipos à mão sem fatura no período);
  - 0163 (o tipo do condomínio com o mesmo nome prevalece sobre o padrão);
  - 0033 (faturas: rascunho, emitida e cancelada);
  - 0283 a 0295 (o desenho das ações em lote).

## Contexto

A situação dos tipos de faturas (ADR 0296) aponta em `unused_ids` os tipos à mão sem fatura no período. O tipo criado para
uma cobrança que acabou (uma obra, um rateio) segue ativo e aparece na escolha do tipo ao preparar a fatura. Inativar era
um por um, pelo cadastro.

Busquei `invoice_type_id` em `source/` e nas migrations: só as faturas apontam o tipo. A emissão (`Invoices::issue`) não
confere a situação do tipo, e a listagem da ADR 0163 esconde o padrão quando há um tipo não excluído do condomínio com o
mesmo nome, ativo ou não.

## Decisão

Rota nova: `POST /api/v1/invoice-types/deactivate-unused {condominium_id, months?, invoice_type_ids?, reason, dry_run?}`,
logo depois de `GET /reports/invoice-types`. O service é `InvoiceTypeDeactivation`, em `Source\Services\Erp`.

### Reuso

- **Cadastro do registro** (`RegistryService::definition('invoice_types')`): a tabela, a permissão, as recorrências e a
  coluna do `override`.
- **Override da ADR 0163:** o mesmo critério da listagem, para saber quando um tipo encobre um padrão ativo.
- **Faturas:** as não canceladas (ADR 0033) contam como uso; o rascunho é a fatura em preparação.
- **Período:** `DiscountOverview::months()` pela competência, no mês do banco (`SELECT NOW()`), como na situação da 0296.
- **Status:** o `inactive` do cadastro, com `updated_by`.

### Regra

- **Só tipos do próprio condomínio.** Os padrões da Connect (sem condomínio) nunca entram, e nenhuma fatura é tocada.
- **Sem `invoice_type_ids`:** entram os tipos próprios ativos sem fatura não cancelada nos últimos `months` meses (padrão
  24, de 6 a 120) e criados antes do período. Os sem uso com fatura em preparação ficam de fora, em `skipped_draft`; os sem
  uso que encobrem um padrão ativo de mesmo nome ficam de fora, em `skipped_overriding`.
- **Com `invoice_type_ids`:** só os informados. A regra pura `problem()` recusa:
  - tipo não encontrado entre os do condomínio (padrão, excluído ou de outro condomínio);
  - já inativo;
  - com fatura em preparação (a emissão não confere a situação do tipo);
  - encobrindo um padrão ativo (o tipo inativo segue encobrindo, e o nome sumiria da escolha);
  - com fatura no período, ou criado no período.
- **Tudo ou nada:** uma transação com os tipos travados (`FOR UPDATE`). A trava segura também a gravação de uma fatura
  no tipo até o fim (a chave estrangeira confere o tipo). Um recusado desfaz todos (422, `_form` e `_invoice_type`). O
  `UPDATE` exige `status = 'active'`; se não mudar uma linha, 409.
- **`dry_run`:** passa por tudo e desfaz, sem auditoria.
- **Auditoria:** um `update` por tipo (status, meses, última competência, motivo e tamanho do lote), só quando grava.
- **Limites:** até 500 por vez; motivo de 5 a 255.

### Acesso e dados

- **Permissão:** `erp.access`, `erp.invoice_types.view`, `erp.invoice_types.manage` e `erp.invoices.view`. Sem uma
  delas, 403 (fail-closed). CSRF pelo `ApiAuthenticated`.
- **Escopo (ADR-002):** fora do escopo, 404, antes de consultar. Entrada inválida, 422.
- **Dados que saem:** ids, recorrência e a última competência, mais `skipped_draft`, `skipped_overriding`,
  `by_recurrence`, `count` e `changed`. Não saem nome de tipo, conta, valor nem observação.
- **Nada é apagado.** As faturas já preparadas continuam apontando o tipo.
- **Não toca** o login, o 2FA, o Studio, o portal do morador, o e-mail, o app nem a sessão por token.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\InvoiceTypeDeactivationTest`:**
  - a regra pura nos limites do período e da criação;
  - a validação e o escopo;
  - as recusas, uma por caso, com tudo desfeito;
  - a prévia e a inativação só dos próprios sem uso, com os em preparação e os que encobrem contados à parte;
  - padrão, faturas e outro condomínio intactos, sem apagar;
  - a situação da 0296 depois;
  - o contrato, o controller, a rota e a OpenAPI.
- **`Integration\InvoiceTypeDeactivationTest`** (MariaDB, banco `*_test`): o mês do banco, a prévia sem auditoria, a
  recusa do padrão, a inativação com um registro por tipo e as faturas intactas.
- **Corrida conhecida:** a preparação de fatura confere o tipo ativo antes de gravar, sem trava. Se a inativação terminar
  entre a conferência e a gravação, a fatura nasce num tipo inativo. É o mesmo comportamento da inativação pelo cadastro.
- **Sem tela nova neste patch**, então sem artigo da Central de Ajuda.
