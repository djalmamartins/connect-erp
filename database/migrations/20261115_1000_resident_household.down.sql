-- Reverte 20261115_1000: tira "household" da matriz do portal (as linhas dela somem).
DELETE FROM `erp_resident_portal_permissions` WHERE `feature` = 'household';
ALTER TABLE `erp_resident_portal_permissions`
  MODIFY COLUMN `feature` enum('boletos','reservations','occurrences','announcements','documents','accountability','deliveries','visitors','assemblies') NOT NULL;
