-- Plano de contas (AP-160, ADR 0019). Sem condomínio = plano padrão da Connect, válido para
-- todos; com condomínio = conta própria daquele condomínio (ADR-002). Exclusão lógica.
CREATE TABLE IF NOT EXISTS `erp_chart_accounts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned DEFAULT NULL COMMENT 'NULL = plano padrão da Connect',
  `code` varchar(30) NOT NULL COMMENT 'Código hierárquico: 1, 1.01, 1.01.002',
  `name` varchar(120) NOT NULL,
  `nature` enum('receita','despesa') NOT NULL,
  `kind` enum('sintetica','analitica') NOT NULL COMMENT 'sintetica agrupa; analitica recebe lançamentos',
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_chart_accounts_code` (`condominium_id`, `code`, `deleted_at`),
  CONSTRAINT `fk_erp_chart_accounts_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_chart_accounts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_chart_accounts_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
