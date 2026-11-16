-- Reverte 20261116_0230: tira o pagador escolhido das contas a receber.
ALTER TABLE `erp_entries` DROP FOREIGN KEY IF EXISTS `fk_erp_entries_payer`;
ALTER TABLE `erp_entries` DROP KEY IF EXISTS `idx_erp_entries_payer`, DROP COLUMN IF EXISTS `payer_resident_id`;
