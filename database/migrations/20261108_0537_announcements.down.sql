-- Reverte 20261108_0537. Os avisos já na outbox continuam lá (os pendentes viram cancelados);
-- os comunicados e a preferência de comunicado geral saem.
UPDATE `erp_notification_outbox` SET `status` = 'cancelled', `last_error` = 'Comunicação removida'
  WHERE `event_type` IN ('announcement.published','collection.reminder') AND `status` = 'pending';
ALTER TABLE `erp_residents` DROP COLUMN IF EXISTS `notices_opt_out`;
DROP TABLE IF EXISTS `erp_announcements`;
DELETE FROM `access_permissions` WHERE `slug` IN ('erp.announcements.view','erp.announcements.manage');
