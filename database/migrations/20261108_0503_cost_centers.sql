-- Centros de custo (CN-002, ADR 0021). Sem condomínio = centro padrão da Connect, válido para
-- todos; com condomínio = próprio dele (ADR-002). Exclusão lógica.
CREATE TABLE IF NOT EXISTS `erp_cost_centers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned DEFAULT NULL COMMENT 'NULL = centro de custo padrão da Connect',
  `code` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_cost_centers_code` (`condominium_id`, `code`, `deleted_at`),
  CONSTRAINT `fk_erp_cost_centers_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_cost_centers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_cost_centers_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
