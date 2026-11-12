<!-- markdownlint-disable MD024 -->
# 0253 — Liquidação em lote dos empenhos com o lançamento já pago

- **Status:** aceito
- **Data:** 2026-11-12
- **Relacionados:**
  - 0238 (posição dos empenhos);
  - 0239 (cancelamento em lote dos empenhos, mesmo desenho);
  - cadastro de empenhos (AP-128), só como cadastro reusado.

## Contexto

O cadastro de empenhos (AP-128) reserva a despesa antes do pagamento e pede que se liquide "informando a data e, se
houver, o lançamento". A posição dos empenhos (ADR 0238) mostra quanto segue empenhado. O cancelamento em lote (ADR 0239)
deixa de fora os empenhos com lançamento ligado, porque o pagamento aconteceu e o certo é liquidar.

Hoje cada um é liquidado à mão no formulário, copiando a data do pagamento do lançamento. Enquanto ninguém lembra, o
valor empenhado fica inflado.

Busquei `erp_commitments` em `source/`, no README das ADRs e nos handoffs 02xx. Só existem o cadastro, a posição
(somente leitura), o cancelamento em lote e a desativação de fornecedores (que só confere os empenhos). Nenhuma ação
liquida em lote.

## Decisão

Rota nova: `POST /api/v1/commitments/settle-paid {condominium_id, commitment_ids?, dry_run?}`. O service é
`CommitmentSettlement`, em `Source\Services\Erp`.

### Reuso

- **Gravação:** cada empenho é regravado pelo `RegistryService::save` do cadastro "commitments", com a situação
  "Liquidado" e `settled_on` igual a `paid_on` do lançamento ligado. Valem as regras, as referências e a auditoria update
  do formulário. O valor vai em reais, como no formulário.
- **Mesmo desenho do cancelamento em lote (ADR 0239):**
  - a mesma regra de fornecedor ativo e documento no Disco Virtual;
  - a mesma transação com FOR UPDATE;
  - a mesma resposta de recusa.
- **O lançamento não é alterado.**

### Regra pura `problem()`

Recusa o empenho:

- não encontrado no condomínio;
- que não está em aberto;
- sem lançamento ligado;
- com o lançamento cancelado, removido ou de outro condomínio;
- com o lançamento ainda não pago;
- pago antes da data do empenho (o formulário não aceita);
- com fornecedor inativo ou documento removido.

Sem ids, os que não passam ficam de fora e são contados:

- `awaiting_payment`: lançamento em aberto;
- `skipped_entry`: lançamento inválido ou pago antes do empenho;
- `skipped_inactive_link`: fornecedor inativo ou documento removido.

Com ids, um recusado desfaz todos (`_form` e `_commitment`).

### Execução

- **Tudo ou nada:** uma transação com os empenhos travados; `dry_run` passa por tudo e desfaz.
- **Auditoria:** a update do formulário e um registro `settle` por empenho (data, lançamento e valores), só quando grava.
- **Resposta:**
  - por empenho, `id`, `committed_on`, `settled_on`, `entry_id`, `amount_cents` e `entry_amount_cents`;
  - `count`, `changed`, `amount_cents`, `entry_amount_cents` e `difference_cents`, que é a diferença entre o pago e o
    empenhado, somada dos empenhos.

### Acesso e dados

- **Permissão:** exige `erp.access`, `erp.commitments.view` e `erp.commitments.manage`, as do cadastro. Exige também
  `erp.entries.view`, porque a data e o valor do pagamento vêm do lançamento. Sem uma delas, 403 (fail-closed).
- **Escopo (ADR-002):** fora do escopo, 404. Entrada inválida ou empenho recusado, 422, e nada é gravado.
- **CSRF:** pelo `ApiAuthenticated`.
- **Dados que saem:** não saem descrição, fornecedor, documento ou observação. Nenhum aviso é enviado.
- **Sem migration e sem tabela nova.** Nenhum arquivo existente foi alterado além da rota e da OpenAPI.

## Consequências

- **`Unit\CommitmentSettlementTest`:**
  - a regra pura;
  - a validação e o escopo;
  - as recusas antes do formulário, uma por caso;
  - as contagens dos que ficam de fora sem ids;
  - o contrato de reuso, o controller e a rota.
- **`Integration\CommitmentSettlementTest`** (MariaDB, banco `*_test`):
  - a prévia e a gravação pelo cadastro na data do pagamento, com o valor, o lançamento e a diferença;
  - o lançamento sem mudança;
  - as auditorias `settle` e update;
  - nenhum aviso e nenhum dado da descrição;
  - um segundo lote vazio;
  - tudo ou nada com ids e o escopo.
- **Sem tela nova neste patch.** A posição dos empenhos pode ganhar o botão "Liquidar os pagos"; quando ganhar, vem o
  artigo da Central de Ajuda.
