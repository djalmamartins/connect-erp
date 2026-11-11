<!-- markdownlint-disable MD024 -->
# 0214 — Conferência das cópias de cheques

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0050 (documentos operacionais: cópias de cheques), 0022 (lançamentos), 0203 (cancelamento de
  lançamento)

## Contexto

O cadastro de cópias de cheques (AP-127) guarda os cheques emitidos pelo condomínio, com a imagem no Disco Virtual e,
quando houver, o lançamento pago com cada um. Fora o próprio cadastro, nada usa esses dados: ninguém confere se o
cheque bate com o lançamento, nem se falta cheque na sequência do talão.

Cheque sem registro no talão, cheque com valor diferente do lançamento ou cheque cancelado com o lançamento dado como
pago são exatamente o que o conselho fiscal procura na prestação de contas. Hoje, isso é conferido à mão, folha por
folha.

## Decisão

Rota nova, somente leitura: `GET /api/v1/reports/check-copy-review?condominium_id=`. O service é `CheckCopyReview`.

### Regras puras

- **`gaps()`:** a sequência por conta bancária.
  - Lista os números que faltam entre o primeiro e o último cheque registrado. Os cheques emitidos e os cancelados
    preenchem a sequência.
  - Lista até 100 números, com a contagem total.
- **`issues()`:** os problemas do cheque com o lançamento ligado.
  - **Cheque emitido:**
    - `no_entry`: sem lançamento;
    - `entry_missing`: o lançamento é de outro condomínio ou foi apagado;
    - `entry_cancelled`;
    - `not_expense`: o lançamento é de receita;
    - `amount_mismatch`: valor diferente;
    - `other_account`: o lançamento foi pago por outra conta;
    - `entry_unpaid`: o lançamento ainda está em aberto.
  - **Cheque cancelado** ligado a lançamento válido e pago: `cancelled_but_paid`.

### Reuso

- Usa os mesmos cadastros (`erp_check_copies`, `erp_entries`, `erp_bank_accounts`), só com leitura.
- O lançamento entra pela junção com o mesmo condomínio do cheque.
- A correção continua sendo pelo formulário de cada cadastro. Para cancelar um lançamento, use o `EntryCancellation`.
- Sem tabela nova, sem migration e nenhum arquivo existente alterado além da rota e da OpenAPI.

### Acesso e dados

- **Escopo (ADR-002):** um condomínio do escopo, obrigatório; fora dele, 404; entrada inválida, 422.
- **Permissão:** exige `erp.access`, `erp.check_copies.view` e `erp.entries.view`; sem uma delas, 403
  (fail-closed).
- **Somente leitura:** não grava, não audita e não envia nada.
- **Dados que saem:** número, data, valor, situação, ids e o nome da conta bancária do condomínio. Nenhum favorecido
  (pode ser pessoa física).
- **Limites:** até 5000 cheques por condomínio (acima disso, 422) e até 500 itens com problema (`truncated`).

## Consequências

- **`Unit\CheckCopyReviewTest`:**
  - as duas regras puras, incluindo o limite da lista de números;
  - a montagem no SQLite, sem favorecido nem dado de outro condomínio;
  - validação e escopo (404);
  - contrato: somente leitura;
  - controller e rota.
- **`Integration\CheckCopyReviewTest`** (MariaDB, banco `*_test`):
  - cheques e lançamentos criados pelo formulário;
  - os números que faltam;
  - valor diferente, lançamento em aberto, cheque sem lançamento e lançamento cancelado;
  - nenhuma linha nova na auditoria;
  - escopo.
- **Sem tela nova neste patch.** A tela das cópias de cheques pode ganhar o botão "Conferir"; quando ganhar, vem o
  artigo da Central de Ajuda.
