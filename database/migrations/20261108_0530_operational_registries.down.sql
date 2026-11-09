-- Reverte 20261108_0530: remove as tabelas do Operacional e as permissões (chamados e arquivos continuam).
DELETE FROM `access_permissions` WHERE `slug` IN ('erp.maintenances.view','erp.maintenances.manage','erp.occurrences.view','erp.occurrences.manage',
  'erp.reservations.view','erp.reservations.manage','erp.insurances.view','erp.insurances.manage','erp.contracts.view','erp.contracts.manage',
  'erp.moves.view','erp.moves.manage');
DROP TABLE IF EXISTS `erp_move_settings`;
DROP TABLE IF EXISTS `erp_moves`;
DROP TABLE IF EXISTS `erp_contracts`;
DROP TABLE IF EXISTS `erp_insurances`;
DROP TABLE IF EXISTS `erp_waitlist`;
DROP TABLE IF EXISTS `erp_reservations`;
DROP TABLE IF EXISTS `erp_occurrences`;
DROP TABLE IF EXISTS `erp_maintenances`;
