-- Anexos dos lançamentos (ADR 0174): nota fiscal, comprovante de pagamento, recibo ou outro documento
-- ligado a um lançamento (AP-020/022/023). O arquivo fica no Disco Virtual (erp_documents, ADR 0047), na
-- pasta "Notas fiscais" ou na nova pasta "Comprovantes"; esta tabela só faz o vínculo. Um mesmo arquivo pode
-- servir a mais de um lançamento (ex.: uma nota com duas parcelas). Exclusão lógica, auditada.
-- Idempotente (a suíte reaplica as migrations a cada execução).
ALTER TABLE `erp_documents`
  MODIFY COLUMN `folder` enum('atas','contratos','plantas','notas_fiscais','comprovantes','outros') NOT NULL;

CREATE TABLE IF NOT EXISTS `erp_entry_attachments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `entry_id` int unsigned NOT NULL,
  `document_id` int unsigned NOT NULL,
  `kind` enum('nota_fiscal','comprovante','recibo','outro') NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_entry_attachments_entry` (`entry_id`, `deleted_at`),
  KEY `idx_erp_entry_attachments_condominium` (`condominium_id`, `deleted_at`),
  KEY `idx_erp_entry_attachments_document` (`document_id`),
  CONSTRAINT `fk_erp_entry_attachments_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_entry_attachments_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_entry_attachments_document` FOREIGN KEY (`document_id`) REFERENCES `erp_documents` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_entry_attachments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_entry_attachments_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
