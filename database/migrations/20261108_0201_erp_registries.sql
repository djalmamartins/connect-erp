-- Cadastros do ERP (grupo Cadastros). Escopo por condomínio (ADR-002); exclusão lógica em deleted_at.
-- Campos PROPOSTOS: ver docs/Organic Code/decisions/0014-registry-engine.md.

-- Proprietários, inquilinos, moradores e responsáveis financeiros (AP-005)
CREATE TABLE IF NOT EXISTS `erp_residents` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `unit_label` varchar(40) NOT NULL COMMENT 'Unidade em texto até o módulo Unidades (AP-006)',
  `relationship` enum('owner','tenant','resident','financial') NOT NULL,
  `name` varchar(160) NOT NULL,
  `document` varchar(14) DEFAULT NULL COMMENT 'CPF ou CNPJ, somente dígitos',
  `email` varchar(254) DEFAULT NULL,
  `phone` varchar(13) DEFAULT NULL,
  `starts_on` date DEFAULT NULL,
  `ends_on` date DEFAULT NULL,
  `billing_by_email` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_residents_document` (`document`),
  KEY `idx_erp_residents_condominium` (`condominium_id`, `deleted_at`, `status`),
  CONSTRAINT `fk_erp_residents_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_residents_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_residents_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Síndico, subsíndico e conselho (AP-014)
CREATE TABLE IF NOT EXISTS `erp_board_members` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `role` enum('sindico','subsindico','fiscal','consultivo','suplente') NOT NULL,
  `name` varchar(160) NOT NULL,
  `document` varchar(14) DEFAULT NULL COMMENT 'CPF ou CNPJ (síndico profissional), somente dígitos',
  `unit_label` varchar(40) DEFAULT NULL,
  `term_starts_on` date NOT NULL,
  `term_ends_on` date NOT NULL,
  `email` varchar(254) DEFAULT NULL,
  `phone` varchar(13) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_board_members_role` (`condominium_id`, `role`, `status`),
  KEY `idx_erp_board_members_condominium` (`condominium_id`, `deleted_at`, `status`),
  CONSTRAINT `fk_erp_board_members_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_board_members_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_board_members_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Funcionários próprios do condomínio (AP-009)
CREATE TABLE IF NOT EXISTS `erp_employees` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `job` varchar(20) NOT NULL,
  `name` varchar(160) NOT NULL,
  `document` char(11) NOT NULL COMMENT 'CPF, somente dígitos',
  `hired_on` date NOT NULL,
  `terminated_on` date DEFAULT NULL,
  `shift` varchar(10) DEFAULT NULL,
  `salary_cents` bigint unsigned DEFAULT NULL,
  `phone` varchar(13) DEFAULT NULL,
  `email` varchar(254) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_employees_document` (`document`),
  KEY `idx_erp_employees_condominium` (`condominium_id`, `deleted_at`, `status`),
  CONSTRAINT `fk_erp_employees_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_employees_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_employees_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fornecedores da administradora Connect (AP-010)
CREATE TABLE IF NOT EXISTS `erp_suppliers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `legal_name` varchar(160) NOT NULL,
  `document` varchar(14) NOT NULL COMMENT 'CNPJ ou CPF, somente dígitos',
  `trade_name` varchar(160) DEFAULT NULL,
  `category` varchar(20) NOT NULL,
  `email` varchar(254) DEFAULT NULL,
  `phone` varchar(13) DEFAULT NULL,
  `bank_code` char(3) DEFAULT NULL,
  `bank_agency` varchar(10) DEFAULT NULL,
  `bank_account` varchar(20) DEFAULT NULL,
  `pix_key` varchar(140) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_suppliers_document` (`document`),
  KEY `idx_erp_suppliers_status` (`deleted_at`, `status`),
  CONSTRAINT `fk_erp_suppliers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_suppliers_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Advogados e escritórios parceiros (AP-016)
CREATE TABLE IF NOT EXISTS `erp_lawyers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `oab` varchar(12) NOT NULL COMMENT 'UF e número, ex.: SP 123456',
  `document` char(11) DEFAULT NULL,
  `firm` varchar(160) DEFAULT NULL,
  `email` varchar(254) DEFAULT NULL,
  `phone` varchar(13) DEFAULT NULL,
  `fee_percent` decimal(5,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_lawyers_oab` (`oab`),
  KEY `idx_erp_lawyers_status` (`deleted_at`, `status`),
  CONSTRAINT `fk_erp_lawyers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_lawyers_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Veículos por unidade (AP-011)
CREATE TABLE IF NOT EXISTS `erp_vehicles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `unit_label` varchar(40) NOT NULL,
  `plate` char(7) NOT NULL,
  `kind` varchar(12) NOT NULL,
  `model` varchar(80) DEFAULT NULL,
  `color` varchar(30) DEFAULT NULL,
  `parking_spot` varchar(20) DEFAULT NULL,
  `owner_name` varchar(160) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_vehicles_plate` (`plate`),
  KEY `idx_erp_vehicles_condominium` (`condominium_id`, `deleted_at`, `status`),
  CONSTRAINT `fk_erp_vehicles_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_vehicles_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_vehicles_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Visitantes frequentes e prestadores autorizados (AP-012)
CREATE TABLE IF NOT EXISTS `erp_visitors` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `unit_label` varchar(40) NOT NULL,
  `name` varchar(160) NOT NULL,
  `document` varchar(20) DEFAULT NULL COMMENT 'CPF (dígitos) ou RG/outro (alfanumérico)',
  `phone` varchar(13) DEFAULT NULL,
  `permanent_access` tinyint(1) NOT NULL DEFAULT 0,
  `valid_until` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_visitors_condominium` (`condominium_id`, `deleted_at`, `status`),
  CONSTRAINT `fk_erp_visitors_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_visitors_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_visitors_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Animais de estimação por unidade (AP-013)
CREATE TABLE IF NOT EXISTS `erp_pets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `unit_label` varchar(40) NOT NULL,
  `name` varchar(80) NOT NULL,
  `species` varchar(10) NOT NULL,
  `size` varchar(10) DEFAULT NULL,
  `breed` varchar(80) DEFAULT NULL,
  `tutor_name` varchar(160) DEFAULT NULL,
  `vaccinated` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_pets_condominium` (`condominium_id`, `deleted_at`, `status`),
  CONSTRAINT `fk_erp_pets_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_pets_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_pets_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contatos úteis; sem condomínio vale para todos (AP-015)
CREATE TABLE IF NOT EXISTS `erp_useful_contacts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned DEFAULT NULL,
  `service` varchar(20) NOT NULL,
  `name` varchar(160) NOT NULL,
  `phone` varchar(13) NOT NULL,
  `email` varchar(254) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_useful_contacts_condominium` (`condominium_id`, `deleted_at`, `status`),
  CONSTRAINT `fk_erp_useful_contacts_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_useful_contacts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_useful_contacts_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
