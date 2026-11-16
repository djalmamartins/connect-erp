-- Reverte 20261116_2300: os boletos continuam com a conta principal; as contas a mais perdem o vínculo.
DROP TABLE IF EXISTS `erp_bank_charge_entries`;
ALTER TABLE `erp_entries`
  DROP FOREIGN KEY IF EXISTS `fk_erp_entries_bundle_invoice_type`,
  DROP INDEX IF EXISTS `idx_erp_entries_bundle`,
  DROP COLUMN IF EXISTS `bundle_invoice_type_id`;
