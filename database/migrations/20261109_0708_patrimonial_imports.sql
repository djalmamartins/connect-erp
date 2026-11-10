-- Importação de lançamentos patrimoniais (ADR 0171), no mesmo fluxo de prévia e aplicação das outras
-- importações (ADR 0058), mas numa tabela própria. Assim o tipo não entra em Imports::KINDS, que exige tela
-- própria por tipo, e não muda o enum de erp_imports.
-- Idempotência: a chave é única por condomínio. Vale a chave enviada pelo cliente ou, sem ela, o sha256 do
-- arquivo. A mesma importação não é gravada duas vezes, e a aplicação é tudo ou nada, numa transação.
-- O conteúdo (CSV/XLSX de até 2 MB) fica na própria linha, para a aplicação revalidar exatamente o que foi
-- visto na prévia. Idempotente.
CREATE TABLE IF NOT EXISTS `erp_patrimonial_imports` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `idempotency_key` varchar(80) NOT NULL,
  `sha256` char(64) NOT NULL,
  `original_name` varchar(160) NOT NULL,
  `content` mediumblob NOT NULL,
  `status` enum('previewed','applied') NOT NULL DEFAULT 'previewed',
  `rows_count` int unsigned NOT NULL DEFAULT 0,
  `error_count` int unsigned NOT NULL DEFAULT 0,
  `summary_json` text DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `applied_by` int unsigned DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_patrimonial_imports_key` (`condominium_id`, `idempotency_key`),
  KEY `idx_erp_patrimonial_imports_list` (`condominium_id`, `status`, `created_at`),
  CONSTRAINT `fk_erp_patrimonial_imports_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_patrimonial_imports_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_patrimonial_imports_applied_by` FOREIGN KEY (`applied_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `erp_patrimonial_entries`
  ADD COLUMN IF NOT EXISTS `import_id` int unsigned DEFAULT NULL COMMENT 'Importação de origem (ADR 0171)' AFTER `linked_entry_id`,
  ADD INDEX IF NOT EXISTS `idx_erp_patrimonial_entries_import` (`import_id`);
