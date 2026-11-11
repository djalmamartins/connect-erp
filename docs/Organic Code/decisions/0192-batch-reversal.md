# 0192 — Estorno em lote da baixa

- **Status:** aceito
- **Data:** 2026-11-11
- **Relacionados:** ADR 0191 (baixa em lote), 0031 (fechamento mensal), 0057 (recibo e trava de recibo), 0058
  (extrato e conciliação), 0035 (boletos e adaptadores bancários)

## Contexto

Com a baixa em lote (ADR 0191), um lote errado (data errada, conta bancária errada, contas trocadas) também precisa
voltar em lote. Hoje o estorno é feito conta a conta, pelo formulário, apagando "pago em". Além de lento, o formulário
não olha a conciliação do extrato nem o boleto pago pelo banco.

## Decisão

Rota nova: `POST /api/v1/entries/unsettle` `{condominium_id, entry_ids[1..200], reason, dry_run?}`. O service é
`BatchReversal`.

### Regras

Cada conta precisa estar no condomínio, válida e paga, e não pode:

- estar em competência fechada (ADR 0031); mês reaberto pode;
- ter recibo válido (`RECEIPT_LOCK`, ADR 0057), como no formulário: cancela-se o recibo antes. Recibo cancelado não
  trava;
- estar conciliada com uma linha de extrato importado (`erp_bank_statement_lines.status = matched`, ADR 0058). A linha do
  banco ficaria apontando para uma conta em aberto, e o lote não desfaz conciliação;
- ter boleto que o banco confirmou como pago (`erp_bank_charges.status = paid`). O banco diz que o dinheiro entrou. Isto
  é só uma leitura da tabela de boletos; nada do fluxo do banco muda (AP-031/AP-037 ficam com a frente do Inter).

Além disso:

- **Motivo:** obrigatório, de 5 a 255 caracteres, e vai para a auditoria de cada conta.
- **Conta bancária:** fica no lançamento como sugestão, como no formulário.
- **Lote:** de 1 a 200 contas; ids repetidos contam uma vez (`BatchSettlement::ids`).

### Tudo ou nada

- **Transação:** com as contas travadas (`FOR UPDATE`) e o `UPDATE` condicionado à baixa conferida (mesmo `paid_on`).
- **Problema em alguma conta:** o lote inteiro é recusado com 422 `reversal_rejected` e a lista `problems`, que dá o
  motivo de cada conta: `not_found`, `cancelled`, `not_paid`, `closed_competence`, `receipt`, `reconciled`,
  `bank_confirmed` ou `changed`. Nada é gravado.
- **`dry_run`:** confere e devolve as contas, a baixa de cada uma e os totais, sem gravar.

### Auditoria, permissões e escopo

- **Auditoria:** um `update` em `erp_entries` por conta, com o `paid_on` de antes e o motivo.
- **Permissão:** exige `erp.access`, `erp.entries.view` e `erp.entries.manage`, as mesmas da baixa.
- **CSRF:** pelo `ApiAuthenticated`.
- **Escopo:** fora do escopo dá 404 antes de consultar. Entradas inválidas dão 422 `validation_failed`.
- **Consultas:** parametrizadas; a lista de ids usa marcadores `?`.

## Consequências

- **Testes.** `BatchReversalTest` (unit, SQLite; `Pdo\Sqlite` quando existe, `PDO` no PHP 8.2; o PDO de teste tira o
  `FOR UPDATE`) confere:
  - o `dry_run` sem gravar e o estorno de receita e despesa juntas, com a conta bancária mantida;
  - que recibo cancelado, boleto cancelado e mês reaberto não travam;
  - a recusa do lote com cada motivo;
  - a corrida, que desfaz tudo;
  - as validações de motivo e de lista, o escopo, o controller e a rota.
- **Rota e documentação no patch:** `index.php` e OpenAPI.
- **Sem migration nem tabela nova.**
- **Sem tela nova neste patch.** O botão "Estornar selecionadas" fica para a frente de layout, junto do "Baixar
  selecionadas".
- **Lacuna anotada, fora deste patch:** o estorno pelo formulário (`RegistryService`) também deveria recusar conta
  conciliada ou com boleto pago pelo banco. Mudar o formulário é de outro ciclo, com aprovação.
