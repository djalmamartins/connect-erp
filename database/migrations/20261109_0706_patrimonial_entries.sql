-- Lançamentos patrimoniais com partida dobrada (ADR 0169): débito numa conta patrimonial analítica
-- (natureza asset/liability/equity, ADR 0167) e crédito em outra, do mesmo valor. São usados para
-- apropriar e usar o fundo de reserva, transferir entre fundos e ajustar o PL. Não passam pela DRE
-- (a receita e a despesa continuam em erp_entries) e não movem o banco: o Disponível continua vindo das
-- contas bancárias. Por isso as contas calculadas (7.1, 7.2 e 8.1) não recebem lançamento.
-- bank_account_id e linked_entry_id são só referência: em que conta o fundo está e qual receita ou
-- despesa originou o movimento. Não há exclusão: o estorno é um lançamento invertido
-- (reversal_of_id), e o original fica com status 'reversed'. Idempotente.
CREATE TABLE IF NOT EXISTS `erp_patrimonial_entries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `entry_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount_cents` bigint unsigned NOT NULL COMMENT 'Sempre positivo',
  `debit_account_id` int unsigned NOT NULL,
  `credit_account_id` int unsigned NOT NULL,
  `bank_account_id` int unsigned DEFAULT NULL COMMENT 'Referência: conta bancária onde o valor está',
  `linked_entry_id` int unsigned DEFAULT NULL COMMENT 'Referência: receita ou despesa que originou',
  `status` enum('active','reversed') NOT NULL DEFAULT 'active' COMMENT 'reversed = estornado (o estorno é outro lançamento)',
  `reversal_of_id` int unsigned DEFAULT NULL COMMENT 'Este lançamento estorna aquele',
  `reversal_reason` varchar(255) DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_patrimonial_entries_reversal` (`reversal_of_id`),
  KEY `idx_erp_patrimonial_entries_date` (`condominium_id`, `entry_date`),
  KEY `idx_erp_patrimonial_entries_debit` (`debit_account_id`),
  KEY `idx_erp_patrimonial_entries_credit` (`credit_account_id`),
  CONSTRAINT `fk_erp_patrimonial_entries_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_patrimonial_entries_debit` FOREIGN KEY (`debit_account_id`) REFERENCES `erp_chart_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_patrimonial_entries_credit` FOREIGN KEY (`credit_account_id`) REFERENCES `erp_chart_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_patrimonial_entries_bank` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_patrimonial_entries_entry` FOREIGN KEY (`linked_entry_id`) REFERENCES `erp_entries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_patrimonial_entries_reversal` FOREIGN KEY (`reversal_of_id`) REFERENCES `erp_patrimonial_entries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_patrimonial_entries_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
