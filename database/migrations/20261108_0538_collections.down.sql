-- Reverte 20261108_0538: remove o atendimento de cobrança, os casos judiciais e a origem das multas.
-- As multas e as contas continuam.
DROP TABLE IF EXISTS `erp_legal_case_events`;
DROP TABLE IF EXISTS `erp_legal_case_entries`;
DROP TABLE IF EXISTS `erp_legal_cases`;
DROP TABLE IF EXISTS `erp_collection_contacts`;
ALTER TABLE `erp_extra_charges`
  DROP FOREIGN KEY IF EXISTS `fk_erp_extra_charges_reservation`,
  DROP FOREIGN KEY IF EXISTS `fk_erp_extra_charges_occurrence`,
  DROP COLUMN IF EXISTS `reservation_id`,
  DROP COLUMN IF EXISTS `occurrence_id`;
DELETE FROM `access_permissions` WHERE `slug` IN ('erp.collections.view','erp.collections.manage');
