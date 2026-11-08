-- Reverte 20261108_0404: remove unit_id de erp_linkable_areas. unit_label nunca foi alterado.
-- As unidades criadas a partir dos textos ficam (podem estar em uso por outros cadastros).
ALTER TABLE `erp_linkable_areas` DROP FOREIGN KEY IF EXISTS `fk_erp_linkable_areas_unit`, DROP INDEX IF EXISTS `idx_erp_linkable_areas_unit`, DROP COLUMN IF EXISTS `unit_id`;
