-- Reverte 20261108_0514: os lançamentos continuam, só perdem a unidade e o vínculo com a fatura.
ALTER TABLE `erp_entries`
  DROP FOREIGN KEY IF EXISTS `fk_erp_entries_invoice_item`,
  DROP FOREIGN KEY IF EXISTS `fk_erp_entries_unit`,
  DROP INDEX IF EXISTS `uq_erp_entries_invoice_item`,
  DROP INDEX IF EXISTS `idx_erp_entries_unit`,
  DROP COLUMN IF EXISTS `invoice_item_id`,
  DROP COLUMN IF EXISTS `unit_id`;
