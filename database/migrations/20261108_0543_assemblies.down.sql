-- Reverte 20261108_0543: remove assembleias, pauta, presença, votos e as permissões. Os comunicados do
-- edital (erp_announcements) continuam.
DROP TABLE IF EXISTS `erp_assembly_votes`;
DROP TABLE IF EXISTS `erp_assembly_attendance`;
DROP TABLE IF EXISTS `erp_assembly_items`;
DROP TABLE IF EXISTS `erp_assemblies`;
DELETE FROM `access_permissions` WHERE `slug` IN ('erp.assemblies.view','erp.assemblies.manage');
