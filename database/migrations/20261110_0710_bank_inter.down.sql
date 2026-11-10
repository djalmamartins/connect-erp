DROP TABLE IF EXISTS `erp_bank_webhook_deliveries`;
DROP TABLE IF EXISTS `erp_bank_credentials`;
ALTER TABLE `erp_bank_charges`
  DROP INDEX IF EXISTS `idx_erp_bank_charges_external`,
  DROP COLUMN IF EXISTS `pix_copy_paste`,
  DROP COLUMN IF EXISTS `pix_txid`,
  DROP COLUMN IF EXISTS `bank_our_number`;
ALTER TABLE `erp_bank_accounts` DROP COLUMN IF EXISTS `adapter`;
