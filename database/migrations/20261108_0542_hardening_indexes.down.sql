-- Reverte 20261108_0542: remove só os índices (nenhum dado muda).
ALTER TABLE `erp_occurrences` DROP INDEX IF EXISTS `idx_erp_occurrences_period`;
ALTER TABLE `erp_reservations` DROP INDEX IF EXISTS `idx_erp_reservations_period`;
ALTER TABLE `erp_notification_outbox` DROP INDEX IF EXISTS `idx_erp_notification_outbox_report`;
