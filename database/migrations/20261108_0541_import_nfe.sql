-- Importação de NF-e XML (AP-141, ADR 0086): novo tipo em erp_imports, as opções da prévia (a conta de
-- despesa escolhida) e a chave de acesso única por condomínio. Sem consulta à SEFAZ.
-- Idempotente (a suíte reaplica as migrations a cada execução).
ALTER TABLE `erp_imports`
  MODIFY `kind` enum('ofx','entries','unit_entries','payables','fractions','delinquents','readings','nfe') NOT NULL,
  ADD COLUMN IF NOT EXISTS `options_json` varchar(500) DEFAULT NULL COMMENT 'Opções da prévia (NF-e: conta de despesa)' AFTER `bank_account_id`;

-- NF-e lançada: a chave de acesso (44 dígitos) não se repete no condomínio.
CREATE TABLE IF NOT EXISTS `erp_nfe_documents` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `access_key` char(44) NOT NULL,
  `import_id` int unsigned NOT NULL,
  `supplier_id` int unsigned NOT NULL,
  `number` varchar(9) NOT NULL,
  `series` varchar(3) NOT NULL,
  `issued_on` date NOT NULL,
  `total_cents` bigint unsigned NOT NULL,
  `installments` smallint unsigned NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_nfe_documents_key` (`condominium_id`, `access_key`),
  KEY `idx_erp_nfe_documents_import` (`import_id`),
  CONSTRAINT `fk_erp_nfe_documents_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_nfe_documents_import` FOREIGN KEY (`import_id`) REFERENCES `erp_imports` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_nfe_documents_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_nfe_documents_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
