-- Reverte 20261120_2200_my_day (ADR 0630). Os chamados já abertos continuam no SAC.
DELETE rp FROM `access_role_permissions` rp JOIN `access_permissions` p ON p.id = rp.permission_id WHERE p.slug = 'erp.myday.team';
DELETE o FROM `access_user_overrides` o JOIN `access_permissions` p ON p.id = o.permission_id WHERE p.slug = 'erp.myday.team';
DELETE FROM `access_permissions` WHERE slug = 'erp.myday.team';
ALTER TABLE `studio_calendar_events` DROP INDEX IF EXISTS `idx_calendar_ticket`, DROP INDEX IF EXISTS `idx_calendar_condominium`,
    DROP COLUMN IF EXISTS `ticket_id`, DROP COLUMN IF EXISTS `condominium_id`;
DROP TABLE IF EXISTS `inspection_plan_tickets`;
ALTER TABLE `inspection_plans` DROP FOREIGN KEY IF EXISTS `fk_iplan_responsible`, DROP COLUMN IF EXISTS `responsible_id`;
