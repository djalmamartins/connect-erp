-- Reverte 20261108_0509: os lançamentos gerados continuam, só perdem o vínculo com o modelo.
ALTER TABLE `erp_entries`
  DROP FOREIGN KEY IF EXISTS `fk_erp_entries_recurring`,
  DROP INDEX IF EXISTS `uq_erp_entries_recurring_competence`,
  DROP COLUMN IF EXISTS `recurring_entry_id`;
