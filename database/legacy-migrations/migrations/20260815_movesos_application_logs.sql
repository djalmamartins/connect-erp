ALTER TABLE `app_log`
  DROP FOREIGN KEY `app_log_ibfk_1`,
  MODIFY `users_id` int(11) unsigned NULL,
  MODIFY `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  MODIFY `msg` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  MODIFY `url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
  ADD COLUMN `incident_id` varchar(32) NULL AFTER `id`,
  ADD COLUMN `request_id` varchar(32) NULL AFTER `incident_id`,
  ADD COLUMN `level` enum('debug','info','notice','warning','error','critical','alert','emergency') NOT NULL DEFAULT 'info' AFTER `users_id`,
  ADD COLUMN `channel` varchar(80) NOT NULL DEFAULT 'application' AFTER `level`,
  ADD COLUMN `event_type` varchar(100) NULL AFTER `channel`,
  ADD COLUMN `code` varchar(100) NULL AFTER `event_type`,
  ADD COLUMN `exception_class` varchar(255) NULL AFTER `msg`,
  ADD COLUMN `file` varchar(500) NULL AFTER `exception_class`,
  ADD COLUMN `line` int unsigned NULL AFTER `file`,
  ADD COLUMN `context_json` longtext NULL AFTER `url`,
  ADD COLUMN `trace` longtext NULL AFTER `context_json`,
  ADD COLUMN `user_agent` varchar(500) NULL AFTER `trace`,
  ADD COLUMN `fingerprint` char(64) NULL AFTER `user_agent`,
  ADD COLUMN `occurrences` int unsigned NOT NULL DEFAULT 1 AFTER `fingerprint`,
  ADD COLUMN `status` enum('open','resolved','ignored') NOT NULL DEFAULT 'open' AFTER `occurrences`,
  ADD COLUMN `first_seen_at` timestamp NULL DEFAULT current_timestamp() AFTER `status`,
  ADD COLUMN `last_seen_at` timestamp NULL DEFAULT current_timestamp() AFTER `first_seen_at`,
  ADD COLUMN `resolved_by` int unsigned NULL AFTER `last_seen_at`,
  ADD COLUMN `resolved_at` timestamp NULL AFTER `resolved_by`,
  ADD KEY `idx_app_log_level_status` (`level`,`status`),
  ADD KEY `idx_app_log_channel` (`channel`),
  ADD KEY `idx_app_log_incident` (`incident_id`),
  ADD KEY `idx_app_log_fingerprint` (`fingerprint`),
  ADD KEY `idx_app_log_last_seen` (`last_seen_at`),
  ADD CONSTRAINT `fk_app_log_user` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE NO ACTION,
  ADD CONSTRAINT `fk_app_log_resolver` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE NO ACTION;

UPDATE `app_log`
SET `incident_id` = CONCAT('LEG-', LPAD(`id`, 8, '0')),
    `request_id` = CONCAT('legacy-', `id`),
    `event_type` = 'user_activity',
    `status` = 'resolved',
    `first_seen_at` = `created_at`,
    `last_seen_at` = `created_at`
WHERE `incident_id` IS NULL;

INSERT INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar logs do sistema','logs.view','Desenvolvimento','Acesso técnico aos erros, exceções e incidentes do MovesOS')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`group_name`=VALUES(`group_name`),`description`=VALUES(`description`);

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id,p.id FROM access_roles r CROSS JOIN access_permissions p
WHERE r.slug='developer' AND p.slug='logs.view';
