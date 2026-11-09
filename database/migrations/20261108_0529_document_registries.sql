-- Documentos operacionais (AP-126 a AP-129, ADR 0050), pelo motor de cadastros: avisos de
-- recebimento, cópias de cheques, empenhos e malotes. O anexo é um arquivo do Disco Virtual
-- (erp_documents, ADR 0047) do mesmo condomínio. Idempotente (a suíte reaplica as migrations).
CREATE TABLE IF NOT EXISTS `erp_delivery_notices` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `unit_id` int unsigned DEFAULT NULL,
  `recipient` varchar(160) NOT NULL,
  `tracking_code` varchar(40) NOT NULL,
  `subject` varchar(160) DEFAULT NULL,
  `sent_on` date NOT NULL,
  `delivered_on` date DEFAULT NULL,
  `document_id` int unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','delivered','returned') NOT NULL DEFAULT 'active' COMMENT 'active = em trânsito',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_delivery_notices_list` (`condominium_id`, `sent_on`, `deleted_at`),
  KEY `idx_erp_delivery_notices_unit` (`unit_id`),
  KEY `idx_erp_delivery_notices_document` (`document_id`),
  CONSTRAINT `fk_erp_delivery_notices_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_delivery_notices_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_delivery_notices_document` FOREIGN KEY (`document_id`) REFERENCES `erp_documents` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_delivery_notices_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_delivery_notices_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erp_check_copies` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `bank_account_id` int unsigned NOT NULL,
  `check_number` int unsigned NOT NULL,
  `issued_on` date NOT NULL,
  `payee` varchar(160) NOT NULL,
  `amount_cents` bigint unsigned NOT NULL,
  `entry_id` int unsigned DEFAULT NULL COMMENT 'Lançamento pago com o cheque',
  `document_id` int unsigned NOT NULL COMMENT 'Imagem do cheque no Disco Virtual',
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active' COMMENT 'inactive = cheque cancelado',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_check_copies_number` (`condominium_id`, `bank_account_id`, `check_number`, `deleted_at`),
  KEY `idx_erp_check_copies_entry` (`entry_id`),
  KEY `idx_erp_check_copies_document` (`document_id`),
  CONSTRAINT `fk_erp_check_copies_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_check_copies_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_check_copies_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_check_copies_document` FOREIGN KEY (`document_id`) REFERENCES `erp_documents` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_check_copies_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_check_copies_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erp_commitments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `supplier_id` int unsigned DEFAULT NULL,
  `description` varchar(160) NOT NULL,
  `amount_cents` bigint unsigned NOT NULL,
  `committed_on` date NOT NULL,
  `settled_on` date DEFAULT NULL,
  `entry_id` int unsigned DEFAULT NULL COMMENT 'Lançamento que liquidou o empenho',
  `document_id` int unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','settled','inactive') NOT NULL DEFAULT 'active' COMMENT 'active = empenhado, settled = liquidado, inactive = cancelado',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_commitments_list` (`condominium_id`, `status`, `committed_on`, `deleted_at`),
  KEY `idx_erp_commitments_supplier` (`supplier_id`),
  KEY `idx_erp_commitments_entry` (`entry_id`),
  KEY `idx_erp_commitments_document` (`document_id`),
  CONSTRAINT `fk_erp_commitments_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_commitments_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_commitments_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_commitments_document` FOREIGN KEY (`document_id`) REFERENCES `erp_documents` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_commitments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_commitments_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erp_pouches` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `sent_on` date NOT NULL,
  `courier` varchar(160) NOT NULL COMMENT 'Portador',
  `documents_count` smallint unsigned NOT NULL,
  `contents` text NOT NULL COMMENT 'Lista dos documentos do malote',
  `received_on` date DEFAULT NULL,
  `checked_on` date DEFAULT NULL,
  `document_id` int unsigned DEFAULT NULL COMMENT 'Protocolo do malote no Disco Virtual',
  `notes` text DEFAULT NULL,
  `status` enum('active','received','checked') NOT NULL DEFAULT 'active' COMMENT 'active = enviado',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_pouches_list` (`condominium_id`, `sent_on`, `deleted_at`),
  KEY `idx_erp_pouches_document` (`document_id`),
  CONSTRAINT `fk_erp_pouches_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_pouches_document` FOREIGN KEY (`document_id`) REFERENCES `erp_documents` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_pouches_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_pouches_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissões (prefixo erp. nega sem registro, ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar avisos de recebimento','erp.delivery_notices.view','ERP Documentos','Consultar as correspondências com aviso de recebimento'),
('Gerenciar avisos de recebimento','erp.delivery_notices.manage','ERP Documentos','Registrar envio, entrega e devolução de avisos de recebimento'),
('Visualizar cópias de cheques','erp.check_copies.view','ERP Documentos','Consultar as cópias dos cheques emitidos'),
('Gerenciar cópias de cheques','erp.check_copies.manage','ERP Documentos','Registrar cheques com a imagem e cancelar'),
('Visualizar empenhos','erp.commitments.view','ERP Documentos','Consultar despesas empenhadas e liquidadas'),
('Gerenciar empenhos','erp.commitments.manage','ERP Documentos','Empenhar, liquidar e cancelar despesas'),
('Visualizar malotes','erp.pouches.view','ERP Documentos','Consultar os malotes enviados e recebidos'),
('Gerenciar malotes','erp.pouches.manage','ERP Documentos','Registrar envio, recebimento e conferência de malotes');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.delivery_notices.view','erp.delivery_notices.manage','erp.check_copies.view',
  'erp.check_copies.manage','erp.commitments.view','erp.commitments.manage','erp.pouches.view','erp.pouches.manage');
