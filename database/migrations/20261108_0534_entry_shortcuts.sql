-- Lançamentos avulsos e recibos (ADR 0057): desconto em conta a receber (AP-026), multa avulsa e
-- cobranças extras (AP-027/028) como origem dos lançamentos, transferência entre contas bancárias
-- (AP-029) e recibo com código de verificação nos documentos da unidade (AP-040).
-- Idempotente (a suíte reaplica as migrations a cada execução). Sem permissão nova: usa
-- erp.entries.*, erp.invoices.* e erp.unit_documents.*.

-- Recibo (AP-040) é mais um tipo de documento da unidade (ADR 0044).
ALTER TABLE `erp_unit_documents`
  MODIFY `kind` enum('debt_certificate','delinquency_declaration','quittance','consent_letter','receipt') NOT NULL;

-- Lançamentos cobertos por cada recibo: um lançamento só tem um recibo válido (conferido no serviço).
CREATE TABLE IF NOT EXISTS `erp_unit_document_entries` (
  `document_id` int unsigned NOT NULL,
  `entry_id` int unsigned NOT NULL,
  PRIMARY KEY (`document_id`, `entry_id`),
  KEY `idx_erp_unit_document_entries_entry` (`entry_id`),
  CONSTRAINT `fk_erp_unit_document_entries_document` FOREIGN KEY (`document_id`) REFERENCES `erp_unit_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_unit_document_entries_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trilha dos descontos (AP-026): valor antes e depois, com motivo. O lançamento guarda o valor atual.
CREATE TABLE IF NOT EXISTS `erp_entry_adjustments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `entry_id` int unsigned NOT NULL,
  `kind` enum('discount') NOT NULL,
  `amount_cents` bigint unsigned NOT NULL,
  `before_cents` bigint unsigned NOT NULL,
  `after_cents` bigint unsigned NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_entry_adjustments_entry` (`entry_id`),
  KEY `idx_erp_entry_adjustments_list` (`condominium_id`, `created_at`),
  CONSTRAINT `fk_erp_entry_adjustments_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_entry_adjustments_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_entry_adjustments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Multa avulsa (kind fine, uma unidade) e cobrança extra (kind extra, várias unidades): a origem das
-- contas a receber geradas, que ficam travadas no cadastro (origin_lock) e se cancelam por aqui.
CREATE TABLE IF NOT EXISTS `erp_extra_charges` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `kind` enum('extra','fine') NOT NULL,
  `description` varchar(120) NOT NULL,
  `chart_account_id` int unsigned NOT NULL COMMENT 'Conta analítica de receita',
  `method` enum('fixed','equal','fraction') NOT NULL COMMENT 'fixed = valor por unidade; equal/fraction = total rateado',
  `total_cents` bigint unsigned NOT NULL,
  `units_count` smallint unsigned NOT NULL,
  `competence` char(7) NOT NULL,
  `due_on` date NOT NULL,
  `related_entry_id` int unsigned DEFAULT NULL COMMENT 'Multa: conta a que se refere (opcional)',
  `reason` varchar(255) DEFAULT NULL COMMENT 'Multa: motivo (regulamento, ocorrência)',
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `cancelled_by` int unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_extra_charges_list` (`condominium_id`, `kind`, `status`, `due_on`),
  CONSTRAINT `fk_erp_extra_charges_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_extra_charges_account` FOREIGN KEY (`chart_account_id`) REFERENCES `erp_chart_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_extra_charges_related` FOREIGN KEY (`related_entry_id`) REFERENCES `erp_entries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_extra_charges_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_extra_charges_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `erp_entries`
  ADD COLUMN IF NOT EXISTS `extra_charge_id` int unsigned DEFAULT NULL AFTER `agreement_installment`,
  ADD INDEX IF NOT EXISTS `idx_erp_entries_extra_charge` (`extra_charge_id`),
  ADD CONSTRAINT `fk_erp_entries_extra_charge` FOREIGN KEY IF NOT EXISTS (`extra_charge_id`) REFERENCES `erp_extra_charges` (`id`) ON DELETE RESTRICT;

-- Transferência entre contas bancárias do mesmo condomínio (AP-029): não é receita nem despesa;
-- só move saldo entre contas. Cancelada sai dos saldos; nada é apagado.
CREATE TABLE IF NOT EXISTS `erp_bank_transfers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `from_bank_account_id` int unsigned NOT NULL,
  `to_bank_account_id` int unsigned NOT NULL,
  `amount_cents` bigint unsigned NOT NULL,
  `transferred_on` date NOT NULL,
  `description` varchar(160) NOT NULL,
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `cancelled_by` int unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_bank_transfers_list` (`condominium_id`, `status`, `transferred_on`),
  KEY `idx_erp_bank_transfers_from` (`from_bank_account_id`),
  KEY `idx_erp_bank_transfers_to` (`to_bank_account_id`),
  CONSTRAINT `fk_erp_bank_transfers_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_transfers_from` FOREIGN KEY (`from_bank_account_id`) REFERENCES `erp_bank_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_transfers_to` FOREIGN KEY (`to_bank_account_id`) REFERENCES `erp_bank_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_transfers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_bank_transfers_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
