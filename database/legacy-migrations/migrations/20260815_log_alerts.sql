-- Relaciona alertas automáticos ao incidente que os originou.
ALTER TABLE `notifications`
  ADD COLUMN IF NOT EXISTS `source_log_id` INT(11) UNSIGNED NULL AFTER `message_id`,
  ADD KEY IF NOT EXISTS `idx_notifications_source_log` (`source_log_id`, `users_id`);

ALTER TABLE `mail_queue`
  ADD COLUMN IF NOT EXISTS `source_log_id` INT(11) UNSIGNED NULL AFTER `notification_message_id`,
  ADD KEY IF NOT EXISTS `idx_mail_queue_source_log` (`source_log_id`, `users_id`);
