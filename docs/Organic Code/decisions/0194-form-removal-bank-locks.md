# 0194 — Exclusão e cancelamento pelo formulário respeitam conciliação e boleto pago

- **Status:** aceito (aprovado pelo coordenador em 2026-11-11)
- **Data:** 2026-11-11
- **Relacionados:** ADR 0193 (estorno pelo formulário), 0192 (estorno em lote), 0058 (extrato e conciliação), 0035
  (boletos), 0057 (trava de recibo)

## Contexto

O ADR 0193 travou o estorno pelo formulário de conta conciliada com o extrato ou com boleto pago pelo banco. Ficaram
abertos dois caminhos pelo mesmo formulário:

- **Excluir a conta** (exclusão lógica, `deleted_at`). A chave estrangeira da linha do extrato não barra o `UPDATE`.
- **Cancelar a conta** (`status = inactive`).

Nos dois casos, a linha do extrato ou o boleto pago ficariam apontando para uma conta que sumiu dos relatórios, e o
saldo bancário do sistema deixaria de bater com o banco.

## Decisão

- **`PaymentLocks::removal(PDO, id)`:** devolve o mesmo motivo do `find` (`reconciled` ou `bank_confirmed`), com
  mensagens próprias de remoção:
  - "Conta conciliada com o extrato bancário: não pode ser excluída nem cancelada enquanto a conciliação existir."
  - "O banco confirmou o pagamento do boleto desta conta: ela não pode ser excluída nem cancelada."
- **Cancelamento** (`RegistryService::save`, tabela `erp_entries`, `status` de `active` para `inactive`): erro de
  validação em `_form` com a mensagem, como a trava de recibo faz com a situação. Editar os outros campos continua livre.
- **Exclusão** (`RegistryService::delete`, tabela `erp_entries`): exceção nova `RegistryPaymentLocked` (com `reason`),
  logo depois da trava de recibo.
  - A API (`Api\V1\Registry::destroy`) responde 409 `payment_locked`, com `reason` e a mensagem.
  - A tela (`Erp\Connect\Registry`) volta ao registro com a mensagem (PRG), como já faz com `RegistryLocked`. Só o
    controller muda; HTML e JS ficam iguais.
- **Conta sem trava:** cancela e exclui como antes.

## Consequências

- **Testes:**
  - `PaymentLocksTest` (unit) ganha o `removal` e confere o formulário e os dois controllers.
  - `EntryRemovalLocksTest` (integração, MariaDB) confere:
    - conta conciliada não cancela nem exclui, e a edição comum passa;
    - boleto pago, idem;
    - conta comum cancela e exclui.
- **Sem migration, sem rota nova, sem tela nova.**
- **Fora de escopo:** os cancelamentos de módulos próprios (fatura, acordo, cobrança extra, transferência) já têm as
  regras deles e não passam pelo formulário de lançamentos.
