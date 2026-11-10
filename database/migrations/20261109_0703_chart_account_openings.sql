-- Saldo inicial do plano de contas (AP-161, ADR 0166), no mesmo padrão do saldo inicial por centro de
-- custo (AP-162, 20261108_0540): um valor (com sinal) por condomínio e conta analítica, numa data.
-- A conta sintética não recebe saldo: é a soma das analíticas (calculada na leitura).
-- Idempotente (a suíte reaplica as migrations).
CREATE TABLE IF NOT EXISTS `erp_chart_account_openings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `chart_account_id` int unsigned NOT NULL,
  `opened_on` date NOT NULL,
  `amount_cents` bigint NOT NULL COMMENT 'Pode ser negativo',
  `notes` varchar(255) DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_chart_account_openings` (`condominium_id`, `chart_account_id`),
  KEY `idx_erp_chart_account_openings_account` (`chart_account_id`),
  CONSTRAINT `fk_erp_chart_account_openings_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_chart_account_openings_account` FOREIGN KEY (`chart_account_id`) REFERENCES `erp_chart_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_chart_account_openings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
