-- Configurações do ERP (ADR 0085): parâmetros por chave (padrão da Connect com condominium_id NULL,
-- valor próprio do condomínio por cima), saldo inicial por centro de custo (AP-162) e previsão
-- orçamentária (AP-163). Nenhuma credencial (banco, SMTP) fica aqui: vem do ambiente (ADR 0060).
-- Idempotente (a suíte reaplica as migrations a cada execução).
CREATE TABLE IF NOT EXISTS `erp_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned DEFAULT NULL COMMENT 'NULL = padrão da Connect',
  `setting_key` varchar(80) NOT NULL,
  `value_json` varchar(1000) NOT NULL,
  `scope_key` int unsigned GENERATED ALWAYS AS (COALESCE(`condominium_id`, 0)) STORED,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_settings_key` (`scope_key`, `setting_key`),
  CONSTRAINT `fk_erp_settings_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Saldo inicial por centro de custo (AP-162): um valor (com sinal) por condomínio e centro, numa data.
CREATE TABLE IF NOT EXISTS `erp_cost_center_openings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `cost_center_id` int unsigned NOT NULL,
  `opened_on` date NOT NULL,
  `amount_cents` bigint NOT NULL COMMENT 'Pode ser negativo',
  `notes` varchar(255) DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_cost_center_openings` (`condominium_id`, `cost_center_id`),
  CONSTRAINT `fk_erp_cost_center_openings_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_cost_center_openings_center` FOREIGN KEY (`cost_center_id`) REFERENCES `erp_cost_centers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_cost_center_openings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Previsão orçamentária (AP-163): valor previsto por condomínio, ano, conta analítica e mês.
CREATE TABLE IF NOT EXISTS `erp_budget_lines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `year` smallint unsigned NOT NULL,
  `chart_account_id` int unsigned NOT NULL,
  `month` tinyint unsigned NOT NULL COMMENT '1 a 12',
  `amount_cents` bigint unsigned NOT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_budget_lines` (`condominium_id`, `year`, `chart_account_id`, `month`),
  KEY `idx_erp_budget_lines_year` (`condominium_id`, `year`),
  CONSTRAINT `fk_erp_budget_lines_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_budget_lines_account` FOREIGN KEY (`chart_account_id`) REFERENCES `erp_chart_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_budget_lines_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Banners do aplicativo (AP-156): texto curto por período, sem link. condominium_id NULL = todos.
CREATE TABLE IF NOT EXISTS `erp_banners` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned DEFAULT NULL,
  `title` varchar(80) NOT NULL,
  `message` varchar(500) NOT NULL,
  `starts_on` date NOT NULL,
  `ends_on` date NOT NULL,
  `position` int DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_banners_period` (`condominium_id`, `deleted_at`, `starts_on`, `ends_on`),
  CONSTRAINT `fk_erp_banners_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_banners_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_banners_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Impostos (AP-164): alíquota de retenção de referência. condominium_id NULL = padrão da Connect.
CREATE TABLE IF NOT EXISTS `erp_taxes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned DEFAULT NULL,
  `kind` enum('iss','irrf','inss','pis_cofins_csll','outro') NOT NULL,
  `name` varchar(80) NOT NULL,
  `rate` decimal(5,2) NOT NULL,
  `applies_to` enum('servicos','materiais','todos') NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_taxes_list` (`condominium_id`, `deleted_at`, `kind`),
  CONSTRAINT `fk_erp_taxes_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_taxes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_taxes_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissões (prefixo erp. nega sem registro, ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar configurações do ERP','erp.settings.view','ERP Configurações','Consultar parâmetros, saldo inicial por centro de custo e previsão orçamentária'),
('Gerenciar configurações do ERP','erp.settings.manage','ERP Configurações','Alterar parâmetros, saldo inicial por centro de custo, previsão orçamentária e frações ideais');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.settings.view','erp.settings.manage');
