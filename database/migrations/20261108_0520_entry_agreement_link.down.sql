-- Reverte 20261108_0520: os lançamentos continuam, só perdem o vínculo com o acordo.
ALTER TABLE `erp_entries`
  DROP FOREIGN KEY IF EXISTS `fk_erp_entries_agreement`,
  DROP INDEX IF EXISTS `uq_erp_entries_agreement_installment`,
  DROP INDEX IF EXISTS `idx_erp_entries_agreement`,
  DROP COLUMN IF EXISTS `agreement_installment`,
  DROP COLUMN IF EXISTS `agreement_role`,
  DROP COLUMN IF EXISTS `agreement_id`;
