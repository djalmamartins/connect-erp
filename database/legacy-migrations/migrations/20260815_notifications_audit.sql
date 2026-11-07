-- Central de notificações e trilha de auditoria do Studio.
-- Execute uma única vez em instalações que ainda não possuam estas estruturas.

CREATE TABLE IF NOT EXISTS `notification_messages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sender_id` int unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `audience` varchar(30) NOT NULL DEFAULT 'all',
  `target_user_id` int unsigned DEFAULT NULL,
  `severity` varchar(20) NOT NULL DEFAULT 'info',
  `link` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `scheduled_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notification_messages_status` (`status`,`scheduled_at`),
  KEY `idx_notification_messages_sender` (`sender_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `system_audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int unsigned DEFAULT NULL,
  `action` varchar(30) NOT NULL,
  `entity` varchar(100) NOT NULL,
  `entity_id` varchar(50) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `context_json` longtext DEFAULT NULL,
  `severity` varchar(20) NOT NULL DEFAULT 'info',
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity` (`entity`,`entity_id`),
  KEY `idx_audit_user` (`users_id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_severity` (`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `notifications`
  ADD COLUMN IF NOT EXISTS `message_id` int unsigned DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `body` text DEFAULT NULL AFTER `title`,
  ADD COLUMN IF NOT EXISTS `severity` varchar(20) NOT NULL DEFAULT 'info' AFTER `body`,
  ADD COLUMN IF NOT EXISTS `read_at` datetime DEFAULT NULL AFTER `view`,
  ADD COLUMN IF NOT EXISTS `expires_at` datetime DEFAULT NULL AFTER `read_at`;

INSERT INTO `notifications_categories` (`title`, `uri`, `description`, `type`)
SELECT 'Studio', 'studio', 'Notificações administrativas e alterações do sistema', 'system'
WHERE NOT EXISTS (SELECT 1 FROM `notifications_categories` WHERE `uri` = 'studio');
