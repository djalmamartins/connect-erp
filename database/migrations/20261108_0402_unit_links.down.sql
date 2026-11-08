-- Reverte 20261108_0402: remove unit_id das cinco tabelas. unit_label nunca foi alterado,
-- então nada se perde. As unidades criadas a partir dos textos saem com o .down da 0401.
ALTER TABLE `erp_residents` DROP FOREIGN KEY IF EXISTS `fk_erp_residents_unit`, DROP INDEX IF EXISTS `idx_erp_residents_unit`, DROP COLUMN IF EXISTS `unit_id`;
ALTER TABLE `erp_board_members` DROP FOREIGN KEY IF EXISTS `fk_erp_board_members_unit`, DROP INDEX IF EXISTS `idx_erp_board_members_unit`, DROP COLUMN IF EXISTS `unit_id`;
ALTER TABLE `erp_vehicles` DROP FOREIGN KEY IF EXISTS `fk_erp_vehicles_unit`, DROP INDEX IF EXISTS `idx_erp_vehicles_unit`, DROP COLUMN IF EXISTS `unit_id`;
ALTER TABLE `erp_visitors` DROP FOREIGN KEY IF EXISTS `fk_erp_visitors_unit`, DROP INDEX IF EXISTS `idx_erp_visitors_unit`, DROP COLUMN IF EXISTS `unit_id`;
ALTER TABLE `erp_pets` DROP FOREIGN KEY IF EXISTS `fk_erp_pets_unit`, DROP INDEX IF EXISTS `idx_erp_pets_unit`, DROP COLUMN IF EXISTS `unit_id`;
