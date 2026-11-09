-- Acordos (AP-033/034, ADR 0039): renegociação das contas a receber vencidas de uma unidade. As
-- contas originais ficam canceladas e ligadas ao acordo (erp_entries.agreement_id, 0520); as
-- parcelas viram contas a receber novas, pagas pela baixa normal ou por boleto. Multa, juros e
-- desconto são informados por quem faz o acordo (regra de cálculo ainda não aprovada).
-- Idempotente (a suíte reaplica as migrations a cada execução).
CREATE TABLE IF NOT EXISTS `erp_agreements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `unit_id` int unsigned NOT NULL,
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active',
  `chart_account_id` int unsigned NOT NULL COMMENT 'Conta analítica de receita das parcelas',
  `original_cents` bigint unsigned NOT NULL COMMENT 'Soma das contas renegociadas',
  `fees_cents` bigint unsigned NOT NULL DEFAULT 0 COMMENT 'Multa e juros informados',
  `discount_cents` bigint unsigned NOT NULL DEFAULT 0,
  `total_cents` bigint unsigned NOT NULL COMMENT 'original + encargos − desconto = soma das parcelas',
  `installments` smallint unsigned NOT NULL,
  `first_due_on` date NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `cancelled_by` int unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_agreements_list` (`condominium_id`, `status`, `created_at`),
  KEY `idx_erp_agreements_unit` (`unit_id`),
  CONSTRAINT `fk_erp_agreements_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_agreements_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_agreements_account` FOREIGN KEY (`chart_account_id`) REFERENCES `erp_chart_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_agreements_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_agreements_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico das contas renegociadas por acordo (continua depois do cancelamento do acordo).
CREATE TABLE IF NOT EXISTS `erp_agreement_originals` (
  `agreement_id` int unsigned NOT NULL,
  `entry_id` int unsigned NOT NULL,
  `amount_cents` bigint unsigned NOT NULL,
  PRIMARY KEY (`agreement_id`, `entry_id`),
  KEY `idx_erp_agreement_originals_entry` (`entry_id`),
  CONSTRAINT `fk_erp_agreement_originals_agreement` FOREIGN KEY (`agreement_id`) REFERENCES `erp_agreements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_agreement_originals_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
