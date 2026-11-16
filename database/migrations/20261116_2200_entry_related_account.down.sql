-- Reverte 20261116_2200: os lançamentos continuam, só perdem a conta relacionada.
ALTER TABLE `erp_entries`
  DROP FOREIGN KEY IF EXISTS `fk_erp_entries_related_account`,
  DROP INDEX IF EXISTS `idx_erp_entries_related_account`,
  DROP COLUMN IF EXISTS `related_account_id`;
