-- Reverte 20261122_1400: tira o percentual definido das unidades e o registro do rateio das faturas.
ALTER TABLE `erp_invoices`
  DROP COLUMN IF EXISTS `requested_cents`, DROP COLUMN IF EXISTS `participant_blocks`, DROP COLUMN IF EXISTS `participants`, DROP COLUMN IF EXISTS `method`;
ALTER TABLE `erp_units` DROP COLUMN IF EXISTS `apportion_percent`;
