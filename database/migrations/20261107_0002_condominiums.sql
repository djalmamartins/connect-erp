-- Condomínios do ERP e vínculo usuário↔condomínio (ADR-002: administradora única).
CREATE TABLE IF NOT EXISTS `condominiums` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `document` char(14) DEFAULT NULL COMMENT 'CNPJ somente dígitos',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_condominiums_document` (`document`),
  KEY `idx_condominiums_status_name` (`status`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_condominium_access` (
  `user_id` int unsigned NOT NULL,
  `condominium_id` int unsigned NOT NULL,
  `granted_by` int unsigned DEFAULT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`, `condominium_id`),
  KEY `idx_user_condominium_access_condominium` (`condominium_id`),
  CONSTRAINT `fk_uca_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uca_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uca_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
