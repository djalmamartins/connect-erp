-- Reversão da ADR 0653: as propostas cadastradas no ERP continuam na tabela, só perdem a origem e quem cadastrou.
-- proposal_responses e as colunas de anexo do mail_queue não são removidas: já existiam antes desta migration.
ALTER TABLE `proposals` DROP COLUMN IF EXISTS `min_monthly_cents`;
ALTER TABLE `proposals` DROP COLUMN IF EXISTS `price_syndic_unit_cents`;
ALTER TABLE `proposals` DROP COLUMN IF EXISTS `price_admin_unit_cents`;
ALTER TABLE `proposals` DROP COLUMN IF EXISTS `created_by`;
ALTER TABLE `proposals` DROP COLUMN IF EXISTS `origin`;
-- Devolve proposals.manage ao Gerente, como o módulo antigo do Studio fazia.
INSERT IGNORE INTO `access_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p ON p.slug = 'proposals.manage' WHERE r.slug = 'manager';
