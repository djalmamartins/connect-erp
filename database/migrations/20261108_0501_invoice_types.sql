-- Tipos de faturas (AP-168, ADR 0020): cada tipo aponta para uma conta analítica de receita do
-- plano de contas (0405). Sem condomínio = tipo padrão da Connect. Exclusão lógica.
CREATE TABLE IF NOT EXISTS `erp_invoice_types` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned DEFAULT NULL COMMENT 'NULL = tipo padrão da Connect',
  `name` varchar(80) NOT NULL COMMENT 'Ex.: Taxa condominial, Fundo de reserva, Rateio extra',
  `chart_account_id` int unsigned NOT NULL COMMENT 'Conta analítica de receita',
  `recurrence` enum('mensal','avulsa') NOT NULL DEFAULT 'mensal',
  `default_amount_cents` bigint unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_invoice_types_condominium` (`condominium_id`, `deleted_at`, `status`),
  KEY `idx_erp_invoice_types_account` (`chart_account_id`),
  CONSTRAINT `fk_erp_invoice_types_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_invoice_types_account` FOREIGN KEY (`chart_account_id`) REFERENCES `erp_chart_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_invoice_types_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_invoice_types_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
