-- Estrutura do banco do laboratório (ADR 0379), sem dados. Gerado por tools/lab-exportar-esquema.sh.
/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
DROP TABLE IF EXISTS `access_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `access_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `group_name` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_access_permissions_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `access_role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `access_role_permissions` (
  `role_id` int(10) unsigned NOT NULL,
  `permission_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `fk_access_rp_permission` (`permission_id`),
  CONSTRAINT `fk_access_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `access_permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_access_rp_role` FOREIGN KEY (`role_id`) REFERENCES `access_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `access_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `access_roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `slug` varchar(80) NOT NULL,
  `level` int(11) NOT NULL DEFAULT 10,
  `description` varchar(255) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_access_roles_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `access_user_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `access_user_overrides` (
  `user_id` int(10) unsigned NOT NULL,
  `permission_id` int(10) unsigned NOT NULL,
  `effect` enum('allow','deny') NOT NULL,
  `assigned_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`,`permission_id`),
  KEY `fk_access_uo_permission` (`permission_id`),
  CONSTRAINT `fk_access_uo_permission` FOREIGN KEY (`permission_id`) REFERENCES `access_permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_access_uo_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `access_user_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `access_user_roles` (
  `user_id` int(10) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  `assigned_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  KEY `idx_access_user_role` (`role_id`),
  CONSTRAINT `fk_access_ur_role` FOREIGN KEY (`role_id`) REFERENCES `access_roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_access_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_accountable`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_accountable` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `sub_of` int(11) unsigned NOT NULL,
  `users_id` int(11) unsigned NOT NULL,
  `function` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '',
  `administration` date DEFAULT NULL,
  `resignation` date DEFAULT NULL,
  `status` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT 'registered' COMMENT 'registered, confirmed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  KEY `FK_app_units_app_condominium` (`users_id`,`sub_of`) USING BTREE,
  KEY `FK_app_accountable_app_condominium` (`sub_of`),
  FULLTEXT KEY `fulltext` (`function`),
  CONSTRAINT `FK_app_accountable_app_condominium` FOREIGN KEY (`sub_of`) REFERENCES `app_condominium` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  CONSTRAINT `FK_app_accountable_users` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_address`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_address` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `corporations_id` int(11) unsigned DEFAULT NULL,
  `condominium_id` int(11) unsigned DEFAULT NULL,
  `users_id` int(11) unsigned DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT '',
  `code` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '',
  `city` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '',
  `state` varchar(2) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '',
  `district` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '',
  `street` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '',
  `number` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '',
  `complement` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'main' COMMENT 'main, leading',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  KEY `FK_app_address_users` (`users_id`) USING BTREE,
  KEY `FK_app_address_app_enterprises` (`condominium_id`) USING BTREE,
  KEY `Index 2` (`corporations_id`,`condominium_id`,`users_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_categories` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `sub_of` int(11) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL DEFAULT '',
  `type` varchar(15) NOT NULL DEFAULT '',
  `order_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  KEY `sub_of` (`sub_of`) USING BTREE,
  CONSTRAINT `app_categories_ibfk_1` FOREIGN KEY (`sub_of`) REFERENCES `app_categories` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_condominium`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_condominium` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `sub_of` int(11) unsigned NOT NULL,
  `registry` int(11) unsigned DEFAULT NULL,
  `condominium_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '',
  `fantasy_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `document` varchar(14) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `photo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `datebirth` date DEFAULT NULL,
  `obs` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `phone` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `fax` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `whatsapp` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `cell` varchar(11) DEFAULT NULL,
  `send` int(11) NOT NULL DEFAULT 1,
  `despatch` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT 'all' COMMENT 'e-mail, whatsapp, telegram, carta, all',
  `status` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT 'registered' COMMENT 'registered, confirmed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  KEY `sub_of` (`sub_of`) USING BTREE,
  FULLTEXT KEY `fulltext` (`fantasy_name`,`email`,`condominium_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_corporations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_corporations` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `registry` int(11) unsigned DEFAULT NULL,
  `corporation_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '',
  `fantasy_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `document` varchar(14) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `photo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `datebirth` date DEFAULT NULL,
  `phone` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `phone_cell` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `send` int(11) NOT NULL DEFAULT 1,
  `despatch` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT 'all' COMMENT 'e-mail, whatsapp, telegram, carta, all',
  `status` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL COMMENT 'registered, confirmed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  FULLTEXT KEY `fulltext` (`corporation_name`,`fantasy_name`,`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_credit_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_credit_cards` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `brand` varchar(20) NOT NULL DEFAULT '',
  `last_digits` varchar(11) NOT NULL DEFAULT '',
  `cvv` varchar(11) NOT NULL DEFAULT '',
  `hash` varchar(255) NOT NULL DEFAULT '',
  `status` varchar(255) DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `credit_cards_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_invoices` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(11) unsigned NOT NULL,
  `unitis_id` int(11) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `wallet_id` int(11) unsigned NOT NULL,
  `category_id` int(11) unsigned NOT NULL,
  `invoice_of` int(11) unsigned DEFAULT NULL,
  `deal_of` int(11) unsigned DEFAULT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `type` varchar(15) NOT NULL DEFAULT '',
  `value` decimal(10,2) NOT NULL,
  `currency` varchar(5) NOT NULL DEFAULT 'BRL',
  `due_at` date NOT NULL,
  `paid_at` date NOT NULL,
  `repeat_when` varchar(10) NOT NULL DEFAULT '',
  `period` varchar(10) NOT NULL DEFAULT 'month',
  `enrollments` int(11) DEFAULT NULL,
  `enrollment_of` int(11) DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'unpaid' COMMENT 'unpaid, paid, deal',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `app_user` (`user_id`),
  KEY `app_wallet` (`wallet_id`),
  KEY `app_category` (`category_id`),
  KEY `app_condominium` (`condominium_id`) USING BTREE,
  KEY `app_units` (`unitis_id`) USING BTREE,
  KEY `app_invoice` (`invoice_of`,`deal_of`) USING BTREE,
  KEY `app_invoice_deal` (`deal_of`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` varchar(32) DEFAULT NULL,
  `request_id` varchar(32) DEFAULT NULL,
  `corporations_id` int(11) unsigned DEFAULT NULL,
  `condominium_id` int(11) unsigned DEFAULT NULL,
  `users_id` int(11) unsigned DEFAULT NULL,
  `level` enum('debug','info','notice','warning','error','critical','alert','emergency') NOT NULL DEFAULT 'info',
  `channel` varchar(80) NOT NULL DEFAULT 'application',
  `event_type` varchar(100) DEFAULT NULL,
  `code` varchar(100) DEFAULT NULL,
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `msg` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception_class` varchar(255) DEFAULT NULL,
  `file` varchar(500) DEFAULT NULL,
  `line` int(10) unsigned DEFAULT NULL,
  `url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `context_json` longtext DEFAULT NULL,
  `trace` longtext DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `fingerprint` char(64) DEFAULT NULL,
  `occurrences` int(10) unsigned NOT NULL DEFAULT 1,
  `status` enum('open','resolved','ignored') NOT NULL DEFAULT 'open',
  `first_seen_at` timestamp NULL DEFAULT current_timestamp(),
  `last_seen_at` timestamp NULL DEFAULT current_timestamp(),
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  KEY `FK_app_historic_users` (`users_id`) USING BTREE,
  KEY `Corporations` (`corporations_id`) USING BTREE,
  KEY `enterprises` (`condominium_id`) USING BTREE,
  KEY `idx_app_log_level_status` (`level`,`status`),
  KEY `idx_app_log_channel` (`channel`),
  KEY `idx_app_log_incident` (`incident_id`),
  KEY `idx_app_log_fingerprint` (`fingerprint`),
  KEY `idx_app_log_last_seen` (`last_seen_at`),
  KEY `fk_app_log_resolver` (`resolved_by`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_orders` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned DEFAULT NULL,
  `card_id` int(11) unsigned DEFAULT NULL,
  `subscription_id` int(11) unsigned DEFAULT NULL,
  `transaction` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT '',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `orders_user` (`user_id`),
  KEY `orders_credit_card` (`card_id`),
  KEY `orders_subscription` (`subscription_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_owner`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_owner` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `sub_of` int(11) unsigned DEFAULT NULL,
  `units_id` int(11) unsigned DEFAULT NULL,
  `users_id` int(11) unsigned DEFAULT NULL,
  `owner` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'owner' COMMENT 'owner, tenant',
  `status` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'confirmed' COMMENT ' confirmed, registered',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  KEY `Index 2` (`sub_of`,`units_id`,`users_id`) USING BTREE,
  KEY `FK_app_owner_app_units` (`units_id`),
  KEY `FK_app_owner_users` (`users_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_plans` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `period` varchar(11) NOT NULL DEFAULT '',
  `period_str` varchar(11) NOT NULL DEFAULT '',
  `price` decimal(10,2) NOT NULL,
  `status` varchar(11) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_session`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_session` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `corporations_id` int(11) unsigned NOT NULL,
  `condominium_id` int(11) unsigned DEFAULT NULL,
  `users_id` int(11) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  KEY `corporations` (`corporations_id`) USING BTREE,
  KEY `users` (`users_id`) USING BTREE,
  KEY `enterprises` (`condominium_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_subscriptions` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned DEFAULT NULL,
  `plan_id` int(11) unsigned DEFAULT NULL,
  `card_id` int(11) unsigned DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'active' COMMENT 'active,inactive,past_due,canceled',
  `pay_status` varchar(10) NOT NULL DEFAULT 'active' COMMENT 'active,inactive,past_due,canceled',
  `started` date NOT NULL,
  `due_day` int(2) NOT NULL,
  `next_due` date NOT NULL,
  `last_charge` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `subscription_user` (`user_id`),
  KEY `subscription_card` (`card_id`),
  KEY `subscription_plan` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_units` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `sub_of` int(11) unsigned DEFAULT NULL,
  `units` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  KEY `FK_app_units_app_condominium` (`sub_of`),
  FULLTEXT KEY `fulltext` (`units`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_wallets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_wallets` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `wallet` varchar(255) NOT NULL DEFAULT '',
  `free` int(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `wallet_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auth_environment_handoffs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_environment_handoffs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `environment` varchar(20) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_env_handoff_token` (`token_hash`),
  KEY `idx_env_handoff_expires` (`expires_at`),
  KEY `fk_env_handoff_user` (`user_id`),
  CONSTRAINT `fk_env_handoff_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auth_throttle_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_throttle_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `scope` varchar(16) NOT NULL,
  `subject_hash` char(64) NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_auth_throttle_subject` (`scope`,`subject_hash`,`created_at`),
  KEY `idx_auth_throttle_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `brief`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `brief` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(180) NOT NULL,
  `cover` varchar(255) DEFAULT NULL,
  `content` text NOT NULL,
  `townhouse` varchar(180) DEFAULT NULL,
  `rating` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `status` enum('draft','published') NOT NULL DEFAULT 'published',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_brief_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '',
  `uri` varchar(255) NOT NULL DEFAULT '',
  `description` text NOT NULL,
  `cover` varchar(255) DEFAULT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'post' COMMENT 'post, page, etc',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categories_slide`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories_slide` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '',
  `uri` varchar(255) NOT NULL DEFAULT '',
  `description` mediumtext NOT NULL,
  `cover` varchar(255) DEFAULT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'post' COMMENT 'post, page, etc',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `condominium_geolocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `condominium_geolocations` (
  `condominium_id` int(10) unsigned NOT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `radius_m` smallint(5) unsigned NOT NULL DEFAULT 150 COMMENT 'Raio aceito no check-in, em metros',
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`condominium_id`),
  KEY `fk_cgeo_user` (`updated_by`),
  CONSTRAINT `fk_cgeo_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cgeo_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `condominium_onboarding_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `condominium_onboarding_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `template_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL = item avulso do condomínio',
  `category` enum('documentacao','seguranca','manutencao','seguros','financeiro','pessoas') NOT NULL,
  `title` varchar(160) NOT NULL,
  `status` enum('pending','regular','action_required','in_progress','not_applicable') NOT NULL DEFAULT 'pending',
  `protocol` varchar(20) DEFAULT NULL,
  `due_date` date DEFAULT NULL COMMENT 'Prazo para regularizar',
  `expires_at` date DEFAULT NULL COMMENT 'Validade do documento/serviço quando regular',
  `notes` text DEFAULT NULL,
  `responsible_user_id` int(10) unsigned DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `alerted_situation` varchar(20) DEFAULT NULL,
  `alerted_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coi_condominium_template` (`condominium_id`,`template_id`),
  UNIQUE KEY `uq_coi_protocol` (`protocol`),
  KEY `idx_coi_condominium_status` (`condominium_id`,`status`),
  KEY `idx_coi_expires` (`status`,`expires_at`),
  KEY `fk_coi_template` (`template_id`),
  KEY `fk_coi_responsible` (`responsible_user_id`),
  KEY `fk_coi_created_by` (`created_by`),
  KEY `fk_coi_updated_by` (`updated_by`),
  CONSTRAINT `fk_coi_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_coi_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_coi_responsible` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_coi_template` FOREIGN KEY (`template_id`) REFERENCES `onboarding_item_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_coi_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `condominiums`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `condominiums` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `legal_name` varchar(160) DEFAULT NULL COMMENT 'Razão social',
  `document` char(14) DEFAULT NULL COMMENT 'CNPJ somente dígitos',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `zip_code` char(8) DEFAULT NULL COMMENT 'CEP somente dígitos',
  `street` varchar(160) DEFAULT NULL,
  `number` varchar(20) DEFAULT NULL,
  `complement` varchar(80) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` char(2) DEFAULT NULL COMMENT 'UF',
  `phone` varchar(20) DEFAULT NULL COMMENT 'Somente dígitos',
  `email` varchar(190) DEFAULT NULL,
  `blocks_count` smallint(5) unsigned DEFAULT NULL,
  `units_count` int(10) unsigned DEFAULT NULL,
  `contract_start_date` date DEFAULT NULL COMMENT 'Início do contrato de administração',
  `fee_due_day` tinyint(3) unsigned DEFAULT NULL COMMENT 'Dia de vencimento da taxa (1 a 28)' CHECK (`fee_due_day` between 1 and 28),
  `bank_code` char(3) DEFAULT NULL COMMENT 'Provisório; migra para o núcleo financeiro',
  `bank_branch` varchar(10) DEFAULT NULL,
  `bank_account` varchar(20) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_condominiums_document` (`document`),
  KEY `idx_condominiums_status_name` (`status`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_access_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_access_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `person_name` varchar(160) NOT NULL,
  `person_document` varchar(30) DEFAULT NULL,
  `kind` enum('visitante','prestador','entregador','morador') NOT NULL,
  `plate` varchar(10) DEFAULT NULL,
  `entered_on` date NOT NULL,
  `entered_time` time NOT NULL,
  `left_time` time DEFAULT NULL,
  `gatehouse_id` int(10) unsigned DEFAULT NULL,
  `visit_forecast_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active' COMMENT 'inactive = lançamento cancelado',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_access_logs_day` (`condominium_id`,`entered_on`,`deleted_at`),
  KEY `idx_erp_access_logs_unit` (`unit_id`),
  KEY `idx_erp_access_logs_gatehouse` (`gatehouse_id`),
  KEY `idx_erp_access_logs_forecast` (`visit_forecast_id`),
  KEY `fk_erp_access_logs_created_by` (`created_by`),
  KEY `fk_erp_access_logs_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_access_logs_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_access_logs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_access_logs_forecast` FOREIGN KEY (`visit_forecast_id`) REFERENCES `erp_visit_forecasts` (`id`),
  CONSTRAINT `fk_erp_access_logs_gatehouse` FOREIGN KEY (`gatehouse_id`) REFERENCES `erp_gatehouses` (`id`),
  CONSTRAINT `fk_erp_access_logs_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `fk_erp_access_logs_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_accountability_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_accountability_alerts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `competence` char(7) NOT NULL COMMENT 'AAAA-MM do livro',
  `situation` enum('due_soon','overdue') NOT NULL,
  `notified` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'Pessoas da equipe avisadas',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_accountability_alerts` (`condominium_id`,`competence`,`situation`),
  CONSTRAINT `fk_erp_accountability_alerts_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_accountability_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_accountability_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `kind` enum('monthly','annual') NOT NULL DEFAULT 'monthly',
  `competence` varchar(7) NOT NULL COMMENT 'AAAA-MM (mensal) ou AAAA (anual)',
  `token` char(32) NOT NULL COMMENT 'Parte secreta do link público',
  `status` enum('active','revoked') NOT NULL DEFAULT 'active',
  `version` smallint(5) unsigned NOT NULL DEFAULT 1,
  `sha256` char(64) NOT NULL COMMENT 'sha256 de payload_json',
  `payload_json` longtext NOT NULL COMMENT 'Livro agrupado (sem caminho de arquivo) em JSON canônico',
  `generated_by` int(10) unsigned DEFAULT NULL,
  `generated_at` datetime NOT NULL,
  `sent_version` smallint(5) unsigned DEFAULT NULL COMMENT 'Versão enviada por e-mail aos moradores',
  `sent_at` datetime DEFAULT NULL,
  `revoked_by` int(10) unsigned DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoke_reason` varchar(255) DEFAULT NULL,
  `view_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_viewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_accountability_links_period` (`condominium_id`,`kind`,`competence`),
  UNIQUE KEY `uq_erp_accountability_links_token` (`token`),
  KEY `fk_erp_accountability_links_generated_by` (`generated_by`),
  KEY `fk_erp_accountability_links_revoked_by` (`revoked_by`),
  CONSTRAINT `fk_erp_accountability_links_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_accountability_links_generated_by` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_accountability_links_revoked_by` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_accountability_publications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_accountability_publications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `kind` enum('monthly','annual') NOT NULL DEFAULT 'monthly' COMMENT 'ADR 0183',
  `competence` char(7) NOT NULL COMMENT 'AAAA-MM',
  `version` smallint(5) unsigned NOT NULL,
  `sha256` char(64) NOT NULL COMMENT 'sha256 de payload_json',
  `payload_json` mediumtext NOT NULL COMMENT 'Pacote da prestação de contas em JSON canônico',
  `status` enum('current','superseded','withdrawn') NOT NULL DEFAULT 'current',
  `notes` varchar(255) DEFAULT NULL,
  `published_by` int(10) unsigned DEFAULT NULL,
  `published_at` datetime NOT NULL,
  `withdrawn_by` int(10) unsigned DEFAULT NULL,
  `withdrawn_at` datetime DEFAULT NULL,
  `withdraw_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_accountability_publications_kind_version` (`condominium_id`,`kind`,`competence`,`version`),
  KEY `idx_erp_accountability_publications_status` (`condominium_id`,`competence`,`status`),
  KEY `fk_erp_accountability_publications_published_by` (`published_by`),
  KEY `fk_erp_accountability_publications_withdrawn_by` (`withdrawn_by`),
  KEY `idx_erp_accountability_publications_kind` (`condominium_id`,`kind`,`competence`,`status`),
  CONSTRAINT `fk_erp_accountability_publications_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_accountability_publications_published_by` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_accountability_publications_withdrawn_by` FOREIGN KEY (`withdrawn_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_agreement_originals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_agreement_originals` (
  `agreement_id` int(10) unsigned NOT NULL,
  `entry_id` int(10) unsigned NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`agreement_id`,`entry_id`),
  KEY `idx_erp_agreement_originals_entry` (`entry_id`),
  CONSTRAINT `fk_erp_agreement_originals_agreement` FOREIGN KEY (`agreement_id`) REFERENCES `erp_agreements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_agreement_originals_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_agreements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_agreements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `status` enum('active','cancelled','breached') NOT NULL DEFAULT 'active',
  `chart_account_id` int(10) unsigned NOT NULL COMMENT 'Conta analítica de receita das parcelas',
  `original_cents` bigint(20) unsigned NOT NULL COMMENT 'Soma das contas renegociadas',
  `fees_cents` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'Multa e juros informados',
  `discount_cents` bigint(20) unsigned NOT NULL DEFAULT 0,
  `total_cents` bigint(20) unsigned NOT NULL COMMENT 'original + encargos − desconto = soma das parcelas',
  `installments` smallint(5) unsigned NOT NULL,
  `first_due_on` date NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `breached_by` int(10) unsigned DEFAULT NULL,
  `breached_at` datetime DEFAULT NULL,
  `breach_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_agreements_list` (`condominium_id`,`status`,`created_at`),
  KEY `idx_erp_agreements_unit` (`unit_id`),
  KEY `fk_erp_agreements_account` (`chart_account_id`),
  KEY `fk_erp_agreements_created_by` (`created_by`),
  KEY `fk_erp_agreements_cancelled_by` (`cancelled_by`),
  KEY `fk_erp_agreements_breached_by` (`breached_by`),
  CONSTRAINT `fk_erp_agreements_account` FOREIGN KEY (`chart_account_id`) REFERENCES `erp_chart_accounts` (`id`),
  CONSTRAINT `fk_erp_agreements_breached_by` FOREIGN KEY (`breached_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_agreements_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_agreements_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_agreements_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_agreements_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_announcements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `kind` enum('general','collection') NOT NULL COMMENT 'general = comunicado; collection = aviso de cobrança',
  `subject` varchar(120) NOT NULL,
  `body` text DEFAULT NULL COMMENT 'Texto do comunicado (o aviso de cobrança usa o modelo)',
  `audience` enum('all','units') NOT NULL DEFAULT 'all',
  `send_at` datetime NOT NULL COMMENT 'Quando entra na fila (agora ou agendado)',
  `units_count` int(10) unsigned NOT NULL DEFAULT 0,
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_announcements_list` (`condominium_id`,`kind`,`send_at`),
  KEY `fk_erp_announcements_created_by` (`created_by`),
  KEY `fk_erp_announcements_cancelled_by` (`cancelled_by`),
  CONSTRAINT `fk_erp_announcements_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_announcements_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_announcements_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_assemblies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_assemblies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `kind` enum('ordinaria','extraordinaria') NOT NULL,
  `mode` enum('presencial','virtual','hibrida') NOT NULL,
  `title` varchar(160) NOT NULL,
  `location` varchar(255) NOT NULL COMMENT 'Endereço ou o link da sala virtual',
  `first_call_at` datetime NOT NULL COMMENT 'Primeira convocação',
  `second_call_at` datetime DEFAULT NULL COMMENT 'Segunda convocação',
  `status` enum('draft','convened','open','closed','cancelled') NOT NULL DEFAULT 'draft',
  `announcement_id` int(10) unsigned DEFAULT NULL COMMENT 'Comunicado do edital (ADR 0059)',
  `convened_at` datetime DEFAULT NULL,
  `opened_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `minutes` mediumtext DEFAULT NULL COMMENT 'Ata gerada no fechamento',
  `minutes_sha256` char(64) DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_assemblies_list` (`condominium_id`,`status`,`first_call_at`),
  KEY `fk_erp_assemblies_announcement` (`announcement_id`),
  KEY `fk_erp_assemblies_created_by` (`created_by`),
  KEY `fk_erp_assemblies_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_assemblies_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `erp_announcements` (`id`),
  CONSTRAINT `fk_erp_assemblies_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_assemblies_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_assemblies_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_assembly_attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_assembly_attendance` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `assembly_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `attendee_name` varchar(160) NOT NULL,
  `is_proxy` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Presente por procuração',
  `proxy_document_id` int(10) unsigned DEFAULT NULL COMMENT 'Procuração no Disco Virtual (ADR 0047)',
  `eligible` tinyint(1) NOT NULL COMMENT 'Quite na abertura (art. 1.335, III)',
  `overdue_cents` bigint(20) unsigned NOT NULL DEFAULT 0,
  `checked_in_at` datetime NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_assembly_attendance_unit` (`assembly_id`,`unit_id`),
  KEY `idx_erp_assembly_attendance_proxy` (`assembly_id`,`is_proxy`,`attendee_name`),
  KEY `fk_erp_assembly_attendance_unit` (`unit_id`),
  KEY `fk_erp_assembly_attendance_document` (`proxy_document_id`),
  KEY `fk_erp_assembly_attendance_created_by` (`created_by`),
  CONSTRAINT `fk_erp_assembly_attendance_assembly` FOREIGN KEY (`assembly_id`) REFERENCES `erp_assemblies` (`id`),
  CONSTRAINT `fk_erp_assembly_attendance_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_assembly_attendance_document` FOREIGN KEY (`proxy_document_id`) REFERENCES `erp_documents` (`id`),
  CONSTRAINT `fk_erp_assembly_attendance_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_assembly_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_assembly_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `assembly_id` int(10) unsigned NOT NULL,
  `position` smallint(5) unsigned NOT NULL,
  `title` varchar(160) NOT NULL,
  `description` text DEFAULT NULL,
  `vote_basis` enum('fraction','unit') NOT NULL COMMENT 'Fração ideal ou uma unidade, um voto',
  `quorum` enum('simple','absolute','two_thirds','unanimity') NOT NULL,
  `status` enum('pending','voting','decided') NOT NULL DEFAULT 'pending',
  `result_json` text DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_assembly_items_position` (`assembly_id`,`position`),
  CONSTRAINT `fk_erp_assembly_items_assembly` FOREIGN KEY (`assembly_id`) REFERENCES `erp_assemblies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_assembly_votes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_assembly_votes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `assembly_id` int(10) unsigned NOT NULL,
  `item_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `choice` enum('yes','no','abstain') NOT NULL,
  `weight` decimal(9,6) NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_assembly_votes_unit` (`item_id`,`unit_id`),
  KEY `idx_erp_assembly_votes_assembly` (`assembly_id`),
  KEY `fk_erp_assembly_votes_unit` (`unit_id`),
  KEY `fk_erp_assembly_votes_created_by` (`created_by`),
  CONSTRAINT `fk_erp_assembly_votes_assembly` FOREIGN KEY (`assembly_id`) REFERENCES `erp_assemblies` (`id`),
  CONSTRAINT `fk_erp_assembly_votes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_assembly_votes_item` FOREIGN KEY (`item_id`) REFERENCES `erp_assembly_items` (`id`),
  CONSTRAINT `fk_erp_assembly_votes_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_assets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `description` varchar(160) NOT NULL,
  `asset_number` varchar(30) DEFAULT NULL,
  `category` varchar(15) NOT NULL,
  `condition` varchar(12) NOT NULL,
  `acquired_on` date DEFAULT NULL,
  `value_cents` bigint(20) unsigned DEFAULT NULL,
  `location` varchar(120) DEFAULT NULL,
  `supplier_name` varchar(160) DEFAULT NULL,
  `invoice_number` varchar(30) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_assets_condominium` (`condominium_id`,`deleted_at`,`status`),
  KEY `idx_erp_assets_number` (`asset_number`),
  KEY `fk_erp_assets_created_by` (`created_by`),
  KEY `fk_erp_assets_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_assets_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_assets_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_assets_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_bank_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_bank_accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `name` varchar(80) NOT NULL COMMENT 'Apelido, ex.: Conta movimento Itaú',
  `bank_code` char(3) NOT NULL COMMENT 'Código COMPE',
  `branch` varchar(10) NOT NULL,
  `account_number` varchar(20) NOT NULL,
  `kind` enum('corrente','poupanca','aplicacao') NOT NULL DEFAULT 'corrente',
  `parent_bank_account_id` int(10) unsigned DEFAULT NULL COMMENT 'Aplicação: conta corrente ligada (ADR 0170)',
  `is_pocket` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Caixinha: aplicação no mesmo banco da conta corrente (ADR 0685)',
  `wallet` varchar(20) DEFAULT NULL COMMENT 'Carteira de cobrança, quando houver',
  `adapter` varchar(30) NOT NULL DEFAULT 'sandbox' COMMENT 'Adaptador bancário (sandbox, inter)',
  `opening_balance_cents` bigint(20) unsigned DEFAULT NULL,
  `opened_on` date DEFAULT NULL,
  `is_main` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Uma conta principal ativa por condomínio',
  `cost_center_id` int(10) unsigned DEFAULT NULL COMMENT 'Centro de custo padrão dos lançamentos',
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_bank_accounts_condominium` (`condominium_id`,`deleted_at`,`status`),
  KEY `idx_erp_bank_accounts_number` (`bank_code`,`branch`,`account_number`),
  KEY `fk_erp_bank_accounts_cost_center` (`cost_center_id`),
  KEY `fk_erp_bank_accounts_created_by` (`created_by`),
  KEY `fk_erp_bank_accounts_updated_by` (`updated_by`),
  KEY `idx_erp_bank_accounts_parent` (`parent_bank_account_id`),
  CONSTRAINT `fk_erp_bank_accounts_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_bank_accounts_cost_center` FOREIGN KEY (`cost_center_id`) REFERENCES `erp_cost_centers` (`id`),
  CONSTRAINT `fk_erp_bank_accounts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_bank_accounts_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_bank_charge_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_bank_charge_entries` (
  `charge_id` int(10) unsigned NOT NULL,
  `entry_id` int(10) unsigned NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL COMMENT 'Valor da conta dentro do boleto',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`charge_id`,`entry_id`),
  KEY `idx_erp_bank_charge_entries_entry` (`entry_id`),
  CONSTRAINT `fk_erp_bank_charge_entries_charge` FOREIGN KEY (`charge_id`) REFERENCES `erp_bank_charges` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_bank_charge_entries_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_bank_charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_bank_charges` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `bank_account_id` int(10) unsigned NOT NULL,
  `entry_id` int(10) unsigned NOT NULL,
  `adapter` varchar(30) NOT NULL,
  `status` enum('pending','registered','rejected','paid','cancelled') NOT NULL DEFAULT 'pending',
  `active_key` tinyint(3) unsigned GENERATED ALWAYS AS (case when `status` in ('pending','registered','paid') then 1 end) STORED,
  `our_number` varchar(20) NOT NULL COMMENT 'Nosso número, sequencial por conta bancária',
  `amount_cents` bigint(20) unsigned NOT NULL,
  `due_on` date NOT NULL,
  `external_id` varchar(80) DEFAULT NULL COMMENT 'Identificador do título no banco',
  `bank_our_number` varchar(20) DEFAULT NULL COMMENT 'Nosso número atribuído pelo banco',
  `digitable_line` varchar(80) DEFAULT NULL,
  `barcode` varchar(60) DEFAULT NULL,
  `pix_txid` varchar(64) DEFAULT NULL,
  `pix_copy_paste` text DEFAULT NULL COMMENT 'Pix copia e cola do boleto híbrido',
  `last_error` varchar(255) DEFAULT NULL,
  `paid_cents` bigint(20) unsigned DEFAULT NULL,
  `paid_on` date DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `registered_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_bank_charges_our_number` (`bank_account_id`,`our_number`),
  UNIQUE KEY `uq_erp_bank_charges_active_entry` (`entry_id`,`active_key`),
  KEY `idx_erp_bank_charges_list` (`condominium_id`,`status`,`due_on`),
  KEY `fk_erp_bank_charges_created_by` (`created_by`),
  KEY `idx_erp_bank_charges_external` (`bank_account_id`,`external_id`),
  CONSTRAINT `fk_erp_bank_charges_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`),
  CONSTRAINT `fk_erp_bank_charges_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_bank_charges_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_bank_charges_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_bank_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_bank_credentials` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `bank_account_id` int(10) unsigned NOT NULL,
  `adapter` varchar(30) NOT NULL,
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox',
  `secrets` blob NOT NULL COMMENT 'JSON cifrado no cofre (BankVault); nunca em texto aberto',
  `client_id_hint` varchar(12) DEFAULT NULL COMMENT 'Só os 4 últimos caracteres, para conferência',
  `certificate_subject` varchar(160) DEFAULT NULL,
  `certificate_expires_on` date DEFAULT NULL,
  `webhook_token_hash` char(64) DEFAULT NULL COMMENT 'sha256 do segredo do endereço do webhook',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_bank_credentials_account` (`bank_account_id`),
  UNIQUE KEY `uq_erp_bank_credentials_webhook` (`webhook_token_hash`),
  KEY `fk_erp_bank_credentials_created_by` (`created_by`),
  KEY `fk_erp_bank_credentials_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_bank_credentials_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`),
  CONSTRAINT `fk_erp_bank_credentials_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_bank_credentials_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_bank_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_bank_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `bank_account_id` int(10) unsigned NOT NULL,
  `return_file_id` int(10) unsigned DEFAULT NULL,
  `charge_id` int(10) unsigned DEFAULT NULL,
  `event_key` varchar(120) NOT NULL COMMENT 'Identificador do evento no banco (idempotência)',
  `kind` enum('registered','rejected','paid','cancelled') NOT NULL,
  `our_number` varchar(20) NOT NULL,
  `amount_cents` bigint(20) unsigned DEFAULT NULL,
  `occurred_on` date DEFAULT NULL,
  `outcome` enum('applied','duplicate','divergence','unknown_charge') NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `raw` text DEFAULT NULL,
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_bank_events_key` (`bank_account_id`,`event_key`),
  KEY `idx_erp_bank_events_review` (`condominium_id`,`outcome`,`resolved_at`),
  KEY `idx_erp_bank_events_charge` (`charge_id`),
  KEY `fk_erp_bank_events_file` (`return_file_id`),
  KEY `fk_erp_bank_events_resolved_by` (`resolved_by`),
  CONSTRAINT `fk_erp_bank_events_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`),
  CONSTRAINT `fk_erp_bank_events_charge` FOREIGN KEY (`charge_id`) REFERENCES `erp_bank_charges` (`id`),
  CONSTRAINT `fk_erp_bank_events_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_bank_events_file` FOREIGN KEY (`return_file_id`) REFERENCES `erp_bank_return_files` (`id`),
  CONSTRAINT `fk_erp_bank_events_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_bank_return_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_bank_return_files` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `bank_account_id` int(10) unsigned NOT NULL,
  `adapter` varchar(30) NOT NULL,
  `filename` varchar(120) NOT NULL,
  `sha256` char(64) NOT NULL,
  `events_count` int(10) unsigned NOT NULL DEFAULT 0,
  `applied_count` int(10) unsigned NOT NULL DEFAULT 0,
  `divergence_count` int(10) unsigned NOT NULL DEFAULT 0,
  `total_cents` bigint(20) unsigned NOT NULL DEFAULT 0,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_bank_return_files_hash` (`bank_account_id`,`sha256`),
  KEY `idx_erp_bank_return_files_condominium` (`condominium_id`,`created_at`),
  KEY `fk_erp_bank_return_files_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_erp_bank_return_files_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`),
  CONSTRAINT `fk_erp_bank_return_files_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_bank_return_files_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_bank_statement_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_bank_statement_lines` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `bank_account_id` int(10) unsigned NOT NULL,
  `import_id` int(10) unsigned NOT NULL,
  `fitid` varchar(80) NOT NULL,
  `kind` enum('credit','debit') NOT NULL,
  `posted_on` date NOT NULL,
  `amount_cents` bigint(20) NOT NULL COMMENT 'Com sinal: crédito positivo, débito negativo',
  `memo` varchar(160) DEFAULT NULL,
  `status` enum('matched','unmatched','ignored') NOT NULL,
  `entry_id` int(10) unsigned DEFAULT NULL,
  `transfer_id` int(10) unsigned DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_bank_statement_lines_fitid` (`bank_account_id`,`fitid`),
  UNIQUE KEY `uq_erp_bank_statement_lines_entry` (`entry_id`),
  UNIQUE KEY `uq_erp_bank_statement_lines_transfer` (`transfer_id`,`bank_account_id`),
  KEY `idx_erp_bank_statement_lines_review` (`condominium_id`,`status`,`posted_on`),
  KEY `idx_erp_bank_statement_lines_import` (`import_id`),
  KEY `fk_erp_bank_statement_lines_resolved_by` (`resolved_by`),
  CONSTRAINT `fk_erp_bank_statement_lines_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`),
  CONSTRAINT `fk_erp_bank_statement_lines_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_bank_statement_lines_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`),
  CONSTRAINT `fk_erp_bank_statement_lines_import` FOREIGN KEY (`import_id`) REFERENCES `erp_imports` (`id`),
  CONSTRAINT `fk_erp_bank_statement_lines_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_bank_statement_lines_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `erp_bank_transfers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_bank_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_bank_transfers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `from_bank_account_id` int(10) unsigned NOT NULL,
  `to_bank_account_id` int(10) unsigned NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL,
  `transferred_on` date NOT NULL,
  `description` varchar(160) NOT NULL,
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_bank_transfers_list` (`condominium_id`,`status`,`transferred_on`),
  KEY `idx_erp_bank_transfers_from` (`from_bank_account_id`),
  KEY `idx_erp_bank_transfers_to` (`to_bank_account_id`),
  KEY `fk_erp_bank_transfers_created_by` (`created_by`),
  KEY `fk_erp_bank_transfers_cancelled_by` (`cancelled_by`),
  CONSTRAINT `fk_erp_bank_transfers_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_bank_transfers_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_bank_transfers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_bank_transfers_from` FOREIGN KEY (`from_bank_account_id`) REFERENCES `erp_bank_accounts` (`id`),
  CONSTRAINT `fk_erp_bank_transfers_to` FOREIGN KEY (`to_bank_account_id`) REFERENCES `erp_bank_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_bank_webhook_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_bank_webhook_deliveries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `bank_account_id` int(10) unsigned NOT NULL,
  `adapter` varchar(30) NOT NULL,
  `sha256` char(64) NOT NULL,
  `payload` blob NOT NULL COMMENT 'Corpo recebido, cifrado no cofre',
  `items_count` int(10) unsigned NOT NULL DEFAULT 0,
  `applied_count` int(10) unsigned NOT NULL DEFAULT 0,
  `status` enum('received','processed','failed') NOT NULL DEFAULT 'received',
  `error` varchar(255) DEFAULT NULL,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_bank_webhook_deliveries_hash` (`bank_account_id`,`sha256`),
  KEY `idx_erp_bank_webhook_deliveries_status` (`status`,`received_at`),
  KEY `fk_erp_bank_webhook_deliveries_condominium` (`condominium_id`),
  CONSTRAINT `fk_erp_bank_webhook_deliveries_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`),
  CONSTRAINT `fk_erp_bank_webhook_deliveries_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_banners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_banners` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned DEFAULT NULL,
  `title` varchar(80) NOT NULL,
  `message` varchar(500) NOT NULL,
  `starts_on` date NOT NULL,
  `ends_on` date NOT NULL,
  `position` int(11) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_banners_period` (`condominium_id`,`deleted_at`,`starts_on`,`ends_on`),
  KEY `fk_erp_banners_created_by` (`created_by`),
  KEY `fk_erp_banners_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_banners_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_banners_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_banners_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_billing_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_billing_rules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned DEFAULT NULL,
  `scope_key` int(10) unsigned GENERATED ALWAYS AS (coalesce(`condominium_id`,0)) STORED,
  `fine_bp` smallint(5) unsigned NOT NULL DEFAULT 200 COMMENT 'Multa em pontos-base (200 = 2%)',
  `monthly_interest_bp` smallint(5) unsigned NOT NULL DEFAULT 100 COMMENT 'Juros ao mês em pontos-base, pro rata die',
  `price_index` varchar(10) NOT NULL DEFAULT 'IPCA' COMMENT 'IPCA ou NONE (sem correção)',
  `breach_days_late` smallint(5) unsigned NOT NULL DEFAULT 30,
  `breach_open_installments` smallint(5) unsigned NOT NULL DEFAULT 2,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_billing_rules_scope` (`scope_key`),
  KEY `fk_erp_billing_rules_condominium` (`condominium_id`),
  KEY `fk_erp_billing_rules_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_billing_rules_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_billing_rules_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_board_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_board_members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `role` enum('sindico','subsindico','fiscal','consultivo','suplente') NOT NULL,
  `name` varchar(160) NOT NULL,
  `document` varchar(14) DEFAULT NULL COMMENT 'CPF ou CNPJ (síndico profissional), somente dígitos',
  `unit_label` varchar(40) DEFAULT NULL,
  `term_starts_on` date NOT NULL,
  `term_ends_on` date NOT NULL,
  `email` varchar(254) DEFAULT NULL,
  `phone` varchar(13) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_board_members_role` (`condominium_id`,`role`,`status`),
  KEY `idx_erp_board_members_condominium` (`condominium_id`,`deleted_at`,`status`),
  KEY `fk_erp_board_members_created_by` (`created_by`),
  KEY `fk_erp_board_members_updated_by` (`updated_by`),
  KEY `idx_erp_board_members_unit` (`unit_id`),
  CONSTRAINT `fk_erp_board_members_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_board_members_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_board_members_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `fk_erp_board_members_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_budget_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_budget_lines` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `year` smallint(5) unsigned NOT NULL,
  `chart_account_id` int(10) unsigned NOT NULL,
  `month` tinyint(3) unsigned NOT NULL COMMENT '1 a 12',
  `amount_cents` bigint(20) unsigned NOT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_budget_lines` (`condominium_id`,`year`,`chart_account_id`,`month`),
  KEY `idx_erp_budget_lines_year` (`condominium_id`,`year`),
  KEY `fk_erp_budget_lines_account` (`chart_account_id`),
  KEY `fk_erp_budget_lines_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_budget_lines_account` FOREIGN KEY (`chart_account_id`) REFERENCES `erp_chart_accounts` (`id`),
  CONSTRAINT `fk_erp_budget_lines_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_budget_lines_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_chart_account_openings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_chart_account_openings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `chart_account_id` int(10) unsigned NOT NULL,
  `opened_on` date NOT NULL,
  `amount_cents` bigint(20) NOT NULL COMMENT 'Pode ser negativo',
  `notes` varchar(255) DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_chart_account_openings` (`condominium_id`,`chart_account_id`),
  KEY `idx_erp_chart_account_openings_account` (`chart_account_id`),
  KEY `fk_erp_chart_account_openings_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_chart_account_openings_account` FOREIGN KEY (`chart_account_id`) REFERENCES `erp_chart_accounts` (`id`),
  CONSTRAINT `fk_erp_chart_account_openings_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_chart_account_openings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_chart_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_chart_accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL = plano padrão da Connect',
  `code` varchar(30) NOT NULL COMMENT 'Código hierárquico: 1, 1.01, 1.01.002',
  `name` varchar(120) NOT NULL,
  `nature` enum('receita','despesa','asset','liability','equity') NOT NULL COMMENT 'asset/liability/equity = contas patrimoniais (ADR 0167)',
  `kind` enum('sintetica','analitica') NOT NULL COMMENT 'sintetica agrupa; analitica recebe lançamentos',
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_chart_accounts_code` (`condominium_id`,`code`,`deleted_at`),
  KEY `fk_erp_chart_accounts_created_by` (`created_by`),
  KEY `fk_erp_chart_accounts_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_chart_accounts_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_chart_accounts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_chart_accounts_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_check_copies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_check_copies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `bank_account_id` int(10) unsigned NOT NULL,
  `check_number` int(10) unsigned NOT NULL,
  `issued_on` date NOT NULL,
  `payee` varchar(160) NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL,
  `entry_id` int(10) unsigned DEFAULT NULL COMMENT 'Lançamento pago com o cheque',
  `document_id` int(10) unsigned NOT NULL COMMENT 'Imagem do cheque no Disco Virtual',
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active' COMMENT 'inactive = cheque cancelado',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_check_copies_number` (`condominium_id`,`bank_account_id`,`check_number`,`deleted_at`),
  KEY `idx_erp_check_copies_entry` (`entry_id`),
  KEY `idx_erp_check_copies_document` (`document_id`),
  KEY `fk_erp_check_copies_bank_account` (`bank_account_id`),
  KEY `fk_erp_check_copies_created_by` (`created_by`),
  KEY `fk_erp_check_copies_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_check_copies_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`),
  CONSTRAINT `fk_erp_check_copies_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_check_copies_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_check_copies_document` FOREIGN KEY (`document_id`) REFERENCES `erp_documents` (`id`),
  CONSTRAINT `fk_erp_check_copies_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`),
  CONSTRAINT `fk_erp_check_copies_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_collection_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_collection_contacts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `channel` enum('phone','email','whatsapp','in_person','letter') NOT NULL,
  `outcome` enum('promise','negotiating','no_answer','refused','paid_claimed','other') NOT NULL,
  `contact_name` varchar(160) DEFAULT NULL COMMENT 'Com quem falou',
  `promise_on` date DEFAULT NULL,
  `promise_cents` bigint(20) unsigned DEFAULT NULL,
  `next_action_on` date DEFAULT NULL,
  `reminded_at` datetime DEFAULT NULL COMMENT 'Quando o lembrete da próxima ação foi dado (ADR 0315)',
  `note` varchar(1000) NOT NULL,
  `open_cents` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'Valor vencido em aberto no momento do contato',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_collection_contacts_unit` (`unit_id`,`created_at`),
  KEY `idx_erp_collection_contacts_next` (`condominium_id`,`next_action_on`),
  KEY `fk_erp_collection_contacts_created_by` (`created_by`),
  KEY `idx_erp_collection_contacts_next_action` (`next_action_on`,`reminded_at`),
  CONSTRAINT `fk_erp_collection_contacts_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_collection_contacts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_collection_contacts_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_commitments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_commitments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `supplier_id` int(10) unsigned DEFAULT NULL,
  `description` varchar(160) NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL,
  `committed_on` date NOT NULL,
  `settled_on` date DEFAULT NULL,
  `entry_id` int(10) unsigned DEFAULT NULL COMMENT 'Lançamento que liquidou o empenho',
  `document_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','settled','inactive') NOT NULL DEFAULT 'active' COMMENT 'active = empenhado, settled = liquidado, inactive = cancelado',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_commitments_list` (`condominium_id`,`status`,`committed_on`,`deleted_at`),
  KEY `idx_erp_commitments_supplier` (`supplier_id`),
  KEY `idx_erp_commitments_entry` (`entry_id`),
  KEY `idx_erp_commitments_document` (`document_id`),
  KEY `fk_erp_commitments_created_by` (`created_by`),
  KEY `fk_erp_commitments_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_commitments_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_commitments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_commitments_document` FOREIGN KEY (`document_id`) REFERENCES `erp_documents` (`id`),
  CONSTRAINT `fk_erp_commitments_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`),
  CONSTRAINT `fk_erp_commitments_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`),
  CONSTRAINT `fk_erp_commitments_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_common_areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_common_areas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `booking_mode` enum('approval','free','blocked','none') NOT NULL,
  `capacity` int(10) unsigned DEFAULT NULL,
  `booking_fee_cents` bigint(20) unsigned DEFAULT NULL,
  `min_advance_days` smallint(5) unsigned DEFAULT NULL,
  `max_advance_days` smallint(5) unsigned DEFAULT NULL,
  `opening_time` time DEFAULT NULL,
  `closing_time` time DEFAULT NULL,
  `rules` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_common_areas_condominium` (`condominium_id`,`deleted_at`,`status`),
  KEY `fk_erp_common_areas_created_by` (`created_by`),
  KEY `fk_erp_common_areas_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_common_areas_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_common_areas_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_common_areas_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_contracts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `supplier_id` int(10) unsigned NOT NULL,
  `object` varchar(255) NOT NULL,
  `starts_on` date NOT NULL,
  `ends_on` date DEFAULT NULL,
  `monthly_cents` bigint(20) unsigned NOT NULL,
  `readjustment` enum('ipca','igpm','nenhum') NOT NULL DEFAULT 'nenhum',
  `document_id` int(10) unsigned DEFAULT NULL COMMENT 'Contrato assinado no Disco Virtual',
  `notes` text DEFAULT NULL,
  `status` enum('active','ended','inactive') NOT NULL DEFAULT 'active' COMMENT 'active = vigente, ended = encerrado, inactive = cancelado',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_contracts_list` (`condominium_id`,`status`,`ends_on`,`deleted_at`),
  KEY `idx_erp_contracts_supplier` (`supplier_id`),
  KEY `fk_erp_contracts_document` (`document_id`),
  KEY `fk_erp_contracts_created_by` (`created_by`),
  KEY `fk_erp_contracts_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_contracts_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_contracts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_contracts_document` FOREIGN KEY (`document_id`) REFERENCES `erp_documents` (`id`),
  CONSTRAINT `fk_erp_contracts_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`),
  CONSTRAINT `fk_erp_contracts_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_cost_center_openings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_cost_center_openings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `cost_center_id` int(10) unsigned NOT NULL,
  `opened_on` date NOT NULL,
  `amount_cents` bigint(20) NOT NULL COMMENT 'Pode ser negativo',
  `notes` varchar(255) DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_cost_center_openings` (`condominium_id`,`cost_center_id`),
  KEY `fk_erp_cost_center_openings_center` (`cost_center_id`),
  KEY `fk_erp_cost_center_openings_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_cost_center_openings_center` FOREIGN KEY (`cost_center_id`) REFERENCES `erp_cost_centers` (`id`),
  CONSTRAINT `fk_erp_cost_center_openings_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_cost_center_openings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_cost_centers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_cost_centers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL = centro de custo padrão da Connect',
  `code` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_cost_centers_code` (`condominium_id`,`code`,`deleted_at`),
  KEY `fk_erp_cost_centers_created_by` (`created_by`),
  KEY `fk_erp_cost_centers_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_cost_centers_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_cost_centers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_cost_centers_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_deliveries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `recipient` varchar(160) NOT NULL,
  `carrier` enum('correios','mercado_livre','amazon','outra') NOT NULL,
  `tracking_code` varchar(60) DEFAULT NULL,
  `received_on` date NOT NULL,
  `received_time` time DEFAULT NULL,
  `picked_up_on` date DEFAULT NULL,
  `picked_up_by` varchar(160) DEFAULT NULL,
  `gatehouse_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','picked_up','inactive') NOT NULL DEFAULT 'active' COMMENT 'active = aguardando retirada, inactive = cancelada',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_deliveries_condominium` (`condominium_id`,`status`,`received_on`,`deleted_at`),
  KEY `idx_erp_deliveries_unit` (`unit_id`),
  KEY `idx_erp_deliveries_gatehouse` (`gatehouse_id`),
  KEY `fk_erp_deliveries_created_by` (`created_by`),
  KEY `fk_erp_deliveries_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_deliveries_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_deliveries_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_deliveries_gatehouse` FOREIGN KEY (`gatehouse_id`) REFERENCES `erp_gatehouses` (`id`),
  CONSTRAINT `fk_erp_deliveries_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `fk_erp_deliveries_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_delivery_notices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_delivery_notices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `recipient` varchar(160) NOT NULL,
  `tracking_code` varchar(40) NOT NULL,
  `subject` varchar(160) DEFAULT NULL,
  `sent_on` date NOT NULL,
  `delivered_on` date DEFAULT NULL,
  `document_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','delivered','returned') NOT NULL DEFAULT 'active' COMMENT 'active = em trânsito',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_delivery_notices_list` (`condominium_id`,`sent_on`,`deleted_at`),
  KEY `idx_erp_delivery_notices_unit` (`unit_id`),
  KEY `idx_erp_delivery_notices_document` (`document_id`),
  KEY `fk_erp_delivery_notices_created_by` (`created_by`),
  KEY `fk_erp_delivery_notices_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_delivery_notices_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_delivery_notices_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_delivery_notices_document` FOREIGN KEY (`document_id`) REFERENCES `erp_documents` (`id`),
  CONSTRAINT `fk_erp_delivery_notices_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `fk_erp_delivery_notices_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `folder` enum('atas','contratos','plantas','notas_fiscais','comprovantes','outros') NOT NULL,
  `title` varchar(160) NOT NULL,
  `original_name` varchar(160) NOT NULL COMMENT 'Nome enviado, já limpo; nunca usado no disco',
  `path` varchar(120) NOT NULL COMMENT 'Relativo a storage/private/documents',
  `mime` varchar(120) NOT NULL,
  `size_bytes` int(10) unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `scan_status` enum('clean','infected','unscanned','error') NOT NULL DEFAULT 'unscanned' COMMENT 'Antivírus (ADR 0098)',
  `shared_with_residents` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Visível no aplicativo do morador (ADR 0302)',
  `scanned_at` datetime DEFAULT NULL COMMENT 'Quando o antivírus respondeu',
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `deleted_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_documents_path` (`path`),
  KEY `idx_erp_documents_list` (`condominium_id`,`folder`,`deleted_at`,`created_at`),
  KEY `idx_erp_documents_hash` (`condominium_id`,`sha256`),
  KEY `fk_erp_documents_uploaded_by` (`uploaded_by`),
  KEY `fk_erp_documents_deleted_by` (`deleted_by`),
  KEY `idx_erp_documents_shared` (`condominium_id`,`shared_with_residents`,`deleted_at`),
  CONSTRAINT `fk_erp_documents_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_documents_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_documents_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_employees` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `job` varchar(20) NOT NULL,
  `name` varchar(160) NOT NULL,
  `document` char(11) NOT NULL COMMENT 'CPF, somente dígitos',
  `hired_on` date NOT NULL,
  `terminated_on` date DEFAULT NULL,
  `shift` varchar(10) DEFAULT NULL,
  `salary_cents` bigint(20) unsigned DEFAULT NULL,
  `phone` varchar(13) DEFAULT NULL,
  `email` varchar(254) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_employees_document` (`document`),
  KEY `idx_erp_employees_condominium` (`condominium_id`,`deleted_at`,`status`),
  KEY `fk_erp_employees_created_by` (`created_by`),
  KEY `fk_erp_employees_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_employees_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_employees_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_employees_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_entries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `nature` enum('receita','despesa') NOT NULL,
  `chart_account_id` int(10) unsigned NOT NULL,
  `cost_center_id` int(10) unsigned DEFAULT NULL,
  `related_account_id` int(10) unsigned DEFAULT NULL COMMENT 'Conta relacionada (ADR 0329)',
  `bundle_invoice_type_id` int(10) unsigned DEFAULT NULL COMMENT 'Tipo da fatura em que a conta entra no boleto (ADR 0331)',
  `bank_account_id` int(10) unsigned DEFAULT NULL COMMENT 'Obrigatória quando o lançamento está pago',
  `supplier_id` int(10) unsigned DEFAULT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `payer_resident_id` int(10) unsigned DEFAULT NULL COMMENT 'Vínculo (erp_residents) de quem paga, vazio = responsável da unidade',
  `recurring_entry_id` int(10) unsigned DEFAULT NULL,
  `invoice_item_id` int(10) unsigned DEFAULT NULL,
  `agreement_id` int(10) unsigned DEFAULT NULL,
  `agreement_role` enum('original','installment') DEFAULT NULL,
  `agreement_installment` smallint(5) unsigned DEFAULT NULL,
  `extra_charge_id` int(10) unsigned DEFAULT NULL,
  `import_id` int(10) unsigned DEFAULT NULL,
  `description` varchar(160) NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL,
  `competence` char(7) NOT NULL COMMENT 'Mês de competência AAAA-MM',
  `due_on` date NOT NULL,
  `paid_on` date DEFAULT NULL,
  `document_number` varchar(40) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active' COMMENT 'inactive = cancelado',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_entries_recurring_competence` (`recurring_entry_id`,`competence`),
  UNIQUE KEY `uq_erp_entries_invoice_item` (`invoice_item_id`),
  UNIQUE KEY `uq_erp_entries_agreement_installment` (`agreement_id`,`agreement_installment`),
  KEY `idx_erp_entries_condominium` (`condominium_id`,`deleted_at`,`status`,`due_on`),
  KEY `idx_erp_entries_competence` (`condominium_id`,`competence`),
  KEY `idx_erp_entries_account` (`chart_account_id`),
  KEY `idx_erp_entries_cost_center` (`cost_center_id`),
  KEY `idx_erp_entries_bank_account` (`bank_account_id`),
  KEY `idx_erp_entries_supplier` (`supplier_id`),
  KEY `fk_erp_entries_created_by` (`created_by`),
  KEY `fk_erp_entries_updated_by` (`updated_by`),
  KEY `idx_erp_entries_unit` (`unit_id`),
  KEY `idx_erp_entries_agreement` (`agreement_id`,`agreement_role`),
  KEY `idx_erp_entries_extra_charge` (`extra_charge_id`),
  KEY `idx_erp_entries_import` (`import_id`),
  KEY `idx_erp_entries_paid` (`condominium_id`,`paid_on`),
  KEY `idx_erp_entries_bank_paid` (`bank_account_id`,`paid_on`),
  KEY `idx_erp_entries_payer` (`payer_resident_id`),
  KEY `idx_erp_entries_related_account` (`related_account_id`),
  KEY `idx_erp_entries_bundle` (`unit_id`,`competence`,`bundle_invoice_type_id`),
  KEY `fk_erp_entries_bundle_invoice_type` (`bundle_invoice_type_id`),
  CONSTRAINT `fk_erp_entries_account` FOREIGN KEY (`chart_account_id`) REFERENCES `erp_chart_accounts` (`id`),
  CONSTRAINT `fk_erp_entries_agreement` FOREIGN KEY (`agreement_id`) REFERENCES `erp_agreements` (`id`),
  CONSTRAINT `fk_erp_entries_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`),
  CONSTRAINT `fk_erp_entries_bundle_invoice_type` FOREIGN KEY (`bundle_invoice_type_id`) REFERENCES `erp_invoice_types` (`id`),
  CONSTRAINT `fk_erp_entries_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_entries_cost_center` FOREIGN KEY (`cost_center_id`) REFERENCES `erp_cost_centers` (`id`),
  CONSTRAINT `fk_erp_entries_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_entries_extra_charge` FOREIGN KEY (`extra_charge_id`) REFERENCES `erp_extra_charges` (`id`),
  CONSTRAINT `fk_erp_entries_import` FOREIGN KEY (`import_id`) REFERENCES `erp_imports` (`id`),
  CONSTRAINT `fk_erp_entries_invoice_item` FOREIGN KEY (`invoice_item_id`) REFERENCES `erp_invoice_items` (`id`),
  CONSTRAINT `fk_erp_entries_payer` FOREIGN KEY (`payer_resident_id`) REFERENCES `erp_residents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_entries_recurring` FOREIGN KEY (`recurring_entry_id`) REFERENCES `erp_recurring_entries` (`id`),
  CONSTRAINT `fk_erp_entries_related_account` FOREIGN KEY (`related_account_id`) REFERENCES `erp_chart_accounts` (`id`),
  CONSTRAINT `fk_erp_entries_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`),
  CONSTRAINT `fk_erp_entries_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `fk_erp_entries_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_entry_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_entry_adjustments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `entry_id` int(10) unsigned NOT NULL,
  `kind` enum('discount') NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL,
  `before_cents` bigint(20) unsigned NOT NULL,
  `after_cents` bigint(20) unsigned NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_entry_adjustments_entry` (`entry_id`),
  KEY `idx_erp_entry_adjustments_list` (`condominium_id`,`created_at`),
  KEY `fk_erp_entry_adjustments_created_by` (`created_by`),
  CONSTRAINT `fk_erp_entry_adjustments_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_entry_adjustments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_entry_adjustments_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_entry_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_entry_attachments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `entry_id` int(10) unsigned NOT NULL,
  `document_id` int(10) unsigned NOT NULL,
  `kind` enum('conta','nota_fiscal','comprovante','recibo','outro') NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `deleted_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_entry_attachments_entry` (`entry_id`,`deleted_at`),
  KEY `idx_erp_entry_attachments_condominium` (`condominium_id`,`deleted_at`),
  KEY `idx_erp_entry_attachments_document` (`document_id`),
  KEY `fk_erp_entry_attachments_created_by` (`created_by`),
  KEY `fk_erp_entry_attachments_deleted_by` (`deleted_by`),
  CONSTRAINT `fk_erp_entry_attachments_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_entry_attachments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_entry_attachments_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_entry_attachments_document` FOREIGN KEY (`document_id`) REFERENCES `erp_documents` (`id`),
  CONSTRAINT `fk_erp_entry_attachments_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_extra_charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_extra_charges` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `kind` enum('extra','fine') NOT NULL,
  `description` varchar(120) NOT NULL,
  `chart_account_id` int(10) unsigned NOT NULL COMMENT 'Conta analítica de receita',
  `method` enum('fixed','equal','fraction','custom') NOT NULL COMMENT 'fixed = valor por unidade, equal/fraction = total rateado, custom = valor de cada unidade',
  `total_cents` bigint(20) unsigned NOT NULL,
  `units_count` smallint(5) unsigned NOT NULL,
  `competence` char(7) NOT NULL,
  `due_on` date NOT NULL,
  `related_entry_id` int(10) unsigned DEFAULT NULL COMMENT 'Multa: conta a que se refere (opcional)',
  `occurrence_id` int(10) unsigned DEFAULT NULL,
  `reservation_id` int(10) unsigned DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL COMMENT 'Multa: motivo (regulamento, ocorrência)',
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_extra_charges_list` (`condominium_id`,`kind`,`status`,`due_on`),
  KEY `fk_erp_extra_charges_account` (`chart_account_id`),
  KEY `fk_erp_extra_charges_related` (`related_entry_id`),
  KEY `fk_erp_extra_charges_created_by` (`created_by`),
  KEY `fk_erp_extra_charges_cancelled_by` (`cancelled_by`),
  KEY `fk_erp_extra_charges_occurrence` (`occurrence_id`),
  KEY `fk_erp_extra_charges_reservation` (`reservation_id`),
  CONSTRAINT `fk_erp_extra_charges_account` FOREIGN KEY (`chart_account_id`) REFERENCES `erp_chart_accounts` (`id`),
  CONSTRAINT `fk_erp_extra_charges_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_extra_charges_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_extra_charges_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_extra_charges_occurrence` FOREIGN KEY (`occurrence_id`) REFERENCES `erp_occurrences` (`id`),
  CONSTRAINT `fk_erp_extra_charges_related` FOREIGN KEY (`related_entry_id`) REFERENCES `erp_entries` (`id`),
  CONSTRAINT `fk_erp_extra_charges_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `erp_reservations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_gatehouses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_gatehouses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `name` varchar(80) NOT NULL,
  `kind` enum('presencial','remota','hibrida') NOT NULL,
  `shifts` tinyint(3) unsigned DEFAULT NULL,
  `manager_name` varchar(160) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_gatehouses_condominium` (`condominium_id`,`deleted_at`),
  KEY `fk_erp_gatehouses_created_by` (`created_by`),
  KEY `fk_erp_gatehouses_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_gatehouses_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_gatehouses_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_gatehouses_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_imports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `kind` enum('ofx','entries','unit_entries','payables','fractions','delinquents','readings','nfe') NOT NULL,
  `bank_account_id` int(10) unsigned DEFAULT NULL COMMENT 'OFX: conta do extrato',
  `options_json` varchar(500) DEFAULT NULL COMMENT 'Opções da prévia (NF-e: conta de despesa)',
  `original_name` varchar(160) NOT NULL,
  `sha256` char(64) NOT NULL,
  `storage_path` varchar(255) NOT NULL COMMENT 'Relativo a storage/private/imports',
  `size_bytes` int(10) unsigned NOT NULL,
  `status` enum('previewed','applied','discarded') NOT NULL DEFAULT 'previewed',
  `rows_count` int(10) unsigned NOT NULL DEFAULT 0,
  `error_count` int(10) unsigned NOT NULL DEFAULT 0,
  `summary_json` text DEFAULT NULL COMMENT 'Totais da prévia ou da aplicação',
  `created_by` int(10) unsigned DEFAULT NULL,
  `applied_by` int(10) unsigned DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `discarded_by` int(10) unsigned DEFAULT NULL COMMENT 'ADR 0285',
  `discarded_at` datetime DEFAULT NULL COMMENT 'ADR 0285',
  `discard_reason` varchar(255) DEFAULT NULL COMMENT 'ADR 0285',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_imports_file` (`condominium_id`,`kind`,`sha256`),
  KEY `idx_erp_imports_list` (`condominium_id`,`kind`,`created_at`),
  KEY `fk_erp_imports_bank_account` (`bank_account_id`),
  KEY `fk_erp_imports_created_by` (`created_by`),
  KEY `fk_erp_imports_applied_by` (`applied_by`),
  KEY `fk_erp_imports_discarded_by` (`discarded_by`),
  CONSTRAINT `fk_erp_imports_applied_by` FOREIGN KEY (`applied_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_imports_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`),
  CONSTRAINT `fk_erp_imports_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_imports_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_imports_discarded_by` FOREIGN KEY (`discarded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_insurances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_insurances` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `supplier_id` int(10) unsigned DEFAULT NULL COMMENT 'Seguradora ou corretora cadastrada',
  `insurer` varchar(160) NOT NULL,
  `policy_number` varchar(60) NOT NULL,
  `starts_on` date NOT NULL,
  `ends_on` date NOT NULL,
  `premium_cents` bigint(20) unsigned DEFAULT NULL,
  `coverage_cents` bigint(20) unsigned DEFAULT NULL,
  `document_id` int(10) unsigned DEFAULT NULL COMMENT 'Apólice no Disco Virtual',
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active' COMMENT 'inactive = cancelado',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_insurances_list` (`condominium_id`,`ends_on`,`deleted_at`),
  KEY `idx_erp_insurances_supplier` (`supplier_id`),
  KEY `fk_erp_insurances_document` (`document_id`),
  KEY `fk_erp_insurances_created_by` (`created_by`),
  KEY `fk_erp_insurances_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_insurances_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_insurances_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_insurances_document` FOREIGN KEY (`document_id`) REFERENCES `erp_documents` (`id`),
  CONSTRAINT `fk_erp_insurances_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`),
  CONSTRAINT `fk_erp_insurances_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_integration_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_integration_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client` varchar(30) NOT NULL COMMENT 'Sistema que usa o token (talk)',
  `name` varchar(80) NOT NULL,
  `user_id` int(10) unsigned NOT NULL COMMENT 'Usuário de integração: as permissões e o escopo de condomínios dele valem para o token',
  `token_hash` char(64) NOT NULL COMMENT 'sha256 do token Bearer',
  `token_hint` char(4) NOT NULL COMMENT 'Últimos 4 caracteres, só para reconhecer o token na lista',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoke_reason` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_integration_tokens_hash` (`token_hash`),
  KEY `idx_erp_integration_tokens_client` (`client`,`revoked_at`),
  KEY `fk_erp_integration_tokens_user` (`user_id`),
  CONSTRAINT `fk_erp_integration_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_invoice_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `unit_label` varchar(40) NOT NULL COMMENT 'Rótulo da unidade na preparação',
  `amount_cents` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_invoice_items_unit` (`invoice_id`,`unit_id`),
  KEY `idx_erp_invoice_items_unit` (`unit_id`),
  CONSTRAINT `fk_erp_invoice_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `erp_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_invoice_items_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_invoice_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_invoice_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL = tipo padrão da Connect',
  `name` varchar(80) NOT NULL COMMENT 'Ex.: Taxa condominial, Fundo de reserva, Rateio extra',
  `chart_account_id` int(10) unsigned NOT NULL COMMENT 'Conta analítica de receita',
  `recurrence` enum('mensal','avulsa') NOT NULL DEFAULT 'mensal',
  `default_amount_cents` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_invoice_types_condominium` (`condominium_id`,`deleted_at`,`status`),
  KEY `idx_erp_invoice_types_account` (`chart_account_id`),
  KEY `fk_erp_invoice_types_created_by` (`created_by`),
  KEY `fk_erp_invoice_types_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_invoice_types_account` FOREIGN KEY (`chart_account_id`) REFERENCES `erp_chart_accounts` (`id`),
  CONSTRAINT `fk_erp_invoice_types_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_invoice_types_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_invoice_types_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_invoices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `invoice_type_id` int(10) unsigned NOT NULL,
  `competence` char(7) NOT NULL COMMENT 'AAAA-MM',
  `due_on` date NOT NULL,
  `status` enum('draft','issued','cancelled') NOT NULL DEFAULT 'draft',
  `active_key` tinyint(3) unsigned GENERATED ALWAYS AS (case when `status` <> 'cancelled' then 1 end) STORED,
  `units_count` int(10) unsigned NOT NULL DEFAULT 0,
  `amount_cents` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'Soma dos itens',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `issued_by` int(10) unsigned DEFAULT NULL,
  `issued_at` datetime DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_invoices_active` (`condominium_id`,`invoice_type_id`,`competence`,`active_key`),
  KEY `idx_erp_invoices_list` (`condominium_id`,`competence`,`status`),
  KEY `fk_erp_invoices_type` (`invoice_type_id`),
  KEY `fk_erp_invoices_created_by` (`created_by`),
  KEY `fk_erp_invoices_issued_by` (`issued_by`),
  KEY `fk_erp_invoices_cancelled_by` (`cancelled_by`),
  CONSTRAINT `fk_erp_invoices_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_invoices_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_invoices_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_invoices_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_invoices_type` FOREIGN KEY (`invoice_type_id`) REFERENCES `erp_invoice_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_lawyers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_lawyers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `oab` varchar(12) NOT NULL COMMENT 'UF e número, ex.: SP 123456',
  `document` varchar(14) DEFAULT NULL COMMENT 'CPF (11) ou CNPJ (14), só dígitos',
  `firm` varchar(160) DEFAULT NULL,
  `email` varchar(254) DEFAULT NULL,
  `phone` varchar(13) DEFAULT NULL,
  `fee_percent` decimal(5,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_lawyers_oab` (`oab`),
  KEY `idx_erp_lawyers_status` (`deleted_at`,`status`),
  KEY `fk_erp_lawyers_created_by` (`created_by`),
  KEY `fk_erp_lawyers_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_lawyers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_lawyers_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_legal_case_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_legal_case_entries` (
  `case_id` int(10) unsigned NOT NULL,
  `entry_id` int(10) unsigned NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`case_id`,`entry_id`),
  KEY `idx_erp_legal_case_entries_entry` (`entry_id`),
  CONSTRAINT `fk_erp_legal_case_entries_case` FOREIGN KEY (`case_id`) REFERENCES `erp_legal_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_legal_case_entries_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_legal_case_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_legal_case_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int(10) unsigned NOT NULL,
  `kind` enum('opened','note','status','closed') NOT NULL,
  `from_status` varchar(20) DEFAULT NULL,
  `to_status` varchar(20) DEFAULT NULL,
  `note` varchar(1000) NOT NULL,
  `happened_on` date NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_legal_case_events_case` (`case_id`,`happened_on`),
  KEY `fk_erp_legal_case_events_created_by` (`created_by`),
  CONSTRAINT `fk_erp_legal_case_events_case` FOREIGN KEY (`case_id`) REFERENCES `erp_legal_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_legal_case_events_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_legal_cases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_legal_cases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `lawyer_id` int(10) unsigned DEFAULT NULL,
  `status` enum('preparing','filed','suspended','agreement','closed') NOT NULL DEFAULT 'preparing',
  `case_number` varchar(40) DEFAULT NULL COMMENT 'Número do processo, quando houver',
  `court` varchar(160) DEFAULT NULL,
  `opened_on` date NOT NULL,
  `claimed_cents` bigint(20) unsigned NOT NULL COMMENT 'Soma das contas na abertura, sem encargos',
  `closed_on` date DEFAULT NULL,
  `close_reason` varchar(255) DEFAULT NULL,
  `open_key` tinyint(4) GENERATED ALWAYS AS (case when `status` <> 'closed' then 1 else NULL end) STORED,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_legal_cases_open` (`unit_id`,`open_key`),
  KEY `idx_erp_legal_cases_list` (`condominium_id`,`status`),
  KEY `fk_erp_legal_cases_lawyer` (`lawyer_id`),
  KEY `fk_erp_legal_cases_created_by` (`created_by`),
  CONSTRAINT `fk_erp_legal_cases_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_legal_cases_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_legal_cases_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `erp_lawyers` (`id`),
  CONSTRAINT `fk_erp_legal_cases_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_linkable_areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_linkable_areas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `kind` varchar(10) NOT NULL,
  `identifier` varchar(40) NOT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `unit_label` varchar(40) DEFAULT NULL COMMENT 'Unidade em texto até o módulo Unidades (AP-006)',
  `location` varchar(120) DEFAULT NULL,
  `fraction` decimal(5,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_linkable_areas_condominium` (`condominium_id`,`deleted_at`,`status`),
  KEY `fk_erp_linkable_areas_created_by` (`created_by`),
  KEY `fk_erp_linkable_areas_updated_by` (`updated_by`),
  KEY `idx_erp_linkable_areas_unit` (`unit_id`),
  CONSTRAINT `fk_erp_linkable_areas_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_linkable_areas_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_linkable_areas_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `fk_erp_linkable_areas_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_local_holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_local_holidays` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `state` char(2) NOT NULL COMMENT 'UF',
  `city` varchar(100) DEFAULT NULL COMMENT 'Cidade como escrita no cadastro; NULL = o estado todo',
  `city_key` varchar(100) NOT NULL DEFAULT '' COMMENT 'Cidade normalizada; vazio = o estado todo',
  `month` tinyint(3) unsigned NOT NULL,
  `day` tinyint(3) unsigned NOT NULL,
  `year` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT '0 = todo ano',
  `name` varchar(120) NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_local_holidays_day` (`state`,`city_key`,`year`,`month`,`day`),
  KEY `idx_erp_local_holidays_place` (`state`,`city_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_lost_found`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_lost_found` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `item` varchar(160) NOT NULL,
  `location` varchar(120) DEFAULT NULL,
  `found_on` date NOT NULL,
  `found_by` varchar(160) DEFAULT NULL,
  `returned_on` date DEFAULT NULL,
  `returned_to` varchar(160) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','returned','discarded') NOT NULL DEFAULT 'active' COMMENT 'active = guardado',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_lost_found_condominium` (`condominium_id`,`status`,`deleted_at`),
  KEY `fk_erp_lost_found_created_by` (`created_by`),
  KEY `fk_erp_lost_found_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_lost_found_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_lost_found_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_lost_found_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_maintenances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_maintenances` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `service` varchar(160) NOT NULL,
  `supplier_id` int(10) unsigned DEFAULT NULL,
  `planned_on` date NOT NULL,
  `frequency` enum('unica','mensal','trimestral','semestral','anual') NOT NULL DEFAULT 'unica',
  `done_on` date DEFAULT NULL,
  `description` text DEFAULT NULL,
  `document_id` int(10) unsigned DEFAULT NULL,
  `previous_id` int(10) unsigned DEFAULT NULL,
  `status` enum('active','running','done','inactive') NOT NULL DEFAULT 'active' COMMENT 'active = agendada',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_maintenances_previous` (`previous_id`),
  KEY `idx_erp_maintenances_list` (`condominium_id`,`planned_on`,`deleted_at`),
  KEY `idx_erp_maintenances_supplier` (`supplier_id`),
  KEY `fk_erp_maintenances_document` (`document_id`),
  KEY `fk_erp_maintenances_created_by` (`created_by`),
  KEY `fk_erp_maintenances_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_maintenances_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_maintenances_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_maintenances_document` FOREIGN KEY (`document_id`) REFERENCES `erp_documents` (`id`),
  CONSTRAINT `fk_erp_maintenances_previous` FOREIGN KEY (`previous_id`) REFERENCES `erp_maintenances` (`id`),
  CONSTRAINT `fk_erp_maintenances_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`),
  CONSTRAINT `fk_erp_maintenances_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_management_contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_management_contracts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `monthly_fee_cents` bigint(20) unsigned NOT NULL COMMENT 'Valor mensal dos honorários',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `due_day` tinyint(3) unsigned NOT NULL CHECK (`due_day` between 1 and 28),
  `service_description` varchar(255) NOT NULL,
  `status` enum('active','suspended','ended') NOT NULL DEFAULT 'active',
  `notes` varchar(500) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_management_contracts_condominium` (`condominium_id`),
  KEY `fk_erp_management_contracts_created_by` (`created_by`),
  KEY `fk_erp_management_contracts_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_management_contracts_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_management_contracts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_management_contracts_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_meter_readings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_meter_readings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `meter_id` int(10) unsigned NOT NULL,
  `competence` char(7) NOT NULL COMMENT 'AAAA-MM',
  `read_on` date NOT NULL,
  `previous_reading` decimal(12,3) NOT NULL,
  `reading` decimal(12,3) NOT NULL,
  `consumption` decimal(12,3) NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL DEFAULT 0,
  `tariff_valid_from` date DEFAULT NULL COMMENT 'Vigência das faixas usadas no cálculo',
  `entry_id` int(10) unsigned DEFAULT NULL COMMENT 'Conta a receber gerada na cobrança',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_meter_readings_month` (`meter_id`,`competence`),
  UNIQUE KEY `uq_erp_meter_readings_entry` (`entry_id`),
  KEY `idx_erp_meter_readings_list` (`condominium_id`,`competence`),
  KEY `fk_erp_meter_readings_created_by` (`created_by`),
  KEY `fk_erp_meter_readings_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_meter_readings_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_meter_readings_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_meter_readings_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`),
  CONSTRAINT `fk_erp_meter_readings_meter` FOREIGN KEY (`meter_id`) REFERENCES `erp_meters` (`id`),
  CONSTRAINT `fk_erp_meter_readings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_meters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_meters` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `kind` enum('agua','gas','energia') NOT NULL,
  `number` varchar(40) NOT NULL,
  `installed_on` date DEFAULT NULL,
  `initial_reading` decimal(12,3) DEFAULT NULL COMMENT 'Leitura na instalação (base da primeira leitura)',
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_meters_condominium` (`condominium_id`,`kind`,`deleted_at`),
  KEY `idx_erp_meters_unit` (`unit_id`),
  KEY `fk_erp_meters_created_by` (`created_by`),
  KEY `fk_erp_meters_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_meters_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_meters_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_meters_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `fk_erp_meters_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_month_closings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_month_closings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `competence` char(7) NOT NULL COMMENT 'AAAA-MM',
  `status` enum('closed','reopened') NOT NULL,
  `revenue_cents` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'Receitas válidas da competência no fechamento',
  `expense_cents` bigint(20) unsigned NOT NULL DEFAULT 0,
  `open_revenue_cents` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'Parte ainda não recebida',
  `open_expense_cents` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT 'Parte ainda não paga',
  `summary_json` longtext DEFAULT NULL COMMENT 'Resumo por conta e por conta bancária no momento do fechamento',
  `closed_by` int(10) unsigned DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `reopened_by` int(10) unsigned DEFAULT NULL,
  `reopened_at` datetime DEFAULT NULL,
  `reopen_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_month_closings_competence` (`condominium_id`,`competence`),
  KEY `fk_erp_month_closings_closed_by` (`closed_by`),
  KEY `fk_erp_month_closings_reopened_by` (`reopened_by`),
  CONSTRAINT `fk_erp_month_closings_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_month_closings_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_month_closings_reopened_by` FOREIGN KEY (`reopened_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_move_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_move_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `allowed_days` varchar(80) NOT NULL COMMENT 'Ex.: segunda a sábado',
  `hours` varchar(80) DEFAULT NULL COMMENT 'Ex.: 8h às 17h',
  `min_advance_days` smallint(5) unsigned DEFAULT NULL,
  `fee_cents` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_move_settings_condominium` (`condominium_id`,`deleted_at`),
  KEY `fk_erp_move_settings_created_by` (`created_by`),
  KEY `fk_erp_move_settings_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_move_settings_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_move_settings_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_move_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_moves`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_moves` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `resident_name` varchar(160) NOT NULL,
  `kind` enum('entrada','saida') NOT NULL,
  `scheduled_on` date NOT NULL,
  `period` enum('manha','tarde') NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','done','inactive') NOT NULL DEFAULT 'active' COMMENT 'active = agendada, inactive = cancelada',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_moves_day` (`condominium_id`,`scheduled_on`,`period`,`deleted_at`),
  KEY `idx_erp_moves_unit` (`unit_id`),
  KEY `fk_erp_moves_created_by` (`created_by`),
  KEY `fk_erp_moves_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_moves_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_moves_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_moves_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `fk_erp_moves_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_nfe_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_nfe_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `access_key` char(44) NOT NULL,
  `import_id` int(10) unsigned NOT NULL,
  `supplier_id` int(10) unsigned NOT NULL,
  `number` varchar(9) NOT NULL,
  `series` varchar(3) NOT NULL,
  `issued_on` date NOT NULL,
  `total_cents` bigint(20) unsigned NOT NULL,
  `installments` smallint(5) unsigned NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_nfe_documents_key` (`condominium_id`,`access_key`),
  KEY `idx_erp_nfe_documents_import` (`import_id`),
  KEY `fk_erp_nfe_documents_supplier` (`supplier_id`),
  KEY `fk_erp_nfe_documents_created_by` (`created_by`),
  CONSTRAINT `fk_erp_nfe_documents_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_nfe_documents_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_nfe_documents_import` FOREIGN KEY (`import_id`) REFERENCES `erp_imports` (`id`),
  CONSTRAINT `fk_erp_nfe_documents_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_nfse_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_nfse_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `contract_id` int(10) unsigned NOT NULL,
  `condominium_id` int(10) unsigned NOT NULL,
  `competence` char(7) NOT NULL COMMENT 'AAAA-MM',
  `amount_cents` bigint(20) unsigned NOT NULL,
  `service_description` varchar(255) NOT NULL,
  `status` enum('requested','issued','failed','cancelled') NOT NULL DEFAULT 'requested',
  `open_key` tinyint(3) unsigned GENERATED ALWAYS AS (if(`status` = 'cancelled',NULL,1)) STORED,
  `nfse_number` varchar(20) DEFAULT NULL,
  `access_key` varchar(60) DEFAULT NULL COMMENT 'Chave de acesso devolvida pela API Nacional',
  `cancel_reason` varchar(255) DEFAULT NULL,
  `requested_by` int(10) unsigned DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_nfse_requests_open` (`contract_id`,`competence`,`open_key`),
  KEY `idx_erp_nfse_requests_condominium` (`condominium_id`,`competence`),
  KEY `fk_erp_nfse_requests_requested_by` (`requested_by`),
  KEY `fk_erp_nfse_requests_cancelled_by` (`cancelled_by`),
  CONSTRAINT `fk_erp_nfse_requests_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_nfse_requests_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_nfse_requests_contract` FOREIGN KEY (`contract_id`) REFERENCES `erp_management_contracts` (`id`),
  CONSTRAINT `fk_erp_nfse_requests_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_notification_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_notification_deliveries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `outbox_id` int(10) unsigned NOT NULL,
  `delivery_mode` enum('log','sandbox','production') NOT NULL,
  `recipient_hash` char(64) NOT NULL COMMENT 'sha256 do e-mail do morador (sem o e-mail em claro)',
  `recipient_masked` varchar(120) NOT NULL COMMENT 'Ex.: a***@exemplo.com',
  `sent_to` varchar(190) DEFAULT NULL COMMENT 'Endereço efetivo: a caixa de teste no sandbox; vazio no modo log',
  `mail_queue_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_notification_deliveries` (`outbox_id`,`recipient_hash`),
  KEY `idx_erp_notification_deliveries_queue` (`mail_queue_id`),
  CONSTRAINT `fk_erp_notification_deliveries_outbox` FOREIGN KEY (`outbox_id`) REFERENCES `erp_notification_outbox` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_notification_outbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_notification_outbox` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `event_type` varchar(60) NOT NULL COMMENT 'Ex.: waitlist.called',
  `event_key` varchar(120) NOT NULL COMMENT 'Identifica o evento de origem (idempotência)',
  `subject_table` varchar(60) NOT NULL,
  `subject_id` int(10) unsigned NOT NULL,
  `payload_json` text NOT NULL COMMENT 'Dados para o modelo do aviso, sem segredos',
  `status` enum('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  `delivery_mode` enum('log','sandbox','production') DEFAULT NULL,
  `recipients_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `available_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT 'Próxima tentativa',
  `processed_at` datetime DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_notification_outbox_event` (`event_key`),
  KEY `idx_erp_notification_outbox_queue` (`status`,`available_at`),
  KEY `idx_erp_notification_outbox_subject` (`subject_table`,`subject_id`),
  KEY `fk_erp_notification_outbox_created_by` (`created_by`),
  KEY `idx_erp_notification_outbox_report` (`condominium_id`,`event_type`,`created_at`),
  CONSTRAINT `fk_erp_notification_outbox_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_notification_outbox_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_occurrences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_occurrences` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `subject` varchar(160) NOT NULL,
  `priority` enum('baixa','media','alta') NOT NULL DEFAULT 'media',
  `occurred_on` date NOT NULL,
  `description` text NOT NULL,
  `document_id` int(10) unsigned DEFAULT NULL,
  `ticket_id` int(10) unsigned DEFAULT NULL COMMENT 'Chamado aberto na fila do Help Desk (ADR 0034)',
  `status` enum('active','running','done') NOT NULL DEFAULT 'active' COMMENT 'active = aberta',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_occurrences_ticket` (`ticket_id`),
  KEY `idx_erp_occurrences_list` (`condominium_id`,`status`,`occurred_on`,`deleted_at`),
  KEY `idx_erp_occurrences_unit` (`unit_id`),
  KEY `fk_erp_occurrences_document` (`document_id`),
  KEY `fk_erp_occurrences_created_by` (`created_by`),
  KEY `fk_erp_occurrences_updated_by` (`updated_by`),
  KEY `idx_erp_occurrences_period` (`condominium_id`,`occurred_on`),
  CONSTRAINT `fk_erp_occurrences_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_occurrences_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_occurrences_document` FOREIGN KEY (`document_id`) REFERENCES `erp_documents` (`id`),
  CONSTRAINT `fk_erp_occurrences_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `fk_erp_occurrences_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_partner_agencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_partner_agencies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `document` varchar(14) NOT NULL COMMENT 'CNPJ, só dígitos',
  `email` varchar(254) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_partner_agencies_document` (`document`),
  KEY `fk_erp_partner_agencies_created_by` (`created_by`),
  KEY `fk_erp_partner_agencies_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_partner_agencies_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_partner_agencies_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_partner_agency_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_partner_agency_users` (
  `user_id` int(10) unsigned NOT NULL,
  `agency_id` int(10) unsigned NOT NULL,
  `linked_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  KEY `idx_erp_partner_agency_users_agency` (`agency_id`),
  KEY `fk_erp_partner_agency_users_linked_by` (`linked_by`),
  CONSTRAINT `fk_erp_partner_agency_users_agency` FOREIGN KEY (`agency_id`) REFERENCES `erp_partner_agencies` (`id`),
  CONSTRAINT `fk_erp_partner_agency_users_linked_by` FOREIGN KEY (`linked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_partner_agency_users_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_patrimonial_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_patrimonial_entries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `entry_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL COMMENT 'Sempre positivo',
  `debit_account_id` int(10) unsigned NOT NULL,
  `credit_account_id` int(10) unsigned NOT NULL,
  `bank_account_id` int(10) unsigned DEFAULT NULL COMMENT 'Referência: conta bancária onde o valor está',
  `linked_entry_id` int(10) unsigned DEFAULT NULL COMMENT 'Referência: receita ou despesa que originou',
  `import_id` int(10) unsigned DEFAULT NULL COMMENT 'Importação de origem (ADR 0171)',
  `status` enum('active','reversed') NOT NULL DEFAULT 'active' COMMENT 'reversed = estornado (o estorno é outro lançamento)',
  `reversal_of_id` int(10) unsigned DEFAULT NULL COMMENT 'Este lançamento estorna aquele',
  `reversal_reason` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_patrimonial_entries_reversal` (`reversal_of_id`),
  KEY `idx_erp_patrimonial_entries_date` (`condominium_id`,`entry_date`),
  KEY `idx_erp_patrimonial_entries_debit` (`debit_account_id`),
  KEY `idx_erp_patrimonial_entries_credit` (`credit_account_id`),
  KEY `fk_erp_patrimonial_entries_bank` (`bank_account_id`),
  KEY `fk_erp_patrimonial_entries_entry` (`linked_entry_id`),
  KEY `fk_erp_patrimonial_entries_created_by` (`created_by`),
  KEY `idx_erp_patrimonial_entries_import` (`import_id`),
  CONSTRAINT `fk_erp_patrimonial_entries_bank` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`),
  CONSTRAINT `fk_erp_patrimonial_entries_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_patrimonial_entries_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_patrimonial_entries_credit` FOREIGN KEY (`credit_account_id`) REFERENCES `erp_chart_accounts` (`id`),
  CONSTRAINT `fk_erp_patrimonial_entries_debit` FOREIGN KEY (`debit_account_id`) REFERENCES `erp_chart_accounts` (`id`),
  CONSTRAINT `fk_erp_patrimonial_entries_entry` FOREIGN KEY (`linked_entry_id`) REFERENCES `erp_entries` (`id`),
  CONSTRAINT `fk_erp_patrimonial_entries_reversal` FOREIGN KEY (`reversal_of_id`) REFERENCES `erp_patrimonial_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_patrimonial_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_patrimonial_imports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `idempotency_key` varchar(80) NOT NULL,
  `sha256` char(64) NOT NULL,
  `original_name` varchar(160) NOT NULL,
  `content` mediumblob NOT NULL,
  `status` enum('previewed','applied') NOT NULL DEFAULT 'previewed',
  `rows_count` int(10) unsigned NOT NULL DEFAULT 0,
  `error_count` int(10) unsigned NOT NULL DEFAULT 0,
  `summary_json` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `applied_by` int(10) unsigned DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_patrimonial_imports_key` (`condominium_id`,`idempotency_key`),
  KEY `idx_erp_patrimonial_imports_list` (`condominium_id`,`status`,`created_at`),
  KEY `fk_erp_patrimonial_imports_created_by` (`created_by`),
  KEY `fk_erp_patrimonial_imports_applied_by` (`applied_by`),
  CONSTRAINT `fk_erp_patrimonial_imports_applied_by` FOREIGN KEY (`applied_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_patrimonial_imports_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_patrimonial_imports_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_pets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_pets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `unit_label` varchar(40) NOT NULL,
  `name` varchar(80) NOT NULL,
  `species` varchar(10) NOT NULL,
  `size` varchar(10) DEFAULT NULL,
  `breed` varchar(80) DEFAULT NULL,
  `tutor_name` varchar(160) DEFAULT NULL,
  `vaccinated` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_pets_condominium` (`condominium_id`,`deleted_at`,`status`),
  KEY `fk_erp_pets_created_by` (`created_by`),
  KEY `fk_erp_pets_updated_by` (`updated_by`),
  KEY `idx_erp_pets_unit` (`unit_id`),
  CONSTRAINT `fk_erp_pets_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_pets_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_pets_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `fk_erp_pets_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_pouches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_pouches` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `sent_on` date NOT NULL,
  `courier` varchar(160) NOT NULL COMMENT 'Portador',
  `documents_count` smallint(5) unsigned NOT NULL,
  `contents` text NOT NULL COMMENT 'Lista dos documentos do malote',
  `received_on` date DEFAULT NULL,
  `checked_on` date DEFAULT NULL,
  `document_id` int(10) unsigned DEFAULT NULL COMMENT 'Protocolo do malote no Disco Virtual',
  `notes` text DEFAULT NULL,
  `status` enum('active','received','checked') NOT NULL DEFAULT 'active' COMMENT 'active = enviado',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_pouches_list` (`condominium_id`,`sent_on`,`deleted_at`),
  KEY `idx_erp_pouches_document` (`document_id`),
  KEY `fk_erp_pouches_created_by` (`created_by`),
  KEY `fk_erp_pouches_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_pouches_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_pouches_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_pouches_document` FOREIGN KEY (`document_id`) REFERENCES `erp_documents` (`id`),
  CONSTRAINT `fk_erp_pouches_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_price_indexes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_price_indexes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `competence` char(7) NOT NULL COMMENT 'AAAA-MM',
  `rate_percent` decimal(9,6) NOT NULL COMMENT 'Variação do mês em %, pode ser negativa',
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_price_indexes_month` (`code`,`competence`),
  KEY `fk_erp_price_indexes_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_price_indexes_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_recurring_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_recurring_entries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `nature` enum('receita','despesa') NOT NULL,
  `chart_account_id` int(10) unsigned NOT NULL,
  `cost_center_id` int(10) unsigned DEFAULT NULL,
  `bank_account_id` int(10) unsigned DEFAULT NULL COMMENT 'Conta sugerida; o lançamento nasce em aberto',
  `supplier_id` int(10) unsigned DEFAULT NULL,
  `description` varchar(160) NOT NULL,
  `amount_cents` bigint(20) unsigned NOT NULL,
  `day_of_month` tinyint(3) unsigned NOT NULL COMMENT 'Dia de vencimento (1 a 28)',
  `starts_competence` char(7) NOT NULL COMMENT 'Primeira competência AAAA-MM',
  `ends_competence` char(7) DEFAULT NULL COMMENT 'Última competência AAAA-MM; vazio = sem fim',
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_recurring_entries_condominium` (`condominium_id`,`deleted_at`,`status`),
  KEY `fk_erp_recurring_entries_account` (`chart_account_id`),
  KEY `fk_erp_recurring_entries_cost_center` (`cost_center_id`),
  KEY `fk_erp_recurring_entries_bank_account` (`bank_account_id`),
  KEY `fk_erp_recurring_entries_supplier` (`supplier_id`),
  KEY `fk_erp_recurring_entries_created_by` (`created_by`),
  KEY `fk_erp_recurring_entries_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_recurring_entries_account` FOREIGN KEY (`chart_account_id`) REFERENCES `erp_chart_accounts` (`id`),
  CONSTRAINT `fk_erp_recurring_entries_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`),
  CONSTRAINT `fk_erp_recurring_entries_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_recurring_entries_cost_center` FOREIGN KEY (`cost_center_id`) REFERENCES `erp_cost_centers` (`id`),
  CONSTRAINT `fk_erp_recurring_entries_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_recurring_entries_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`),
  CONSTRAINT `fk_erp_recurring_entries_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_reservations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `common_area_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `resident_name` varchar(160) NOT NULL,
  `reserved_on` date NOT NULL,
  `starts_time` time DEFAULT NULL,
  `ends_time` time DEFAULT NULL,
  `fee_cents` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','confirmed','inactive') NOT NULL DEFAULT 'active' COMMENT 'active = aguardando aprovação',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_reservations_area_day` (`common_area_id`,`reserved_on`,`deleted_at`),
  KEY `idx_erp_reservations_list` (`condominium_id`,`status`,`reserved_on`),
  KEY `idx_erp_reservations_unit` (`unit_id`),
  KEY `fk_erp_reservations_created_by` (`created_by`),
  KEY `fk_erp_reservations_updated_by` (`updated_by`),
  KEY `idx_erp_reservations_period` (`condominium_id`,`reserved_on`),
  CONSTRAINT `fk_erp_reservations_area` FOREIGN KEY (`common_area_id`) REFERENCES `erp_common_areas` (`id`),
  CONSTRAINT `fk_erp_reservations_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_reservations_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_reservations_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `fk_erp_reservations_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_resident_app_challenges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_resident_app_challenges` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `challenge_hash` char(64) NOT NULL,
  `device_name` varchar(80) DEFAULT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_resident_app_challenges_hash` (`challenge_hash`),
  KEY `idx_erp_resident_app_challenges_user` (`user_id`),
  CONSTRAINT `fk_erp_resident_app_challenges_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_resident_app_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_resident_app_profiles` (
  `user_id` int(10) unsigned NOT NULL,
  `photo_path` varchar(120) DEFAULT NULL COMMENT 'Relativo a storage/private/resident-photos; NULL = sem foto',
  `onboarded_at` datetime DEFAULT NULL COMMENT 'Quando concluiu (ou pulou) as boas-vindas; NULL = mostrar no próximo acesso',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_resident_app_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_resident_app_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'sha256 do token Bearer',
  `device_name` varchar(80) DEFAULT NULL COMMENT 'Nome informado pelo app, só para o morador reconhecer o aparelho',
  `created_at` datetime NOT NULL,
  `created_at_ms` bigint(20) unsigned NOT NULL COMMENT 'Instante do login (ms), comparado com o logout global (ADR 0036)',
  `last_used_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoke_reason` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_resident_app_tokens_hash` (`token_hash`),
  KEY `idx_erp_resident_app_tokens_user` (`user_id`,`revoked_at`),
  CONSTRAINT `fk_erp_resident_app_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_resident_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_resident_invitations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `resident_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'sha256 do token; o token só vai no aviso',
  `status` enum('pending','accepted','revoked') NOT NULL DEFAULT 'pending',
  `expires_at` datetime NOT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `accepted_user_id` int(10) unsigned DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_resident_invitations_token` (`token_hash`),
  KEY `idx_erp_resident_invitations_resident` (`resident_id`,`status`),
  KEY `idx_erp_resident_invitations_condominium` (`condominium_id`,`created_at`),
  KEY `fk_erp_resident_invitations_user` (`accepted_user_id`),
  KEY `fk_erp_resident_invitations_created_by` (`created_by`),
  CONSTRAINT `fk_erp_resident_invitations_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_resident_invitations_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_resident_invitations_resident` FOREIGN KEY (`resident_id`) REFERENCES `erp_residents` (`id`),
  CONSTRAINT `fk_erp_resident_invitations_user` FOREIGN KEY (`accepted_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_resident_portal_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_resident_portal_permissions` (
  `condominium_id` int(10) unsigned NOT NULL,
  `feature` enum('boletos','reservations','occurrences','announcements','documents','accountability','deliveries','visitors','assemblies','household') NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`condominium_id`,`feature`),
  KEY `fk_erp_resident_portal_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_resident_portal_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_resident_portal_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_residents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_residents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `unit_label` varchar(40) NOT NULL COMMENT 'Unidade em texto até o módulo Unidades (AP-006)',
  `relationship` enum('owner','tenant','resident','financial') NOT NULL,
  `name` varchar(160) NOT NULL,
  `document` varchar(14) DEFAULT NULL COMMENT 'CPF ou CNPJ, somente dígitos',
  `email` varchar(254) DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL COMMENT 'Conta criada no aceite do convite (ADR 0092)',
  `phone` varchar(13) DEFAULT NULL,
  `starts_on` date DEFAULT NULL,
  `ends_on` date DEFAULT NULL,
  `billing_by_email` tinyint(1) NOT NULL DEFAULT 0,
  `notices_opt_out` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_residents_user` (`user_id`),
  KEY `idx_erp_residents_document` (`document`),
  KEY `idx_erp_residents_condominium` (`condominium_id`,`deleted_at`,`status`),
  KEY `fk_erp_residents_created_by` (`created_by`),
  KEY `fk_erp_residents_updated_by` (`updated_by`),
  KEY `idx_erp_residents_unit` (`unit_id`),
  CONSTRAINT `fk_erp_residents_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_residents_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_residents_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `fk_erp_residents_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_residents_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL = padrão da Connect',
  `setting_key` varchar(80) NOT NULL,
  `value_json` varchar(1000) NOT NULL,
  `scope_key` int(10) unsigned GENERATED ALWAYS AS (coalesce(`condominium_id`,0)) STORED,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_settings_key` (`scope_key`,`setting_key`),
  KEY `fk_erp_settings_condominium` (`condominium_id`),
  KEY `fk_erp_settings_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_settings_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_suppliers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `legal_name` varchar(160) NOT NULL,
  `document` varchar(14) NOT NULL COMMENT 'CNPJ ou CPF, somente dígitos',
  `trade_name` varchar(160) DEFAULT NULL,
  `category` varchar(20) NOT NULL,
  `email` varchar(254) DEFAULT NULL,
  `phone` varchar(13) DEFAULT NULL,
  `bank_code` char(3) DEFAULT NULL,
  `bank_agency` varchar(10) DEFAULT NULL,
  `bank_account` varchar(20) DEFAULT NULL,
  `pix_key` varchar(140) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_suppliers_document` (`document`),
  KEY `idx_erp_suppliers_status` (`deleted_at`,`status`),
  KEY `fk_erp_suppliers_created_by` (`created_by`),
  KEY `fk_erp_suppliers_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_suppliers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_suppliers_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_tariffs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_tariffs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned DEFAULT NULL,
  `kind` enum('agua','gas','energia') NOT NULL,
  `name` varchar(80) NOT NULL,
  `from_amount` decimal(12,3) NOT NULL DEFAULT 0.000,
  `to_amount` decimal(12,3) DEFAULT NULL,
  `price_cents` bigint(20) unsigned NOT NULL COMMENT 'Preço por m³ (ou kWh) em centavos',
  `valid_from` date NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_tariffs_lookup` (`kind`,`condominium_id`,`valid_from`,`deleted_at`),
  KEY `fk_erp_tariffs_condominium` (`condominium_id`),
  KEY `fk_erp_tariffs_created_by` (`created_by`),
  KEY `fk_erp_tariffs_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_tariffs_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_tariffs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_tariffs_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_taxes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_taxes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned DEFAULT NULL,
  `kind` enum('iss','irrf','inss','pis_cofins_csll','outro') NOT NULL,
  `name` varchar(80) NOT NULL,
  `rate` decimal(5,2) NOT NULL,
  `applies_to` enum('servicos','materiais','todos') NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_taxes_list` (`condominium_id`,`deleted_at`,`kind`),
  KEY `fk_erp_taxes_created_by` (`created_by`),
  KEY `fk_erp_taxes_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_taxes_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_taxes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_taxes_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_unit_document_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_unit_document_entries` (
  `document_id` int(10) unsigned NOT NULL,
  `entry_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`document_id`,`entry_id`),
  KEY `idx_erp_unit_document_entries_entry` (`entry_id`),
  CONSTRAINT `fk_erp_unit_document_entries_document` FOREIGN KEY (`document_id`) REFERENCES `erp_unit_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_unit_document_entries_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_unit_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_unit_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `kind` enum('debt_certificate','delinquency_declaration','quittance','consent_letter','receipt') NOT NULL,
  `as_of` date NOT NULL COMMENT 'Data de referência',
  `reference` varchar(40) DEFAULT NULL COMMENT 'Competência final (quitação) ou finalidade (anuência)',
  `result` enum('clear','debts') NOT NULL COMMENT 'clear = nada consta / quitado',
  `total_cents` bigint(20) unsigned NOT NULL DEFAULT 0,
  `payload_json` longtext NOT NULL,
  `verification_code` char(16) NOT NULL,
  `status` enum('issued','cancelled') NOT NULL DEFAULT 'issued',
  `issued_by` int(10) unsigned DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_unit_documents_code` (`verification_code`),
  KEY `idx_erp_unit_documents_list` (`condominium_id`,`kind`,`created_at`),
  KEY `idx_erp_unit_documents_unit` (`unit_id`),
  KEY `fk_erp_unit_documents_issued_by` (`issued_by`),
  KEY `fk_erp_unit_documents_cancelled_by` (`cancelled_by`),
  CONSTRAINT `fk_erp_unit_documents_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_unit_documents_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_unit_documents_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_unit_documents_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_unit_move_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_unit_move_terms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned DEFAULT NULL COMMENT 'Vazio = texto geral da Connect',
  `scope_key` int(10) unsigned GENERATED ALWAYS AS (ifnull(`condominium_id`,0)) STORED,
  `kind` enum('entrada','saida') NOT NULL,
  `body` text NOT NULL COMMENT 'Texto puro com palavras-chave (@condominio, @unidade ...)',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_unit_move_terms_scope` (`scope_key`,`kind`),
  KEY `idx_erp_unit_move_terms_condominium` (`condominium_id`),
  KEY `fk_erp_unit_move_terms_created_by` (`created_by`),
  KEY `fk_erp_unit_move_terms_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_unit_move_terms_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_unit_move_terms_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_unit_move_terms_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_units` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `block` varchar(20) DEFAULT NULL COMMENT 'Bloco ou torre; vazio quando o condomínio não tem blocos',
  `number` varchar(40) NOT NULL COMMENT 'Identificação da unidade (101, Casa 3, Loja 2)',
  `cib` varchar(8) DEFAULT NULL COMMENT 'Código CIB, 7 caracteres + DV, sem traço',
  `label` varchar(64) GENERATED ALWAYS AS (concat_ws(' - ',nullif(`block`,''),`number`)) STORED,
  `type` enum('apartamento','casa','sala','loja','garagem','outro') NOT NULL DEFAULT 'apartamento',
  `floor` varchar(10) DEFAULT NULL,
  `ideal_fraction` decimal(9,6) DEFAULT NULL COMMENT 'Fração ideal (0 a 1)',
  `private_area` decimal(10,2) DEFAULT NULL COMMENT 'Área privativa em m²',
  `is_vacant` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Unidade vazia, sem morador (marcada pela equipe)',
  `due_day` tinyint(3) unsigned DEFAULT NULL COMMENT 'Dia padrão de vencimento (1 a 31, mês curto = último dia)',
  `apportionment_exempt` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Isenta de rateio: fica fora das faturas rateadas',
  `postage_cents` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Porte postal por boleto, 0 = não cobra',
  `postage_payer` enum('ambos','proprietario','inquilino') NOT NULL DEFAULT 'ambos',
  `reads_gas` tinyint(1) NOT NULL DEFAULT 0,
  `reads_water` tinyint(1) NOT NULL DEFAULT 0,
  `reads_power` tinyint(1) NOT NULL DEFAULT 0,
  `block_bill_merge` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Fica fora do boleto único de várias unidades',
  `billing_responsible` enum('nao_definido','proprietario','inquilino') NOT NULL DEFAULT 'nao_definido',
  `bill_address` enum('predio','correios') NOT NULL DEFAULT 'predio' COMMENT 'Endereço do boleto',
  `bill_email_to` enum('responsavel','proprietario','inquilino','ambos') NOT NULL DEFAULT 'responsavel',
  `partner_agency_id` int(10) unsigned DEFAULT NULL COMMENT 'Imobiliária que administra a unidade',
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_units_condominium` (`condominium_id`,`deleted_at`,`status`),
  KEY `idx_erp_units_label` (`condominium_id`,`label`),
  KEY `fk_erp_units_created_by` (`created_by`),
  KEY `fk_erp_units_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_units_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_units_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_units_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_useful_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_useful_contacts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned DEFAULT NULL,
  `service` varchar(20) NOT NULL,
  `name` varchar(160) NOT NULL,
  `phone` varchar(13) NOT NULL,
  `email` varchar(254) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_useful_contacts_condominium` (`condominium_id`,`deleted_at`,`status`),
  KEY `fk_erp_useful_contacts_created_by` (`created_by`),
  KEY `fk_erp_useful_contacts_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_useful_contacts_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_useful_contacts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_useful_contacts_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_vehicles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_vehicles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `unit_label` varchar(40) NOT NULL,
  `plate` char(7) NOT NULL,
  `kind` varchar(12) NOT NULL,
  `model` varchar(80) DEFAULT NULL,
  `color` varchar(30) DEFAULT NULL,
  `parking_spot` varchar(20) DEFAULT NULL,
  `owner_name` varchar(160) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_vehicles_plate` (`plate`),
  KEY `idx_erp_vehicles_condominium` (`condominium_id`,`deleted_at`,`status`),
  KEY `fk_erp_vehicles_created_by` (`created_by`),
  KEY `fk_erp_vehicles_updated_by` (`updated_by`),
  KEY `idx_erp_vehicles_unit` (`unit_id`),
  CONSTRAINT `fk_erp_vehicles_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_vehicles_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_vehicles_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `fk_erp_vehicles_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_visit_forecasts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_visit_forecasts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `visitor_name` varchar(160) NOT NULL,
  `visitor_document` varchar(30) DEFAULT NULL,
  `expected_on` date NOT NULL,
  `expected_until` date DEFAULT NULL,
  `authorized_by` varchar(160) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','done','inactive') NOT NULL DEFAULT 'active' COMMENT 'active = prevista, done = realizada, inactive = cancelada',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_visit_forecasts_day` (`condominium_id`,`expected_on`,`deleted_at`),
  KEY `idx_erp_visit_forecasts_unit` (`unit_id`),
  KEY `fk_erp_visit_forecasts_created_by` (`created_by`),
  KEY `fk_erp_visit_forecasts_updated_by` (`updated_by`),
  CONSTRAINT `fk_erp_visit_forecasts_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_visit_forecasts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_visit_forecasts_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `fk_erp_visit_forecasts_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_visitors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_visitors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `unit_label` varchar(40) NOT NULL,
  `name` varchar(160) NOT NULL,
  `document` varchar(20) DEFAULT NULL COMMENT 'CPF (dígitos) ou RG/outro (alfanumérico)',
  `phone` varchar(13) DEFAULT NULL,
  `permanent_access` tinyint(1) NOT NULL DEFAULT 0,
  `valid_until` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_visitors_condominium` (`condominium_id`,`deleted_at`,`status`),
  KEY `fk_erp_visitors_created_by` (`created_by`),
  KEY `fk_erp_visitors_updated_by` (`updated_by`),
  KEY `idx_erp_visitors_unit` (`unit_id`),
  CONSTRAINT `fk_erp_visitors_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_visitors_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_visitors_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `fk_erp_visitors_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `erp_waitlist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_waitlist` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `common_area_id` int(10) unsigned NOT NULL,
  `unit_id` int(10) unsigned NOT NULL,
  `resident_name` varchar(160) NOT NULL,
  `desired_on` date NOT NULL,
  `notes` text DEFAULT NULL,
  `called_at` datetime DEFAULT NULL,
  `called_by` int(10) unsigned DEFAULT NULL,
  `called_for_reservation_id` int(10) unsigned DEFAULT NULL,
  `status` enum('active','served','inactive') NOT NULL DEFAULT 'active' COMMENT 'active = aguardando, inactive = desistiu',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_waitlist_area_day` (`common_area_id`,`desired_on`,`status`,`created_at`),
  KEY `idx_erp_waitlist_unit` (`unit_id`),
  KEY `fk_erp_waitlist_condominium` (`condominium_id`),
  KEY `fk_erp_waitlist_created_by` (`created_by`),
  KEY `fk_erp_waitlist_updated_by` (`updated_by`),
  KEY `fk_erp_waitlist_called_by` (`called_by`),
  KEY `fk_erp_waitlist_called_for` (`called_for_reservation_id`),
  CONSTRAINT `fk_erp_waitlist_area` FOREIGN KEY (`common_area_id`) REFERENCES `erp_common_areas` (`id`),
  CONSTRAINT `fk_erp_waitlist_called_by` FOREIGN KEY (`called_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_waitlist_called_for` FOREIGN KEY (`called_for_reservation_id`) REFERENCES `erp_reservations` (`id`),
  CONSTRAINT `fk_erp_waitlist_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_erp_waitlist_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_waitlist_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `fk_erp_waitlist_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `faq_channels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `faq_channels` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `channel` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `faq_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `faq_questions` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `channel_id` int(11) unsigned NOT NULL,
  `question` varchar(255) NOT NULL DEFAULT '',
  `response` text NOT NULL,
  `support_link` varchar(255) DEFAULT NULL,
  `order_by` int(11) unsigned DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `channel_id` (`channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inspection_answers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspection_answers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `inspection_id` int(10) unsigned NOT NULL,
  `position` smallint(5) unsigned NOT NULL,
  `section` varchar(80) NOT NULL,
  `label` varchar(200) NOT NULL,
  `requires_photo` tinyint(1) NOT NULL DEFAULT 0,
  `result` enum('pending','ok','issue','not_applicable') NOT NULL DEFAULT 'pending',
  `note` varchar(1000) DEFAULT NULL,
  `answered_by` int(10) unsigned DEFAULT NULL,
  `answered_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_insp_answers_inspection` (`inspection_id`,`position`),
  KEY `fk_insp_answers_user` (`answered_by`),
  CONSTRAINT `fk_insp_answers_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `inspections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_insp_answers_user` FOREIGN KEY (`answered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inspection_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspection_photos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `inspection_id` int(10) unsigned NOT NULL,
  `answer_id` int(10) unsigned NOT NULL,
  `path` varchar(255) NOT NULL COMMENT 'Relativo a storage/private',
  `mime` varchar(40) NOT NULL,
  `size_bytes` int(10) unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `width` smallint(5) unsigned NOT NULL,
  `height` smallint(5) unsigned NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspection_photos_path` (`path`),
  KEY `idx_insp_photos_answer` (`answer_id`),
  KEY `idx_insp_photos_inspection` (`inspection_id`),
  KEY `fk_insp_photos_user` (`uploaded_by`),
  CONSTRAINT `fk_insp_photos_answer` FOREIGN KEY (`answer_id`) REFERENCES `inspection_answers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_insp_photos_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `inspections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_insp_photos_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inspection_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspection_plans` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `template_id` int(10) unsigned NOT NULL,
  `frequency` enum('weekly','biweekly','monthly','quarterly','semiannual','yearly') NOT NULL,
  `starts_on` date NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspection_plans` (`condominium_id`,`template_id`),
  KEY `idx_inspection_plans_template` (`template_id`),
  KEY `fk_iplan_user` (`created_by`),
  CONSTRAINT `fk_iplan_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_iplan_template` FOREIGN KEY (`template_id`) REFERENCES `inspection_templates` (`id`),
  CONSTRAINT `fk_iplan_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inspection_report_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspection_report_deliveries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `inspection_id` int(10) unsigned NOT NULL,
  `recipient_email` varchar(190) NOT NULL,
  `recipient_name` varchar(160) DEFAULT NULL,
  `sent_by` int(10) unsigned DEFAULT NULL,
  `queued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_insp_deliveries_inspection` (`inspection_id`),
  KEY `fk_insp_deliveries_user` (`sent_by`),
  CONSTRAINT `fk_insp_deliveries_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `inspections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_insp_deliveries_user` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inspection_template_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspection_template_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `template_id` int(10) unsigned NOT NULL,
  `position` smallint(5) unsigned NOT NULL,
  `section` varchar(80) NOT NULL DEFAULT 'Geral',
  `label` varchar(200) NOT NULL,
  `requires_photo` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_itpl_items_template` (`template_id`,`position`),
  CONSTRAINT `fk_itpl_items_template` FOREIGN KEY (`template_id`) REFERENCES `inspection_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inspection_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspection_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `frequency` enum('weekly','biweekly','monthly','quarterly','semiannual','yearly') NOT NULL DEFAULT 'monthly',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspection_templates_name` (`name`),
  KEY `idx_inspection_templates_status` (`status`),
  KEY `fk_itpl_user` (`created_by`),
  CONSTRAINT `fk_itpl_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inspections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int(10) unsigned NOT NULL,
  `template_id` int(10) unsigned NOT NULL,
  `plan_id` int(10) unsigned DEFAULT NULL,
  `inspector_id` int(10) unsigned DEFAULT NULL,
  `status` enum('in_progress','completed','cancelled') NOT NULL DEFAULT 'in_progress',
  `template_name` varchar(120) NOT NULL COMMENT 'Cópia do nome do modelo no início da vistoria',
  `checkin_at` datetime NOT NULL,
  `checkin_latitude` decimal(10,7) DEFAULT NULL,
  `checkin_longitude` decimal(10,7) DEFAULT NULL,
  `checkin_accuracy_m` decimal(8,1) DEFAULT NULL,
  `checkin_distance_m` decimal(10,1) DEFAULT NULL,
  `checkin_status` enum('inside','outside','no_reference','no_signal') NOT NULL,
  `checkin_note` varchar(500) DEFAULT NULL COMMENT 'Justificativa quando fora do raio ou sem sinal',
  `summary` text DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inspections_condominium` (`condominium_id`,`status`,`checkin_at`),
  KEY `idx_inspections_plan` (`plan_id`,`status`,`completed_at`),
  KEY `idx_inspections_inspector` (`inspector_id`),
  KEY `fk_insp_template` (`template_id`),
  CONSTRAINT `fk_insp_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_insp_inspector` FOREIGN KEY (`inspector_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_insp_plan` FOREIGN KEY (`plan_id`) REFERENCES `inspection_plans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_insp_template` FOREIGN KEY (`template_id`) REFERENCES `inspection_templates` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mail_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_queue` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `notification_message_id` int(10) unsigned DEFAULT NULL,
  `proposal_response_id` int(10) unsigned DEFAULT NULL,
  `source_log_id` int(11) unsigned DEFAULT NULL,
  `users_id` int(10) unsigned DEFAULT NULL,
  `subject` varchar(255) NOT NULL DEFAULT '',
  `body` text NOT NULL,
  `attachments_json` longtext DEFAULT NULL,
  `from_email` varchar(255) NOT NULL DEFAULT '',
  `from_name` varchar(255) NOT NULL DEFAULT '',
  `recipient_email` varchar(255) NOT NULL DEFAULT '',
  `recipient_name` varchar(255) NOT NULL DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `scheduled_at` datetime DEFAULT NULL,
  `attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `max_attempts` smallint(5) unsigned NOT NULL DEFAULT 3,
  `last_attempt_at` datetime DEFAULT NULL,
  `next_attempt_at` datetime DEFAULT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `failed_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mail_queue_dispatch` (`status`,`scheduled_at`,`next_attempt_at`),
  KEY `idx_mail_queue_message` (`notification_message_id`),
  KEY `idx_mail_queue_user` (`users_id`),
  KEY `idx_mail_queue_source_log` (`source_log_id`,`users_id`),
  KEY `idx_mail_queue_proposal_response` (`proposal_response_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `module_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `module_migrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `module_slug` varchar(100) NOT NULL,
  `migration` varchar(190) NOT NULL,
  `checksum` char(64) NOT NULL,
  `batch` int(10) unsigned NOT NULL DEFAULT 1,
  `executed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_module_migration` (`module_slug`,`migration`),
  KEY `idx_module_migrations_slug` (`module_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `modules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) NOT NULL,
  `name` varchar(140) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `available_version` varchar(30) NOT NULL DEFAULT '1.0.0',
  `installed_version` varchar(30) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `is_core` tinyint(1) NOT NULL DEFAULT 0,
  `installed_by` int(10) unsigned DEFAULT NULL,
  `installed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_modules_slug` (`slug`),
  KEY `idx_modules_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `movesos_schema_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `movesos_schema_migrations` (
  `migration` varchar(190) NOT NULL,
  `checksum` char(64) NOT NULL,
  `batch` int(10) unsigned NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `movesos_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `movesos_versions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product` enum('web','app','studio','erp','support') NOT NULL DEFAULT 'studio',
  `version` varchar(30) NOT NULL,
  `name` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('current','archived') NOT NULL DEFAULT 'current',
  `created_by` int(10) unsigned DEFAULT NULL,
  `published_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_movesos_product_version` (`product`,`version`),
  KEY `idx_movesos_version_status` (`status`),
  KEY `idx_movesos_product_status` (`product`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sender_id` int(10) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `audience` varchar(30) NOT NULL DEFAULT 'all',
  `target_user_id` int(10) unsigned DEFAULT NULL,
  `severity` varchar(20) NOT NULL DEFAULT 'info',
  `delivery_channels` varchar(20) NOT NULL DEFAULT 'system',
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `message_id` int(10) unsigned DEFAULT NULL,
  `source_log_id` int(11) unsigned DEFAULT NULL,
  `users_id` int(11) unsigned NOT NULL,
  `category` int(11) unsigned NOT NULL,
  `image` varchar(255) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL DEFAULT '',
  `body` text DEFAULT NULL,
  `severity` varchar(20) NOT NULL DEFAULT 'info',
  `link` varchar(255) NOT NULL DEFAULT '',
  `view` int(11) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `FK_notifications_users` (`users_id`,`category`) USING BTREE,
  KEY `FK_notifications_notifications_categories` (`category`),
  KEY `idx_notifications_source_log` (`source_log_id`,`users_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications_categories` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '',
  `uri` varchar(255) NOT NULL DEFAULT '',
  `description` text NOT NULL,
  `cover` varchar(255) DEFAULT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'post' COMMENT 'post, page, etc',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `onboarding_item_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `onboarding_item_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) NOT NULL,
  `category` enum('documentacao','seguranca','manutencao','seguros','financeiro','pessoas') NOT NULL,
  `title` varchar(160) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `default_due_days` smallint(5) unsigned NOT NULL DEFAULT 30 COMMENT 'Prazo padrão do protocolo, em dias',
  `renewal_months` smallint(5) unsigned DEFAULT NULL COMMENT 'Periodicidade de renovação; NULL = não vence',
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_onboarding_item_templates_code` (`code`),
  KEY `idx_onboarding_item_templates_active` (`active`,`category`,`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pages` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `author` int(11) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `uri` varchar(255) NOT NULL,
  `content` mediumtext NOT NULL,
  `cover` varchar(255) DEFAULT NULL,
  `video` varchar(50) DEFAULT NULL,
  `views` int(11) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'draft' COMMENT 'post, draft, trash ',
  `post_at` timestamp NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `user_id` (`author`) USING BTREE,
  FULLTEXT KEY `full_text` (`content`,`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `posts` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `author` int(11) unsigned DEFAULT NULL,
  `category` int(11) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `uri` varchar(255) NOT NULL,
  `subtitle` text NOT NULL,
  `content` text NOT NULL,
  `cover` varchar(255) DEFAULT NULL,
  `video` varchar(50) DEFAULT NULL,
  `views` int(11) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'draft' COMMENT 'post, draft, trash ',
  `post_at` timestamp NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category`),
  KEY `user_id` (`author`),
  FULLTEXT KEY `full_text` (`title`,`subtitle`,`content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `proposal_responses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `proposal_responses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `proposal_id` int(10) unsigned NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `template_type` enum('syndic','administrator') NOT NULL DEFAULT 'syndic',
  `subject` varchar(255) NOT NULL,
  `introduction` text NOT NULL,
  `scope` text NOT NULL,
  `commercial_terms` text NOT NULL,
  `payment_terms` varchar(500) DEFAULT NULL,
  `valid_until` date NOT NULL,
  `notes` text DEFAULT NULL,
  `pdf_path` varchar(500) DEFAULT NULL,
  `status` enum('draft','generated','queued','sent','failed') NOT NULL DEFAULT 'draft',
  `queued_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_proposal_responses_proposal` (`proposal_id`,`created_at`),
  KEY `idx_proposal_responses_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `proposals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `proposals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `protocol` varchar(32) NOT NULL,
  `name` varchar(180) NOT NULL,
  `email` varchar(180) NOT NULL,
  `whatsapp` varchar(30) NOT NULL,
  `condominium` varchar(180) NOT NULL,
  `units` int(10) unsigned NOT NULL,
  `profile` varchar(40) NOT NULL,
  `message` text DEFAULT NULL,
  `status` enum('new','contacted','qualified','proposal_sent','won','lost','archived') NOT NULL DEFAULT 'new',
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `source_url` varchar(500) DEFAULT NULL,
  `request_hash` char(64) DEFAULT NULL,
  `consent_at` datetime DEFAULT NULL,
  `contacted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_proposals_protocol` (`protocol`),
  KEY `idx_proposals_status_created` (`status`,`created_at`),
  KEY `idx_proposals_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `report_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_access` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `users` int(11) NOT NULL DEFAULT 1,
  `views` int(11) NOT NULL DEFAULT 1,
  `pages` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `report_online`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_online` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user` int(11) unsigned DEFAULT NULL,
  `ip` varchar(50) NOT NULL DEFAULT '',
  `url` varchar(255) NOT NULL DEFAULT '',
  `agent` varchar(255) NOT NULL DEFAULT '',
  `pages` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `mode` int(11) DEFAULT 1,
  `site_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `site_title` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `site_desc` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `site_photo` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `site_logo_svg` varchar(255) DEFAULT NULL,
  `site_icon` varchar(255) DEFAULT NULL,
  `site_favicon` varchar(255) DEFAULT NULL,
  `site_lang` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'pt_BR',
  `site_domain` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `site_domain_ssl` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `site_domain_off` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `site_street` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `site_phone` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `site_whatsapp` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `site_number` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `site_complement` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `site_city` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `site_state` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `site_code` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `site_district` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `view_theme` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `view_support` varchar(255) DEFAULT 'support_by_moves',
  `view_app` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `view_erp` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `view_admin` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `view_mail` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `view_upkeep` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'moves_upkeep',
  `upload_dir` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'storage',
  `upload_image` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'images',
  `upload_file` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'files',
  `upload_media` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'medias',
  `image_cache` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'cache',
  `image_size` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT '2000',
  `image_jpg` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT '75',
  `image_png` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT '5',
  `mail_host` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `mail_port` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `mail_user` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `mail_pass` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `mail_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `mail_address` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `mail_suport` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `mail_lang` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `mail_html` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'true',
  `mail_auth` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'true',
  `mail_secure` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `mail_charset` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `pay_mode` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT 'test',
  `pay_live` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `pay_test` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `pay_back` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `social_tw_creator` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `social_tw_publisher` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `social_fb_app` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `social_fb_page` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `social_fb_author` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `social_google_page` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `social_google_author` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `social_instagram_page` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `social_youtube_page` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `social_linkedin_page` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `timezone_set` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `access_studio` tinyint(1) NOT NULL DEFAULT 1,
  `access_erp` tinyint(1) NOT NULL DEFAULT 1,
  `access_app` tinyint(1) NOT NULL DEFAULT 1,
  `access_site` tinyint(1) NOT NULL DEFAULT 1,
  `access_support` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `slides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `slides` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `author` int(11) unsigned DEFAULT NULL,
  `category` int(11) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `uri` varchar(255) NOT NULL,
  `content` mediumtext NOT NULL,
  `cover` varchar(255) DEFAULT NULL,
  `views` int(11) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'draft' COMMENT 'post, draft, trash ',
  `position` varchar(20) NOT NULL DEFAULT 'left' COMMENT 'left, center, right',
  `post_at` timestamp NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `category_id` (`category`) USING BTREE,
  KEY `user_id` (`author`) USING BTREE,
  FULLTEXT KEY `full_text` (`content`,`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `studio_calendar_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `studio_calendar_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(180) NOT NULL,
  `description` text DEFAULT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime DEFAULT NULL,
  `type` enum('meeting','task','deadline','support') NOT NULL DEFAULT 'meeting',
  `status` enum('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_calendar_range` (`starts_at`,`ends_at`),
  KEY `idx_calendar_assigned` (`assigned_to`),
  KEY `fk_calendar_created_user` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `studio_support_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `studio_support_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(120) NOT NULL,
  `body` text NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_support_template_user` (`created_by`),
  CONSTRAINT `fk_support_template_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `studio_support_ticket_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `studio_support_ticket_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int(10) unsigned NOT NULL,
  `message_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime_type` varchar(120) DEFAULT NULL,
  `file_size` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ticket_attachment` (`ticket_id`,`created_at`),
  KEY `fk_ticket_attachment_message` (`message_id`),
  KEY `fk_ticket_attachment_user` (`user_id`),
  CONSTRAINT `fk_ticket_attachment_message` FOREIGN KEY (`message_id`) REFERENCES `studio_support_ticket_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ticket_attachment_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `studio_support_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ticket_attachment_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `studio_support_ticket_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `studio_support_ticket_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `event_type` varchar(40) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ticket_event` (`ticket_id`,`created_at`),
  KEY `fk_ticket_event_user` (`user_id`),
  CONSTRAINT `fk_ticket_event_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `studio_support_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ticket_event_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `studio_support_ticket_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `studio_support_ticket_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `message` text NOT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ticket_message` (`ticket_id`,`created_at`),
  KEY `fk_ticket_message_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `studio_support_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `studio_support_tickets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `protocol` char(12) NOT NULL,
  `subject` varchar(180) NOT NULL,
  `message` text NOT NULL,
  `area` enum('general','technical','financial') NOT NULL DEFAULT 'general',
  `origin` enum('helpdesk','erp','site','residents','api','talk') NOT NULL DEFAULT 'helpdesk',
  `external_ref` varchar(40) DEFAULT NULL COMMENT 'Protocolo do sistema de origem (Talk), ADR 0308',
  `condominium_id` int(10) unsigned DEFAULT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `tags` varchar(500) DEFAULT NULL,
  `priority` enum('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status` enum('open','in_progress','waiting_customer','resolved','closed') NOT NULL DEFAULT 'open',
  `requester_id` int(10) unsigned DEFAULT NULL,
  `requester_name` varchar(160) DEFAULT NULL,
  `requester_email` varchar(190) DEFAULT NULL,
  `requester_phone` varchar(30) DEFAULT NULL,
  `access_token` char(64) DEFAULT NULL,
  `assigned_to` int(10) unsigned DEFAULT NULL,
  `team` varchar(100) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `due_at` datetime NOT NULL,
  `first_response_at` datetime DEFAULT NULL,
  `work_seconds` int(10) unsigned NOT NULL DEFAULT 0,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_support_ticket_protocol` (`protocol`),
  UNIQUE KEY `uq_support_ticket_access_token` (`access_token`),
  UNIQUE KEY `uq_support_ticket_external` (`origin`,`external_ref`),
  KEY `idx_support_ticket_status` (`status`,`priority`,`created_at`),
  KEY `idx_support_ticket_requester` (`requester_id`),
  KEY `idx_support_ticket_assignee` (`assigned_to`),
  KEY `fk_ticket_created_user` (`created_by`),
  KEY `idx_support_ticket_condominium` (`condominium_id`,`status`,`created_at`),
  KEY `idx_support_ticket_unit` (`unit_id`),
  KEY `idx_support_ticket_origin` (`origin`,`created_at`),
  CONSTRAINT `fk_support_ticket_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_support_ticket_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `support_article_votes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_article_votes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `article_id` int(10) unsigned NOT NULL,
  `vote` enum('yes','no') NOT NULL,
  `visitor_hash` char(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_support_vote` (`article_id`,`visitor_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `support_articles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_articles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int(10) unsigned NOT NULL,
  `author_id` int(10) unsigned DEFAULT NULL,
  `title` varchar(180) NOT NULL,
  `uri` varchar(190) NOT NULL,
  `summary` varchar(320) NOT NULL,
  `content` longtext NOT NULL,
  `cover` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `kb_key` varchar(190) DEFAULT NULL,
  `kb_hash` char(64) DEFAULT NULL,
  `kb_meta` longtext DEFAULT NULL,
  `views` int(10) unsigned NOT NULL DEFAULT 0,
  `helpful_yes` int(10) unsigned NOT NULL DEFAULT 0,
  `helpful_no` int(10) unsigned NOT NULL DEFAULT 0,
  `published_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_support_article_uri` (`uri`),
  UNIQUE KEY `uq_support_article_kb_key` (`kb_key`),
  KEY `idx_support_article_category` (`category_id`,`status`,`published_at`),
  KEY `fk_support_article_author` (`author_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `support_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(120) NOT NULL,
  `uri` varchar(140) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `icon` varchar(80) NOT NULL DEFAULT 'help-circle-outline',
  `position` int(10) unsigned NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `kb_key` varchar(80) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_support_category_uri` (`uri`),
  UNIQUE KEY `uq_support_category_kb_key` (`kb_key`),
  KEY `idx_support_category_status` (`status`,`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(10) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_protected_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_protected_users` (
  `user_id` int(10) unsigned NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_protected_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_audit_log` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_campaign_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_campaign_recipients` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_campaigns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_campaigns` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_channels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_channels` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_contact_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_contact_notes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_contact_notes` (`contact_id`,`id`),
  CONSTRAINT `fk_cn_contact` FOREIGN KEY (`contact_id`) REFERENCES `talk_contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_contact_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_contact_tags` (
  `contact_id` int(10) unsigned NOT NULL,
  `tag_id` int(10) unsigned NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`contact_id`,`tag_id`),
  KEY `idx_contact_tags_tag` (`tag_id`),
  CONSTRAINT `fk_ct_contact` FOREIGN KEY (`contact_id`) REFERENCES `talk_contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ct_tag` FOREIGN KEY (`tag_id`) REFERENCES `talk_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_contacts` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_conversations` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_events` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_holidays` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `day` date NOT NULL,
  `name` varchar(80) NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_holiday_day` (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_jack_decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_jack_decisions` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_login_email` (`email`,`created_at`),
  KEY `ix_login_ip` (`ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_messages` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_outbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_outbox` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_protocol_seq`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_protocol_seq` (
  `day` date NOT NULL,
  `last_value` int(10) unsigned NOT NULL,
  PRIMARY KEY (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_quick_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_quick_messages` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_ratings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_ratings` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_schedules` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_sectors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_sectors` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_settings` (
  `name` varchar(60) NOT NULL,
  `value` mediumtext NOT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_staff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_staff` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `sector_id` int(10) unsigned DEFAULT NULL,
  `role` enum('agent','manager','supervisor') NOT NULL DEFAULT 'agent',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff` (`user_id`,`sector_id`),
  KEY `fk_staff_sector` (`sector_id`),
  CONSTRAINT `fk_staff_sector` FOREIGN KEY (`sector_id`) REFERENCES `talk_sectors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_tags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(40) NOT NULL,
  `color` char(7) NOT NULL DEFAULT '#12804A',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tag_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_templates` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `talk_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `talk_users` (
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
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_condominium_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_condominium_access` (
  `user_id` int(10) unsigned NOT NULL,
  `condominium_id` int(10) unsigned NOT NULL,
  `granted_by` int(10) unsigned DEFAULT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`condominium_id`),
  KEY `idx_user_condominium_access_condominium` (`condominium_id`),
  KEY `fk_uca_granted_by` (`granted_by`),
  CONSTRAINT `fk_uca_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uca_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_uca_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `requested_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_password_resets_token` (`token_hash`),
  KEY `idx_user_password_resets_user` (`user_id`,`used_at`),
  CONSTRAINT `fk_user_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_recovery_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_recovery_codes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_recovery_codes_user` (`user_id`,`used_at`),
  CONSTRAINT `fk_user_recovery_codes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_session_revocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_session_revocations` (
  `user_id` int(10) unsigned NOT NULL,
  `revoked_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_two_factor`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_two_factor` (
  `user_id` int(10) unsigned NOT NULL,
  `secret_encrypted` varbinary(255) NOT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `last_used_step` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_two_factor_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL DEFAULT '',
  `last_name` varchar(255) NOT NULL DEFAULT '',
  `email` varchar(255) NOT NULL DEFAULT '',
  `document` varchar(11) NOT NULL DEFAULT '',
  `document_rg` varchar(16) DEFAULT '',
  `password` varchar(255) NOT NULL DEFAULT '',
  `level` int(11) NOT NULL DEFAULT 1,
  `forget` varchar(255) DEFAULT NULL,
  `genre` varchar(10) NOT NULL DEFAULT 'uninformed' COMMENT 'uninformed, male, female, other, null',
  `datebirth` date DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `doc_cpf` varchar(255) DEFAULT NULL,
  `doc_rg` varchar(255) DEFAULT NULL,
  `phone` varchar(10) DEFAULT NULL,
  `phone_cell` varchar(11) DEFAULT NULL,
  `send` int(11) NOT NULL DEFAULT 1,
  `despatch` varchar(255) NOT NULL DEFAULT 'all' COMMENT 'e-mail, whatsapp, telegram, letter, all',
  `status` varchar(50) NOT NULL DEFAULT 'registered' COMMENT 'registered, confirmed',
  `privacy` varchar(50) DEFAULT 'reject' COMMENT 'reject, accept',
  `session_condo` int(11) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `email` (`document`,`email`) USING BTREE,
  KEY `idx_users_session_condo` (`session_condo`),
  FULLTEXT KEY `full_text` (`document`,`first_name`,`last_name`,`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

