CREATE TABLE IF NOT EXISTS `modules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(100) NOT NULL,
  `name` VARCHAR(140) NOT NULL,
  `description` VARCHAR(255) NULL,
  `available_version` VARCHAR(30) NOT NULL DEFAULT '1.0.0',
  `installed_version` VARCHAR(30) NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `is_core` TINYINT(1) NOT NULL DEFAULT 0,
  `installed_by` INT UNSIGNED NULL,
  `installed_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_modules_slug` (`slug`),
  KEY `idx_modules_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `module_migrations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_slug` VARCHAR(100) NOT NULL,
  `migration` VARCHAR(190) NOT NULL,
  `checksum` CHAR(64) NOT NULL,
  `batch` INT UNSIGNED NOT NULL DEFAULT 1,
  `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_module_migration` (`module_slug`,`migration`),
  KEY `idx_module_migrations_slug` (`module_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
