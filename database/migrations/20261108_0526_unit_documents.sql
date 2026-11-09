-- Documentos da unidade (AP-123, 124, 125 e 130; ADR 0044): certidão de débitos, declaração de
-- inadimplência, comprovante de quitação e carta de anuência, gerados das contas a receber da unidade
-- e guardados com a foto do conteúdo (payload_json) e um código de verificação único. Emitido não
-- muda; cancelar exige motivo. Idempotente (a suíte reaplica as migrations a cada execução).
CREATE TABLE IF NOT EXISTS `erp_unit_documents` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `unit_id` int unsigned NOT NULL,
  `kind` enum('debt_certificate','delinquency_declaration','quittance','consent_letter') NOT NULL,
  `as_of` date NOT NULL COMMENT 'Data de referência',
  `reference` varchar(40) DEFAULT NULL COMMENT 'Competência final (quitação) ou finalidade (anuência)',
  `result` enum('clear','debts') NOT NULL COMMENT 'clear = nada consta / quitado',
  `total_cents` bigint unsigned NOT NULL DEFAULT 0,
  `payload_json` longtext NOT NULL,
  `verification_code` char(16) NOT NULL,
  `status` enum('issued','cancelled') NOT NULL DEFAULT 'issued',
  `issued_by` int unsigned DEFAULT NULL,
  `cancelled_by` int unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_unit_documents_code` (`verification_code`),
  KEY `idx_erp_unit_documents_list` (`condominium_id`, `kind`, `created_at`),
  KEY `idx_erp_unit_documents_unit` (`unit_id`),
  CONSTRAINT `fk_erp_unit_documents_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_unit_documents_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_unit_documents_issued_by` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_unit_documents_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissões (prefixo erp. nega sem registro, ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar documentos da unidade','erp.unit_documents.view','ERP Documentos','Consultar e verificar certidões, declarações, quitações e cartas de anuência emitidas'),
('Emitir documentos da unidade','erp.unit_documents.manage','ERP Documentos','Emitir e cancelar certidão de débitos, declaração de inadimplência, comprovante de quitação e carta de anuência');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.unit_documents.view','erp.unit_documents.manage');
