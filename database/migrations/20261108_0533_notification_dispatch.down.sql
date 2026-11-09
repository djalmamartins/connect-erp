-- Reverte 20261108_0533: remove a trilha de entrega, as colunas de modo e as permissões.
DELETE FROM `access_permissions` WHERE `slug` IN ('erp.notifications.view','erp.notifications.manage');
DROP TABLE IF EXISTS `erp_notification_deliveries`;
ALTER TABLE `erp_notification_outbox`
  DROP COLUMN IF EXISTS `recipients_count`,
  DROP COLUMN IF EXISTS `delivery_mode`;
