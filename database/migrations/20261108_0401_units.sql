-- Unidades dos condomínios (AP-006, ADR 0040). Escopo por condomínio (ADR-002); exclusão lógica.
-- `label` é gerado (Bloco - Número) e é o texto gravado em unit_label dos cadastros vinculados.
CREATE TABLE IF NOT EXISTS `erp_units` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `block` varchar(20) DEFAULT NULL COMMENT 'Bloco ou torre; vazio quando o condomínio não tem blocos',
  `number` varchar(40) NOT NULL COMMENT 'Identificação da unidade (101, Casa 3, Loja 2)',
  `label` varchar(64) GENERATED ALWAYS AS (CONCAT_WS(' - ', NULLIF(`block`, ''), `number`)) STORED,
  `type` enum('apartamento','casa','sala','loja','garagem','outro') NOT NULL DEFAULT 'apartamento',
  `floor` varchar(10) DEFAULT NULL,
  `ideal_fraction` decimal(9,6) DEFAULT NULL COMMENT 'Fração ideal (0 a 1)',
  `private_area` decimal(10,2) DEFAULT NULL COMMENT 'Área privativa em m²',
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_units_condominium` (`condominium_id`, `deleted_at`, `status`),
  KEY `idx_erp_units_label` (`condominium_id`, `label`),
  CONSTRAINT `fk_erp_units_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_units_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_units_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
