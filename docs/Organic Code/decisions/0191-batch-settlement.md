# 0191 — Baixa em lote

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0022 (lançamentos), 0024 (contas a pagar e a receber), 0031 (fechamento mensal), 0057
  (atalhos do lançamento), 0033/0039 (trava de origem)

## Contexto

O dia da administradora termina com a baixa: as contas a pagar que saíram do banco hoje e as receitas que apareceram
no extrato. Hoje isso é feito lançamento por lançamento, abrindo o formulário, preenchendo "pago em" e a conta bancária
e salvando. Com dezenas de contas por condomínio, isso é lento e dá erro: uma data errada, uma conta bancária errada ou
uma conta esquecida. Os últimos ciclos foram relatórios. Esta é uma operação do dia a dia que ainda não tinha API.

## Decisão

Rota nova: `POST /api/v1/entries/settle` `{condominium_id, bank_account_id, paid_on, entry_ids[], dry_run?}`. O service
é `BatchSettlement`.

### Regras (as mesmas da baixa pelo formulário)

- **Contas:** cada conta é do condomínio, válida (não cancelada nem excluída) e está em aberto.
- **Competência:** a competência da conta não está fechada (ADR 0031). Mês reaberto não trava.
- **Conta bancária:** ativa, não excluída e do condomínio.
- **Data do pagamento:** não pode ser futura nem anterior à abertura da conta bancária. Assim o saldo do Balanço não
  fica com pagamento antes da abertura.
- **Trava de origem:** conta gerada por fatura ou acordo pode ser baixada; essa trava protege só os outros campos.
- **Valor:** a conta é baixada pelo valor dela. Juros, multa e desconto continuam pelos atalhos do lançamento (ADR 0057).
- **Lote:** de 1 a 200 contas; ids repetidos contam uma vez.

### Tudo ou nada

- **Transação:** a baixa roda numa transação, com as contas travadas (`FOR UPDATE`).
- **Problema em alguma conta:** o lote inteiro é recusado com 422 `settlement_rejected` e a lista `problems`, que diz,
  conta a conta, o motivo (`not_found`, `cancelled`, `already_paid` ou `closed_competence`). Nada é gravado.
- **Baixa concorrente:** o `UPDATE` só grava onde `paid_on IS NULL`. Se outra pessoa baixou uma conta entre a
  conferência e a gravação, o lote é desfeito (rollback) e recusado.
- **`dry_run: true`:** confere e devolve as contas e os totais (receita, despesa e líquido) sem gravar. É para a tela
  mostrar o resumo antes de confirmar.

### Auditoria

Cada conta baixada é auditada como `update` em `erp_entries`, com `paid_on` e `bank_account_id` antes e depois e o
tamanho do lote. É o mesmo registro da edição pelo formulário, então o histórico do lançamento fica igual.

### Permissões e escopo

- **Permissão:** exige `erp.access`, `erp.entries.view` e `erp.entries.manage`, as mesmas da baixa pelo formulário.
- **CSRF:** pelo `ApiAuthenticated`.
- **Escopo:** um condomínio, obrigatório; fora do escopo dá 404 antes de consultar.
- **Entradas inválidas:** dão 422 `validation_failed`.
- **Consultas:** parametrizadas; a lista de ids usa marcadores `?`.

### Fora de escopo

- **Estorno em lote:** o estorno continua conta a conta, pelo formulário, por causa da trava de recibo (ADR 0057).
- **Baixa com valor diferente:** pagamento parcial ou com juros também fica fora.

## Consequências

- **Testes.** `BatchSettlementTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2; o PDO de teste tira o
  `FOR UPDATE`) confere:
  - o `dry_run` sem gravar, com os totais;
  - a baixa de receita e despesa juntas, trocando a conta sugerida pela do pagamento;
  - a recusa do lote com os motivos de cada conta: paga, cancelada, excluída, mês fechado, outro condomínio e
    inexistente. Mês reaberto e fechamento de outro condomínio não travam;
  - a corrida, que desfaz tudo;
  - as validações de conta bancária (inativa, excluída, de outro condomínio, aberta depois do pagamento), de data e de
    lista;
  - o escopo, o controller e a rota.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration nem tabela nova.**
- **Sem tela nova neste patch.** O botão "Baixar selecionadas" na lista de contas a pagar e a receber fica para a
  frente de layout (sem mudar HTML/JS aqui). Quando a tela existir, ganha o artigo na Central de Ajuda.
