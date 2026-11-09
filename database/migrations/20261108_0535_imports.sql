-- Importações (ADR 0058): extrato OFX (AP-140) e planilhas CSV/XLSX de lançamentos (AP-144),
-- lançamentos por unidade (AP-145), contas a pagar (AP-146), frações ideais (AP-143) e
-- inadimplentes (AP-142). Cada importação tem prévia e só grava se o arquivo inteiro passar; o mesmo
-- arquivo (sha256) não é aplicado duas vezes no mesmo condomínio e tipo. O arquivo fica em
-- storage/private/imports, fora da pasta pública. Idempotente (a suíte reaplica as migrations).
CREATE TABLE IF NOT EXISTS `erp_imports` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `kind` enum('ofx','entries','unit_entries','payables','fractions','delinquents') NOT NULL,
  `bank_account_id` int unsigned DEFAULT NULL COMMENT 'OFX: conta do extrato',
  `original_name` varchar(160) NOT NULL,
  `sha256` char(64) NOT NULL,
  `storage_path` varchar(255) NOT NULL COMMENT 'Relativo a storage/private/imports',
  `size_bytes` int unsigned NOT NULL,
  `status` enum('previewed','applied') NOT NULL DEFAULT 'previewed',
  `rows_count` int unsigned NOT NULL DEFAULT 0,
  `error_count` int unsigned NOT NULL DEFAULT 0,
  `summary_json` text DEFAULT NULL COMMENT 'Totais da prévia ou da aplicação',
  `created_by` int unsigned DEFAULT NULL,
  `applied_by` int unsigned DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_imports_file` (`condominium_id`, `kind`, `sha256`),
  KEY `idx_erp_imports_list` (`condominium_id`, `kind`, `created_at`),
  CONSTRAINT `fk_erp_imports_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_imports_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_imports_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_imports_applied_by` FOREIGN KEY (`applied_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Linhas do extrato OFX (AP-140) e a conciliação com o que já foi lançado: conferida (um lançamento
-- pago ou uma transferência da mesma conta, mesmo valor, até 3 dias de diferença), pendente
-- (nenhum ou mais de um candidato: nunca escolhe sozinha) ou ignorada com nota. O extrato não cria
-- nem baixa lançamento. (bank_account_id, fitid) único: extratos que se sobrepõem não repetem linha.
CREATE TABLE IF NOT EXISTS `erp_bank_statement_lines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `bank_account_id` int unsigned NOT NULL,
  `import_id` int unsigned NOT NULL,
  `fitid` varchar(80) NOT NULL,
  `kind` enum('credit','debit') NOT NULL,
  `posted_on` date NOT NULL,
  `amount_cents` bigint NOT NULL COMMENT 'Com sinal: crédito positivo, débito negativo',
  `memo` varchar(160) DEFAULT NULL,
  `status` enum('matched','unmatched','ignored') NOT NULL,
  `entry_id` int unsigned DEFAULT NULL,
  `transfer_id` int unsigned DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `resolved_by` int unsigned DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_bank_statement_lines_fitid` (`bank_account_id`, `fitid`),
  UNIQUE KEY `uq_erp_bank_statement_lines_entry` (`entry_id`),
  UNIQUE KEY `uq_erp_bank_statement_lines_transfer` (`transfer_id`, `bank_account_id`),
  KEY `idx_erp_bank_statement_lines_review` (`condominium_id`, `status`, `posted_on`),
  KEY `idx_erp_bank_statement_lines_import` (`import_id`),
  CONSTRAINT `fk_erp_bank_statement_lines_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_statement_lines_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_statement_lines_import` FOREIGN KEY (`import_id`) REFERENCES `erp_imports` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_statement_lines_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_statement_lines_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `erp_bank_transfers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_statement_lines_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lançamento criado por importação: rastreio da origem (não trava o cadastro).
ALTER TABLE `erp_entries`
  ADD COLUMN IF NOT EXISTS `import_id` int unsigned DEFAULT NULL AFTER `extra_charge_id`,
  ADD INDEX IF NOT EXISTS `idx_erp_entries_import` (`import_id`),
  ADD CONSTRAINT `fk_erp_entries_import` FOREIGN KEY IF NOT EXISTS (`import_id`) REFERENCES `erp_imports` (`id`) ON DELETE RESTRICT;

-- Permissões (prefixo erp. nega sem registro, ADR 0006). Aplicar exige também a permissão do destino.
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar importações','erp.imports.view','ERP Importações','Consultar as importações, as prévias e o extrato conciliado'),
('Gerenciar importações','erp.imports.manage','ERP Importações','Enviar arquivos, gerar a prévia, aplicar e resolver linhas do extrato');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.imports.view','erp.imports.manage');
