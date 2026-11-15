-- Reverte 20261115_2330: tira a marca de unidade vazia.
ALTER TABLE `erp_units` DROP COLUMN IF EXISTS `is_vacant`;
