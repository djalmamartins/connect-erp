-- Reverte 20261116_0030: tira as configurações de cobrança e o CIB das unidades.
ALTER TABLE `erp_units`
  DROP COLUMN IF EXISTS `partner_agency_id`, DROP COLUMN IF EXISTS `bill_email_to`, DROP COLUMN IF EXISTS `bill_address`,
  DROP COLUMN IF EXISTS `billing_responsible`, DROP COLUMN IF EXISTS `block_bill_merge`, DROP COLUMN IF EXISTS `reads_power`,
  DROP COLUMN IF EXISTS `reads_water`, DROP COLUMN IF EXISTS `reads_gas`, DROP COLUMN IF EXISTS `postage_payer`,
  DROP COLUMN IF EXISTS `postage_cents`, DROP COLUMN IF EXISTS `apportionment_exempt`, DROP COLUMN IF EXISTS `due_day`, DROP COLUMN IF EXISTS `cib`;
