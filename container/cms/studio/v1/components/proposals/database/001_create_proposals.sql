CREATE TABLE IF NOT EXISTS `proposals` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `protocol` VARCHAR(32) NOT NULL,
  `name` VARCHAR(180) NOT NULL,
  `email` VARCHAR(180) NOT NULL,
  `whatsapp` VARCHAR(30) NOT NULL,
  `condominium` VARCHAR(180) NOT NULL,
  `units` INT UNSIGNED NOT NULL,
  `profile` VARCHAR(40) NOT NULL,
  `message` TEXT NULL,
  `status` ENUM('new','contacted','qualified','proposal_sent','won','lost','archived') NOT NULL DEFAULT 'new',
  `assigned_to` INT UNSIGNED NULL,
  `source_url` VARCHAR(500) NULL,
  `request_hash` CHAR(64) NULL,
  `consent_at` DATETIME NULL,
  `contacted_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_proposals_protocol` (`protocol`),
  KEY `idx_proposals_status_created` (`status`,`created_at`),
  KEY `idx_proposals_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Gerenciar propostas','proposals.manage','Comercial','Consultar e acompanhar propostas recebidas pelo site')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`group_name`=VALUES(`group_name`),`description`=VALUES(`description`);

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id,p.id FROM access_roles r CROSS JOIN access_permissions p
WHERE r.slug IN ('developer','super_admin','client_admin','manager') AND p.slug='proposals.manage';
