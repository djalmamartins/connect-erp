-- Reverte 20261109_0701: tira os índices por data de pagamento. A FK de bank_account_id segue
-- coberta por idx_erp_entries_bank_account.
ALTER TABLE `erp_entries`
  DROP INDEX IF EXISTS `idx_erp_entries_paid`,
  DROP INDEX IF EXISTS `idx_erp_entries_bank_paid`;
