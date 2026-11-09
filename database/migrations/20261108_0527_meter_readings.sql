-- Leituras e consumo (AP-041 a AP-043, ADR 0045): relógios por unidade, tarifas em faixas
-- progressivas por tipo e as leituras mensais, com consumo e valor calculados. A cobrança gera conta
-- a receber da unidade (erp_entries) sem duplicar: entry_id único por leitura.
-- Idempotente (a suíte reaplica as migrations a cada execução).
CREATE TABLE IF NOT EXISTS `erp_meters` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `unit_id` int unsigned NOT NULL,
  `kind` enum('agua','gas','energia') NOT NULL,
  `number` varchar(40) NOT NULL,
  `installed_on` date DEFAULT NULL,
  `initial_reading` decimal(12,3) DEFAULT NULL COMMENT 'Leitura na instalação (base da primeira leitura)',
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_meters_condominium` (`condominium_id`, `kind`, `deleted_at`),
  KEY `idx_erp_meters_unit` (`unit_id`),
  CONSTRAINT `fk_erp_meters_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_meters_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_meters_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_meters_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Faixa de tarifa: de from_amount até to_amount (vazio = sem limite), preço por unidade de consumo.
-- Sem condomínio = tarifa padrão da Connect; com condomínio = própria dele (substitui o padrão).
CREATE TABLE IF NOT EXISTS `erp_tariffs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned DEFAULT NULL,
  `kind` enum('agua','gas','energia') NOT NULL,
  `name` varchar(80) NOT NULL,
  `from_amount` decimal(12,3) NOT NULL DEFAULT 0,
  `to_amount` decimal(12,3) DEFAULT NULL,
  `price_cents` bigint unsigned NOT NULL COMMENT 'Preço por m³ (ou kWh) em centavos',
  `valid_from` date NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_tariffs_lookup` (`kind`, `condominium_id`, `valid_from`, `deleted_at`),
  CONSTRAINT `fk_erp_tariffs_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_tariffs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_tariffs_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erp_meter_readings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `meter_id` int unsigned NOT NULL,
  `competence` char(7) NOT NULL COMMENT 'AAAA-MM',
  `read_on` date NOT NULL,
  `previous_reading` decimal(12,3) NOT NULL,
  `reading` decimal(12,3) NOT NULL,
  `consumption` decimal(12,3) NOT NULL,
  `amount_cents` bigint unsigned NOT NULL DEFAULT 0,
  `tariff_valid_from` date DEFAULT NULL COMMENT 'Vigência das faixas usadas no cálculo',
  `entry_id` int unsigned DEFAULT NULL COMMENT 'Conta a receber gerada na cobrança',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_meter_readings_month` (`meter_id`, `competence`),
  UNIQUE KEY `uq_erp_meter_readings_entry` (`entry_id`),
  KEY `idx_erp_meter_readings_list` (`condominium_id`, `competence`),
  CONSTRAINT `fk_erp_meter_readings_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_meter_readings_meter` FOREIGN KEY (`meter_id`) REFERENCES `erp_meters` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_meter_readings_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_meter_readings_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_meter_readings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissões (prefixo erp. nega sem registro, ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar relógios','erp.meters.view','ERP Leituras','Consultar os relógios (medidores) das unidades'),
('Gerenciar relógios','erp.meters.manage','ERP Leituras','Cadastrar, alterar e excluir relógios'),
('Visualizar tarifas','erp.tariffs.view','ERP Leituras','Consultar as faixas de tarifa de água, gás e energia'),
('Gerenciar tarifas','erp.tariffs.manage','ERP Leituras','Cadastrar e alterar faixas de tarifa'),
('Visualizar leituras','erp.meter_readings.view','ERP Leituras','Consultar leituras, consumo e valores'),
('Gerenciar leituras','erp.meter_readings.manage','ERP Leituras','Lançar leituras e gerar a cobrança do consumo');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.meters.view','erp.meters.manage','erp.tariffs.view','erp.tariffs.manage',
  'erp.meter_readings.view','erp.meter_readings.manage');
