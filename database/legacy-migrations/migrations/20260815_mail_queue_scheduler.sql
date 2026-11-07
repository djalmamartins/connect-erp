-- MovesOS: fila de e-mails agendada, rastreável e integrada às notificações.
ALTER TABLE `notification_messages`
  ADD COLUMN IF NOT EXISTS `delivery_channels` varchar(20) NOT NULL DEFAULT 'system' AFTER `severity`;

ALTER TABLE `mail_queue`
  ADD COLUMN IF NOT EXISTS `notification_message_id` int unsigned DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `users_id` int unsigned DEFAULT NULL AFTER `notification_message_id`,
  ADD COLUMN IF NOT EXISTS `status` varchar(20) NOT NULL DEFAULT 'pending' AFTER `recipient_name`,
  ADD COLUMN IF NOT EXISTS `scheduled_at` datetime DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `attempts` smallint unsigned NOT NULL DEFAULT 0 AFTER `scheduled_at`,
  ADD COLUMN IF NOT EXISTS `max_attempts` smallint unsigned NOT NULL DEFAULT 3 AFTER `attempts`,
  ADD COLUMN IF NOT EXISTS `last_attempt_at` datetime DEFAULT NULL AFTER `max_attempts`,
  ADD COLUMN IF NOT EXISTS `next_attempt_at` datetime DEFAULT NULL AFTER `last_attempt_at`,
  ADD COLUMN IF NOT EXISTS `error_message` varchar(500) DEFAULT NULL AFTER `next_attempt_at`,
  ADD COLUMN IF NOT EXISTS `failed_at` datetime DEFAULT NULL AFTER `error_message`,
  ADD COLUMN IF NOT EXISTS `cancelled_at` datetime DEFAULT NULL AFTER `failed_at`,
  ADD KEY IF NOT EXISTS `idx_mail_queue_dispatch` (`status`,`scheduled_at`,`next_attempt_at`),
  ADD KEY IF NOT EXISTS `idx_mail_queue_message` (`notification_message_id`),
  ADD KEY IF NOT EXISTS `idx_mail_queue_user` (`users_id`);

UPDATE `mail_queue` SET `status` = 'sent' WHERE `sent_at` IS NOT NULL AND `status` = 'pending';
UPDATE `mail_queue` SET `scheduled_at` = `created_at` WHERE `scheduled_at` IS NULL;
