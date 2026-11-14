-- Reverte 20261114_0901: tira as funções novas da matriz (as linhas delas somem) e a marca de documento visível ao morador.
DELETE FROM `erp_resident_portal_permissions` WHERE `feature` IN ('accountability','deliveries','visitors','assemblies');
ALTER TABLE `erp_resident_portal_permissions`
  MODIFY COLUMN `feature` enum('boletos','reservations','occurrences','announcements','documents') NOT NULL;
ALTER TABLE `erp_documents` DROP INDEX IF EXISTS `idx_erp_documents_shared`, DROP COLUMN IF EXISTS `shared_with_residents`;
