-- Boletos (AP-035, ADR 0035): título bancário de uma conta a receber (erp_entries, receita), emitido
-- pelo adaptador bancário da conta bancária (0504). Só o adaptador 'sandbox' existe: sem rede e sem
-- credencial. Estados: pending (sem confirmação do banco, verificável), registered, rejected, paid,
-- cancelled. Um título ativo por lançamento: active_key é 1 enquanto pending/registered/paid e NULL
-- depois, e a chave única ignora NULL. Nosso número único por conta bancária.
-- Idempotente (a suíte reaplica as migrations a cada execução).
CREATE TABLE IF NOT EXISTS `erp_bank_charges` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `bank_account_id` int unsigned NOT NULL,
  `entry_id` int unsigned NOT NULL,
  `adapter` varchar(30) NOT NULL,
  `status` enum('pending','registered','rejected','paid','cancelled') NOT NULL DEFAULT 'pending',
  `active_key` tinyint unsigned GENERATED ALWAYS AS (CASE WHEN `status` IN ('pending','registered','paid') THEN 1 END) STORED,
  `our_number` varchar(20) NOT NULL COMMENT 'Nosso número, sequencial por conta bancária',
  `amount_cents` bigint unsigned NOT NULL,
  `due_on` date NOT NULL,
  `external_id` varchar(80) DEFAULT NULL COMMENT 'Identificador do título no banco',
  `digitable_line` varchar(80) DEFAULT NULL,
  `barcode` varchar(60) DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `paid_cents` bigint unsigned DEFAULT NULL,
  `paid_on` date DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `registered_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_bank_charges_our_number` (`bank_account_id`, `our_number`),
  UNIQUE KEY `uq_erp_bank_charges_active_entry` (`entry_id`, `active_key`),
  KEY `idx_erp_bank_charges_list` (`condominium_id`, `status`, `due_on`),
  CONSTRAINT `fk_erp_bank_charges_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_charges_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_charges_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_charges_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
