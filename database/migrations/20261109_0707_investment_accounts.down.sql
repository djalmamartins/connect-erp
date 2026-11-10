-- Reverte 20261109_0707: tira o vínculo entre a aplicação e a conta corrente. As contas, as transferências
-- e os saldos não mudam: só o vínculo deixa de existir.
ALTER TABLE `erp_bank_accounts`
  DROP INDEX IF EXISTS `idx_erp_bank_accounts_parent`,
  DROP COLUMN IF EXISTS `parent_bank_account_id`;
