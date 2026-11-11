# 0193 — Estorno pelo formulário respeita conciliação e boleto pago

- **Status:** aceito (aprovado pelo coordenador em 2026-11-11)
- **Data:** 2026-11-11
- **Relacionados:** ADR 0192 (estorno em lote), 0058 (extrato e conciliação), 0035 (boletos), 0057 (trava de recibo),
  0100 (competência fechada na transação)

## Contexto

O estorno em lote (ADR 0192) recusa devolver para "em aberto" uma conta conciliada com o extrato importado ou com boleto
que o banco confirmou como pago. O estorno conta a conta pelo formulário (`RegistryService::save`, `paid_on` para vazio)
não tinha essa trava. Por isso o mesmo estorno passava por um caminho e não pelo outro, e deixava:

- a linha do extrato apontando para uma conta em aberto;
- ou um boleto pago com a conta em aberto.

## Decisão

- **Helper compartilhado.** `PaymentLocks::find(PDO, ids)` devolve, por conta, o motivo da trava: `reconciled`
  (linha de extrato `matched`) ou `bank_confirmed` (boleto `paid`). A conciliação vem primeiro. As mensagens ficam em
  `PaymentLocks::RECONCILED` e `PaymentLocks::BANK_CONFIRMED`.
  - O `BatchReversal` passa a usar o helper; a consulta mora só nele.
  - O texto da conciliação ficou neutro ("o estorno não desfaz a conciliação"), para servir aos dois caminhos.
- **Formulário.** No `RegistryService::save`, para cadastros da tabela `erp_entries` (lançamentos e as visões de
  contas a pagar e a receber), a gravação é recusada com o erro no campo `paid_on` quando:
  - a conta estava paga;
  - a entrada esvazia `paid_on`;
  - e o `PaymentLocks` acha uma trava.

  A conferência fica junto das travas de origem e de recibo, antes da transação.
- **O que continua livre:** mudar a data ou a conta bancária da baixa, que não é estorno. Também continua livre
  estornar conta sem conciliação e sem boleto pago (boleto cancelado ou só registrado não trava).
- **Sem migration, sem rota nova, sem tela nova.** A mensagem aparece no formulário pelo caminho de erro de validação
  que já existe.

## Consequências

- **Testes:**
  - `PaymentLocksTest` (unit, SQLite) confere os motivos e a ordem: linha ignorada, boleto cancelado e boleto
    registrado não travam. Confere também que o formulário e o lote usam a mesma regra.
  - `EntryReversalLocksTest` (integration, MariaDB) confere pelo formulário real:
    - conta conciliada recusada com a mensagem, e a mudança de data da baixa permitida;
    - boleto pago recusado;
    - conta comum estornando como antes.
  - O `BatchReversalTest` continua igual nos códigos.
- **Ajuste nos testes do 0191 e do 0192.** O PDO de teste deles passa a tirar também o `LOCK IN SHARE MODE` da leitura
  da competência fechada (ADR 0100). Na base 5e540f5 essa leitura não olha o driver; na main atual (373d9be) ela só
  trava no MySQL.
- **Fora de escopo:** excluir (exclusão lógica, `deleted_at`) ou cancelar uma conta conciliada ou com boleto pago pelo
  formulário. A chave estrangeira não barra a exclusão lógica, então essa lacuna fica anotada para outro ciclo, com
  aprovação.
