-- Arquivos de retorno (AP-036/038) e eventos bancários (ADR 0035). O mesmo arquivo (sha256) por
-- conta bancária só é processado uma vez; o mesmo evento (event_key) também. Cada evento guarda o
-- resultado: applied (baixa feita), duplicate, divergence (fila de revisão, nunca baixa silenciosa)
-- ou unknown_charge. raw guarda só os campos do evento, sem dados de credencial.
-- Idempotente (a suíte reaplica as migrations a cada execução).
CREATE TABLE IF NOT EXISTS `erp_bank_return_files` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `bank_account_id` int unsigned NOT NULL,
  `adapter` varchar(30) NOT NULL,
  `filename` varchar(120) NOT NULL,
  `sha256` char(64) NOT NULL,
  `events_count` int unsigned NOT NULL DEFAULT 0,
  `applied_count` int unsigned NOT NULL DEFAULT 0,
  `divergence_count` int unsigned NOT NULL DEFAULT 0,
  `total_cents` bigint unsigned NOT NULL DEFAULT 0,
  `uploaded_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_bank_return_files_hash` (`bank_account_id`, `sha256`),
  KEY `idx_erp_bank_return_files_condominium` (`condominium_id`, `created_at`),
  CONSTRAINT `fk_erp_bank_return_files_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_return_files_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_return_files_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erp_bank_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `bank_account_id` int unsigned NOT NULL,
  `return_file_id` int unsigned DEFAULT NULL,
  `charge_id` int unsigned DEFAULT NULL,
  `event_key` varchar(120) NOT NULL COMMENT 'Identificador do evento no banco (idempotência)',
  `kind` enum('registered','rejected','paid','cancelled') NOT NULL,
  `our_number` varchar(20) NOT NULL,
  `amount_cents` bigint unsigned DEFAULT NULL,
  `occurred_on` date DEFAULT NULL,
  `outcome` enum('applied','duplicate','divergence','unknown_charge') NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `raw` text DEFAULT NULL,
  `resolved_by` int unsigned DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_bank_events_key` (`bank_account_id`, `event_key`),
  KEY `idx_erp_bank_events_review` (`condominium_id`, `outcome`, `resolved_at`),
  KEY `idx_erp_bank_events_charge` (`charge_id`),
  CONSTRAINT `fk_erp_bank_events_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_events_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_events_file` FOREIGN KEY (`return_file_id`) REFERENCES `erp_bank_return_files` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_events_charge` FOREIGN KEY (`charge_id`) REFERENCES `erp_bank_charges` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_events_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
