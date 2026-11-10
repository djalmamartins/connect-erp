-- Aplicação financeira como ativo separado da conta corrente (ADR 0170). A conta bancária de tipo
-- 'aplicacao' (que já existe desde 20261108_0504) pode ser ligada à conta corrente de onde sai e para onde
-- volta o dinheiro. Aplicar e resgatar são transferências entre as duas (erp_bank_transfers, AP-029): não
-- passam pela DRE e não mudam o saldo total. O rendimento é receita e o IR/IOF é despesa, lançados na
-- conta de aplicação.
-- Sem FOREIGN KEY: o vínculo (mesmo condomínio, aplicação → conta corrente) é validado pelo service
-- (Investments). Idempotente.
ALTER TABLE `erp_bank_accounts`
  ADD COLUMN IF NOT EXISTS `parent_bank_account_id` int unsigned DEFAULT NULL COMMENT 'Aplicação: conta corrente ligada (ADR 0170)' AFTER `kind`,
  ADD INDEX IF NOT EXISTS `idx_erp_bank_accounts_parent` (`parent_bank_account_id`);
