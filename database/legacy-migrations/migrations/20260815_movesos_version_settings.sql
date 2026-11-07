ALTER TABLE `settings`
  ADD COLUMN IF NOT EXISTS `access_studio` tinyint(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `access_erp` tinyint(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `access_app` tinyint(1) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS `movesos_versions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product` enum('web','app','studio','erp') NOT NULL DEFAULT 'studio',
  `version` varchar(30) NOT NULL,
  `name` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('current','archived') NOT NULL DEFAULT 'current',
  `created_by` int unsigned DEFAULT NULL,
  `published_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_movesos_product_version` (`product`,`version`), KEY `idx_movesos_version_status` (`product`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `movesos_versions` (`product`,`version`,`name`,`notes`,`status`,`created_by`,`published_at`) VALUES
('web','1.0.0','Fundação Web','Versão inicial do site.','current',1,NOW()),
('app','ALFA','Fundação App','Versão inicial do aplicativo.','current',1,NOW()),
('studio','3.0.0','Fundação Studio','Versão inicial do Studio.','current',1,NOW()),
('erp','ALFA','Fundação ERP','Versão inicial do ERP.','current',1,NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name);
