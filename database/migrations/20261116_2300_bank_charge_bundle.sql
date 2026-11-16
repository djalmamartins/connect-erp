-- Boleto único (ADR 0331, Nova Receita modelo 8 "Tipo da fatura"): o rateio marcado com um tipo de fatura entra no
-- boleto da fatura da unidade (mesmo tipo e competência), em vez de ter boleto próprio.
-- erp_entries.bundle_invoice_type_id: tipo da fatura em que a conta entra; NULL = boleto próprio.
-- erp_bank_charge_entries: contas a mais cobertas por um boleto (a principal continua em erp_bank_charges.entry_id).
-- Idempotente (a suíte reaplica as migrations a cada execução).
ALTER TABLE `erp_entries`
  ADD COLUMN IF NOT EXISTS `bundle_invoice_type_id` int unsigned DEFAULT NULL COMMENT 'Tipo da fatura em que a conta entra no boleto (ADR 0331)' AFTER `related_account_id`,
  ADD KEY IF NOT EXISTS `idx_erp_entries_bundle` (`unit_id`, `competence`, `bundle_invoice_type_id`),
  ADD CONSTRAINT `fk_erp_entries_bundle_invoice_type` FOREIGN KEY IF NOT EXISTS (`bundle_invoice_type_id`) REFERENCES `erp_invoice_types` (`id`) ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS `erp_bank_charge_entries` (
  `charge_id` int unsigned NOT NULL,
  `entry_id` int unsigned NOT NULL,
  `amount_cents` bigint unsigned NOT NULL COMMENT 'Valor da conta dentro do boleto',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`charge_id`, `entry_id`),
  KEY `idx_erp_bank_charge_entries_entry` (`entry_id`),
  CONSTRAINT `fk_erp_bank_charge_entries_charge` FOREIGN KEY (`charge_id`) REFERENCES `erp_bank_charges` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_bank_charge_entries_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
