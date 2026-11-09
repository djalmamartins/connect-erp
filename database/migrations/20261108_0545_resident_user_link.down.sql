-- Reverte 20261108_0545: desfaz a ligação morador ↔ conta. As contas criadas continuam.
ALTER TABLE `erp_residents` DROP FOREIGN KEY IF EXISTS `fk_erp_residents_user`;
ALTER TABLE `erp_residents` DROP INDEX IF EXISTS `uq_erp_residents_user`;
ALTER TABLE `erp_residents` DROP COLUMN IF EXISTS `user_id`;
