-- Assembleia e votação (ADR 0088): edital com pauta (AP-018, presencial, virtual ou híbrida), presença
-- com procuração (AP-088), votação por item com base de cálculo e quórum próprios (AP-017) e ata gerada e
-- imutável depois de fechada (AP-092). Unidade inadimplente não vota (Código Civil, art. 1.335, III).
-- Idempotente (a suíte reaplica as migrations a cada execução).
CREATE TABLE IF NOT EXISTS `erp_assemblies` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `kind` enum('ordinaria','extraordinaria') NOT NULL,
  `mode` enum('presencial','virtual','hibrida') NOT NULL,
  `title` varchar(160) NOT NULL,
  `location` varchar(255) NOT NULL COMMENT 'Endereço ou o link da sala virtual',
  `first_call_at` datetime NOT NULL COMMENT 'Primeira convocação',
  `second_call_at` datetime DEFAULT NULL COMMENT 'Segunda convocação',
  `status` enum('draft','convened','open','closed','cancelled') NOT NULL DEFAULT 'draft',
  `announcement_id` int unsigned DEFAULT NULL COMMENT 'Comunicado do edital (ADR 0059)',
  `convened_at` datetime DEFAULT NULL,
  `opened_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `minutes` mediumtext DEFAULT NULL COMMENT 'Ata gerada no fechamento',
  `minutes_sha256` char(64) DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_assemblies_list` (`condominium_id`, `status`, `first_call_at`),
  CONSTRAINT `fk_erp_assemblies_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_assemblies_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `erp_announcements` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_assemblies_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_assemblies_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Itens da pauta: cada um com a base de cálculo do voto e o quórum.
CREATE TABLE IF NOT EXISTS `erp_assembly_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `assembly_id` int unsigned NOT NULL,
  `position` smallint unsigned NOT NULL,
  `title` varchar(160) NOT NULL,
  `description` text DEFAULT NULL,
  `vote_basis` enum('fraction','unit') NOT NULL COMMENT 'Fração ideal ou uma unidade, um voto',
  `quorum` enum('simple','absolute','two_thirds','unanimity') NOT NULL,
  `status` enum('pending','voting','decided') NOT NULL DEFAULT 'pending',
  `result_json` text DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_assembly_items_position` (`assembly_id`, `position`),
  CONSTRAINT `fk_erp_assembly_items_assembly` FOREIGN KEY (`assembly_id`) REFERENCES `erp_assemblies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Presença (AP-088): uma linha por unidade, com o presente, a procuração e se a unidade pode votar.
CREATE TABLE IF NOT EXISTS `erp_assembly_attendance` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `assembly_id` int unsigned NOT NULL,
  `unit_id` int unsigned NOT NULL,
  `attendee_name` varchar(160) NOT NULL,
  `is_proxy` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Presente por procuração',
  `proxy_document_id` int unsigned DEFAULT NULL COMMENT 'Procuração no Disco Virtual (ADR 0047)',
  `eligible` tinyint(1) NOT NULL COMMENT 'Quite na abertura (art. 1.335, III)',
  `overdue_cents` bigint unsigned NOT NULL DEFAULT 0,
  `checked_in_at` datetime NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_assembly_attendance_unit` (`assembly_id`, `unit_id`),
  KEY `idx_erp_assembly_attendance_proxy` (`assembly_id`, `is_proxy`, `attendee_name`),
  CONSTRAINT `fk_erp_assembly_attendance_assembly` FOREIGN KEY (`assembly_id`) REFERENCES `erp_assemblies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_assembly_attendance_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_assembly_attendance_document` FOREIGN KEY (`proxy_document_id`) REFERENCES `erp_documents` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_assembly_attendance_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Votos (AP-017): um por unidade e item, com o peso (fração ideal ou 1) no momento do voto.
CREATE TABLE IF NOT EXISTS `erp_assembly_votes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `assembly_id` int unsigned NOT NULL,
  `item_id` int unsigned NOT NULL,
  `unit_id` int unsigned NOT NULL,
  `choice` enum('yes','no','abstain') NOT NULL,
  `weight` decimal(9,6) NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_assembly_votes_unit` (`item_id`, `unit_id`),
  KEY `idx_erp_assembly_votes_assembly` (`assembly_id`),
  CONSTRAINT `fk_erp_assembly_votes_assembly` FOREIGN KEY (`assembly_id`) REFERENCES `erp_assemblies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_assembly_votes_item` FOREIGN KEY (`item_id`) REFERENCES `erp_assembly_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_assembly_votes_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_assembly_votes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissões (prefixo erp. nega sem registro, ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar assembleias','erp.assemblies.view','ERP Comunicação','Consultar assembleias, pauta, presença, votos, resultados e atas'),
('Gerenciar assembleias','erp.assemblies.manage','ERP Comunicação','Criar e convocar assembleias, registrar presença e votos, apurar e fechar a ata');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.assemblies.view','erp.assemblies.manage');
