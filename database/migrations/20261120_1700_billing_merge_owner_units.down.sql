-- Desfaz 20261120_1700_billing_merge_owner_units.sql (ADR 0399).
ALTER TABLE `erp_billing_rules` DROP COLUMN IF EXISTS `merge_owner_units`;
