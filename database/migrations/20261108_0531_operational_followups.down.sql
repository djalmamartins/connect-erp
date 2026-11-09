-- Reverte 20261108_0531: as manutenções geradas e as inscrições continuam, só perdem o vínculo.
ALTER TABLE `erp_maintenances`
  DROP FOREIGN KEY IF EXISTS `fk_erp_maintenances_previous`,
  DROP INDEX IF EXISTS `uq_erp_maintenances_previous`,
  DROP COLUMN IF EXISTS `previous_id`;
ALTER TABLE `erp_waitlist`
  DROP FOREIGN KEY IF EXISTS `fk_erp_waitlist_called_for`,
  DROP FOREIGN KEY IF EXISTS `fk_erp_waitlist_called_by`,
  DROP COLUMN IF EXISTS `called_for_reservation_id`,
  DROP COLUMN IF EXISTS `called_by`,
  DROP COLUMN IF EXISTS `called_at`;
