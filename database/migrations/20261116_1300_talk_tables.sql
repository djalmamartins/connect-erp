-- Connect Talk dentro do ERP (ADR 0323): as tabelas do atendimento por WhatsApp, iguais às do Talk separado
-- (repositório djalmamartins/talk, migrations 001 a 019, versão 0.22.0), para a cópia dos dados ser direta.
-- Prefixo talk_ (não colide com o ERP). talk_users é o perfil do atendente no Talk: liga ao usuário do ERP por
-- erp_user_id (login e permissões vêm do ERP). Idempotente (a suíte reaplica as migrations).
SET FOREIGN_KEY_CHECKS=0;
CREATE TABLE IF NOT EXISTS `talk_audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(40) NOT NULL,
  `target_type` varchar(20) NOT NULL,
  `target_id` int(10) unsigned DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_audit_target` (`target_type`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_campaign_recipients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `campaign_id` int(10) unsigned NOT NULL,
  `contact_id` int(10) unsigned DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `name` varchar(150) DEFAULT NULL,
  `params` text DEFAULT NULL,
  `status` enum('queued','sent','delivered','read','failed','skipped') NOT NULL DEFAULT 'queued',
  `skip_reason` varchar(60) DEFAULT NULL,
  `provider_message_id` varchar(128) DEFAULT NULL,
  `error` varchar(255) DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `replied_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_campaign_phone` (`campaign_id`,`phone`),
  KEY `ix_recipient_queue` (`campaign_id`,`status`),
  KEY `ix_recipient_provider` (`provider_message_id`),
  KEY `ix_recipient_contact` (`contact_id`,`sent_at`),
  CONSTRAINT `fk_recipient_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `talk_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_campaigns` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `channel_id` int(10) unsigned NOT NULL,
  `template_id` int(10) unsigned NOT NULL,
  `sector_id` int(10) unsigned NOT NULL,
  `var_map` text DEFAULT NULL,
  `audience` text DEFAULT NULL,
  `status` enum('draft','scheduled','running','paused','done','canceled') NOT NULL DEFAULT 'draft',
  `send_at` datetime DEFAULT NULL,
  `per_minute` smallint(5) unsigned NOT NULL DEFAULT 60,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `last_batch_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_campaign_status` (`status`,`send_at`),
  KEY `fk_campaign_channel` (`channel_id`),
  KEY `fk_campaign_template` (`template_id`),
  KEY `fk_campaign_sector` (`sector_id`),
  CONSTRAINT `fk_campaign_channel` FOREIGN KEY (`channel_id`) REFERENCES `talk_channels` (`id`),
  CONSTRAINT `fk_campaign_sector` FOREIGN KEY (`sector_id`) REFERENCES `talk_sectors` (`id`),
  CONSTRAINT `fk_campaign_template` FOREIGN KEY (`template_id`) REFERENCES `talk_templates` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_channels` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `kind` enum('qrcode','official','lab') NOT NULL,
  `purpose` enum('attendance','bulk','both') NOT NULL DEFAULT 'attendance',
  `sector_id` int(10) unsigned DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `phone` varchar(20) DEFAULT NULL,
  `config_encrypted` text NOT NULL,
  `status` enum('active','disconnected','disabled') NOT NULL DEFAULT 'active',
  `connection_state` enum('connected','connecting','disconnected','unknown') NOT NULL DEFAULT 'unknown',
  `state_checked_at` datetime DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_channel_sector` (`sector_id`),
  CONSTRAINT `fk_channel_sector` FOREIGN KEY (`sector_id`) REFERENCES `talk_sectors` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_contact_notes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_contact_notes` (`contact_id`,`id`),
  CONSTRAINT `fk_cn_contact` FOREIGN KEY (`contact_id`) REFERENCES `talk_contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_contact_tags` (
  `contact_id` int(10) unsigned NOT NULL,
  `tag_id` int(10) unsigned NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`contact_id`,`tag_id`),
  KEY `idx_contact_tags_tag` (`tag_id`),
  CONSTRAINT `fk_ct_contact` FOREIGN KEY (`contact_id`) REFERENCES `talk_contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ct_tag` FOREIGN KEY (`tag_id`) REFERENCES `talk_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_contacts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `phone` varchar(20) NOT NULL,
  `name` varchar(150) DEFAULT NULL,
  `erp_person_id` int(10) unsigned DEFAULT NULL,
  `erp_snapshot` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `blocked_at` datetime DEFAULT NULL,
  `blocked_by` int(10) unsigned DEFAULT NULL,
  `block_reason` varchar(200) DEFAULT NULL,
  `blocked_messages` int(10) unsigned NOT NULL DEFAULT 0,
  `opt_out_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contact_phone` (`phone`),
  KEY `ix_contact_person` (`erp_person_id`),
  KEY `ix_contact_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_conversations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `protocol` varchar(20) NOT NULL,
  `channel_id` int(10) unsigned NOT NULL,
  `contact_id` int(10) unsigned NOT NULL,
  `sector_id` int(10) unsigned DEFAULT NULL,
  `assigned_user_id` int(10) unsigned DEFAULT NULL,
  `status` enum('bot','pending','open','closed') NOT NULL DEFAULT 'bot',
  `stage` varchar(20) DEFAULT NULL,
  `priority` enum('low','normal','high') NOT NULL DEFAULT 'normal',
  `subject` varchar(200) DEFAULT NULL,
  `helpdesk_ticket_id` int(10) unsigned DEFAULT NULL,
  `history_from_message_id` bigint(20) unsigned DEFAULT NULL,
  `unread` int(10) unsigned NOT NULL DEFAULT 0,
  `last_message_at` datetime DEFAULT NULL,
  `opened_at` datetime NOT NULL DEFAULT current_timestamp(),
  `queued_at` datetime DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `closed_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `off_hours_at` datetime DEFAULT NULL,
  `helpdesk_protocol` varchar(40) DEFAULT NULL,
  `legacy_protocol` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_conv_protocol` (`protocol`),
  KEY `ix_conv_inbox` (`status`,`sector_id`,`last_message_at`),
  KEY `ix_conv_contact` (`contact_id`,`channel_id`,`status`),
  KEY `ix_conv_assigned` (`assigned_user_id`,`status`),
  KEY `fk_conv_channel` (`channel_id`),
  KEY `fk_conv_sector` (`sector_id`),
  KEY `ix_conv_queue` (`status`,`queued_at`),
  KEY `ix_conv_opened` (`opened_at`),
  KEY `ix_conv_legacy_protocol` (`legacy_protocol`),
  CONSTRAINT `fk_conv_channel` FOREIGN KEY (`channel_id`) REFERENCES `talk_channels` (`id`),
  CONSTRAINT `fk_conv_contact` FOREIGN KEY (`contact_id`) REFERENCES `talk_contacts` (`id`),
  CONSTRAINT `fk_conv_sector` FOREIGN KEY (`sector_id`) REFERENCES `talk_sectors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(50) NOT NULL,
  `conversation_id` int(10) unsigned DEFAULT NULL,
  `sector_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `payload` text NOT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `ix_events_conv` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_holidays` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `day` date NOT NULL,
  `name` varchar(80) NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_holiday_day` (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_jack_decisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL,
  `message_id` bigint(20) unsigned NOT NULL,
  `mode` enum('shadow','auto') NOT NULL,
  `intent` varchar(30) DEFAULT NULL,
  `suggested_sector_id` int(10) unsigned DEFAULT NULL,
  `confident` tinyint(1) NOT NULL DEFAULT 0,
  `scores` varchar(255) NOT NULL DEFAULT '{}',
  `matched` varchar(255) DEFAULT NULL,
  `action` enum('none','suggested','routed') NOT NULL DEFAULT 'none',
  `final_sector_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_jack_conversation` (`conversation_id`,`id`),
  KEY `idx_jack_created` (`created_at`),
  CONSTRAINT `fk_jack_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `talk_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_login_email` (`email`,`created_at`),
  KEY `ix_login_ip` (`ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL,
  `direction` enum('in','out','internal') NOT NULL,
  `author_user_id` int(10) unsigned DEFAULT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'text',
  `body` mediumtext DEFAULT NULL,
  `media_path` varchar(255) DEFAULT NULL,
  `media_mime` varchar(100) DEFAULT NULL,
  `media_name` varchar(255) DEFAULT NULL,
  `media_size` int(10) unsigned DEFAULT NULL,
  `provider_message_id` varchar(128) DEFAULT NULL,
  `ack` enum('pending','sent','delivered','read','failed','received') NOT NULL DEFAULT 'pending',
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_msg_provider` (`provider_message_id`),
  KEY `ix_msg_conv` (`conversation_id`,`created_at`),
  FULLTEXT KEY `ft_msg_body` (`body`),
  CONSTRAINT `fk_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `talk_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_outbox` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `message_id` bigint(20) unsigned NOT NULL,
  `channel_id` int(10) unsigned NOT NULL,
  `to_phone` varchar(20) NOT NULL,
  `typing_seconds` smallint(5) unsigned NOT NULL DEFAULT 0,
  `status` enum('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `next_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_error` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_outbox_ready` (`status`,`next_attempt_at`),
  KEY `fk_outbox_msg` (`message_id`),
  CONSTRAINT `fk_outbox_msg` FOREIGN KEY (`message_id`) REFERENCES `talk_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_protocol_seq` (
  `day` date NOT NULL,
  `last_value` int(10) unsigned NOT NULL,
  PRIMARY KEY (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_quick_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `shortcut` varchar(40) NOT NULL,
  `body` text NOT NULL,
  `sector_id` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quick_shortcut` (`shortcut`),
  KEY `idx_quick_sector` (`sector_id`),
  CONSTRAINT `fk_quick_sector` FOREIGN KEY (`sector_id`) REFERENCES `talk_sectors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_ratings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` int(10) unsigned NOT NULL,
  `contact_id` int(10) unsigned NOT NULL,
  `sector_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `score` tinyint(3) unsigned DEFAULT NULL,
  `comment` varchar(500) DEFAULT NULL,
  `asked_at` datetime NOT NULL DEFAULT current_timestamp(),
  `answered_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rating_conv` (`conversation_id`),
  KEY `ix_rating_contact` (`contact_id`,`answered_at`),
  KEY `ix_rating_user` (`user_id`,`answered_at`),
  CONSTRAINT `fk_rating_conv` FOREIGN KEY (`conversation_id`) REFERENCES `talk_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_schedules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` int(10) unsigned NOT NULL,
  `channel_id` int(10) unsigned NOT NULL,
  `sector_id` int(10) unsigned NOT NULL,
  `origin_conversation_id` int(10) unsigned DEFAULT NULL,
  `body` text NOT NULL,
  `sign` tinyint(1) NOT NULL DEFAULT 1,
  `send_at` datetime NOT NULL,
  `status` enum('scheduled','sending','sent','canceled','failed') NOT NULL DEFAULT 'scheduled',
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `canceled_by` int(10) unsigned DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `sent_conversation_id` int(10) unsigned DEFAULT NULL,
  `sent_message_id` bigint(20) unsigned DEFAULT NULL,
  `error` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_schedule_due` (`status`,`send_at`),
  KEY `ix_schedule_contact` (`contact_id`),
  KEY `fk_schedule_channel` (`channel_id`),
  KEY `fk_schedule_sector` (`sector_id`),
  CONSTRAINT `fk_schedule_channel` FOREIGN KEY (`channel_id`) REFERENCES `talk_channels` (`id`),
  CONSTRAINT `fk_schedule_contact` FOREIGN KEY (`contact_id`) REFERENCES `talk_contacts` (`id`),
  CONSTRAINT `fk_schedule_sector` FOREIGN KEY (`sector_id`) REFERENCES `talk_sectors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_sectors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `jack_key` varchar(30) DEFAULT NULL,
  `color` char(7) NOT NULL DEFAULT '#12804A',
  `description` varchar(255) DEFAULT NULL,
  `menu_key` varchar(5) NOT NULL,
  `keywords` text DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sector_menu` (`menu_key`),
  UNIQUE KEY `uq_sector_jack` (`jack_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_settings` (
  `name` varchar(60) NOT NULL,
  `value` mediumtext NOT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_staff` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `sector_id` int(10) unsigned DEFAULT NULL,
  `role` enum('agent','manager','supervisor') NOT NULL DEFAULT 'agent',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff` (`user_id`,`sector_id`),
  KEY `fk_staff_sector` (`sector_id`),
  CONSTRAINT `fk_staff_sector` FOREIGN KEY (`sector_id`) REFERENCES `talk_sectors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_tags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(40) NOT NULL,
  `color` char(7) NOT NULL DEFAULT '#12804A',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tag_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `channel_id` int(10) unsigned NOT NULL,
  `name` varchar(512) NOT NULL,
  `language` varchar(15) NOT NULL DEFAULT 'pt_BR',
  `category` varchar(30) DEFAULT NULL,
  `body` text NOT NULL,
  `variables` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'APPROVED',
  `synced_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_template` (`channel_id`,`name`,`language`),
  CONSTRAINT `fk_template_channel` FOREIGN KEY (`channel_id`) REFERENCES `talk_channels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `talk_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `erp_user_id` int(10) unsigned DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=1;
