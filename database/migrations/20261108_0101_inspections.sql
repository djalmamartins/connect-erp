-- Vistoria presencial de condomínios (docs/modules/vistorias.md, ADR 0013).
-- Toda vistoria pertence a um condomínio (ADR-002: segregação por condominium_id).

-- Ponto de referência do condomínio para o check-in por GPS.
CREATE TABLE IF NOT EXISTS `condominium_geolocations` (
  `condominium_id` int unsigned NOT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `radius_m` smallint unsigned NOT NULL DEFAULT 150 COMMENT 'Raio aceito no check-in, em metros',
  `updated_by` int unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`condominium_id`),
  CONSTRAINT `fk_cgeo_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cgeo_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modelos de checklist da Connect (administradora única: modelos valem para todos os condomínios).
CREATE TABLE IF NOT EXISTS `inspection_templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `frequency` enum('weekly','biweekly','monthly','quarterly','semiannual','yearly') NOT NULL DEFAULT 'monthly',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspection_templates_name` (`name`),
  KEY `idx_inspection_templates_status` (`status`),
  CONSTRAINT `fk_itpl_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inspection_template_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `template_id` int unsigned NOT NULL,
  `position` smallint unsigned NOT NULL,
  `section` varchar(80) NOT NULL DEFAULT 'Geral',
  `label` varchar(200) NOT NULL,
  `requires_photo` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_itpl_items_template` (`template_id`, `position`),
  CONSTRAINT `fk_itpl_items_template` FOREIGN KEY (`template_id`) REFERENCES `inspection_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quais modelos cada condomínio segue e com que periodicidade.
CREATE TABLE IF NOT EXISTS `inspection_plans` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `template_id` int unsigned NOT NULL,
  `frequency` enum('weekly','biweekly','monthly','quarterly','semiannual','yearly') NOT NULL,
  `starts_on` date NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspection_plans` (`condominium_id`, `template_id`),
  KEY `idx_inspection_plans_template` (`template_id`),
  CONSTRAINT `fk_iplan_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_iplan_template` FOREIGN KEY (`template_id`) REFERENCES `inspection_templates` (`id`),
  CONSTRAINT `fk_iplan_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uma visita presencial. O check-in guarda o que o aparelho informou e o resultado da checagem no servidor.
CREATE TABLE IF NOT EXISTS `inspections` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `template_id` int unsigned NOT NULL,
  `plan_id` int unsigned DEFAULT NULL,
  `inspector_id` int unsigned DEFAULT NULL,
  `status` enum('in_progress','completed','cancelled') NOT NULL DEFAULT 'in_progress',
  `template_name` varchar(120) NOT NULL COMMENT 'Cópia do nome do modelo no início da vistoria',
  `checkin_at` datetime NOT NULL,
  `checkin_latitude` decimal(10,7) DEFAULT NULL,
  `checkin_longitude` decimal(10,7) DEFAULT NULL,
  `checkin_accuracy_m` decimal(8,1) DEFAULT NULL,
  `checkin_distance_m` decimal(10,1) DEFAULT NULL,
  `checkin_status` enum('inside','outside','no_reference','no_signal') NOT NULL,
  `checkin_note` varchar(500) DEFAULT NULL COMMENT 'Justificativa quando fora do raio ou sem sinal',
  `summary` text DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inspections_condominium` (`condominium_id`, `status`, `checkin_at`),
  KEY `idx_inspections_plan` (`plan_id`, `status`, `completed_at`),
  KEY `idx_inspections_inspector` (`inspector_id`),
  CONSTRAINT `fk_insp_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`),
  CONSTRAINT `fk_insp_template` FOREIGN KEY (`template_id`) REFERENCES `inspection_templates` (`id`),
  CONSTRAINT `fk_insp_plan` FOREIGN KEY (`plan_id`) REFERENCES `inspection_plans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_insp_inspector` FOREIGN KEY (`inspector_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Itens copiados do modelo no início: editar o modelo depois não altera vistorias já feitas.
CREATE TABLE IF NOT EXISTS `inspection_answers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `inspection_id` int unsigned NOT NULL,
  `position` smallint unsigned NOT NULL,
  `section` varchar(80) NOT NULL,
  `label` varchar(200) NOT NULL,
  `requires_photo` tinyint(1) NOT NULL DEFAULT 0,
  `result` enum('pending','ok','issue','not_applicable') NOT NULL DEFAULT 'pending',
  `note` varchar(1000) DEFAULT NULL,
  `answered_by` int unsigned DEFAULT NULL,
  `answered_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_insp_answers_inspection` (`inspection_id`, `position`),
  CONSTRAINT `fk_insp_answers_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `inspections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_insp_answers_user` FOREIGN KEY (`answered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fotos de evidência. Ficam em storage/private (fora do acesso público) e só saem por rota autorizada.
CREATE TABLE IF NOT EXISTS `inspection_photos` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `inspection_id` int unsigned NOT NULL,
  `answer_id` int unsigned NOT NULL,
  `path` varchar(255) NOT NULL COMMENT 'Relativo a storage/private',
  `mime` varchar(40) NOT NULL,
  `size_bytes` int unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `width` smallint unsigned NOT NULL,
  `height` smallint unsigned NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `uploaded_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspection_photos_path` (`path`),
  KEY `idx_insp_photos_answer` (`answer_id`),
  KEY `idx_insp_photos_inspection` (`inspection_id`),
  CONSTRAINT `fk_insp_photos_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `inspections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_insp_photos_answer` FOREIGN KEY (`answer_id`) REFERENCES `inspection_answers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_insp_photos_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de cada envio do relatório (quem enviou, para quem, item da fila de e-mail).
CREATE TABLE IF NOT EXISTS `inspection_report_deliveries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `inspection_id` int unsigned NOT NULL,
  `recipient_email` varchar(190) NOT NULL,
  `recipient_name` varchar(160) DEFAULT NULL,
  `sent_by` int unsigned DEFAULT NULL,
  `queued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_insp_deliveries_inspection` (`inspection_id`),
  CONSTRAINT `fk_insp_deliveries_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `inspections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_insp_deliveries_user` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissões no núcleo access_* existente (ADR-001).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar vistorias','erp.inspections.view','ERP','Consultar vistorias e relatórios dos condomínios vinculados'),
('Realizar vistorias','erp.inspections.perform','ERP','Fazer check-in, preencher o checklist, anexar fotos e enviar relatórios'),
('Configurar vistorias','erp.inspections.manage','ERP','Gerenciar modelos de checklist, planos por condomínio e localização');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin')
  AND p.slug IN ('erp.inspections.view','erp.inspections.perform','erp.inspections.manage');

-- Modelos iniciais (INFERIDO: itens comuns de ronda condominial; a Connect ajusta pela tela de modelos).
INSERT IGNORE INTO `inspection_templates` (`name`,`description`,`frequency`) VALUES
('Ronda semanal','Verificação rápida das áreas comuns e da segurança','weekly'),
('Vistoria mensal','Vistoria completa de áreas comuns, equipamentos e manutenção','monthly');

INSERT INTO `inspection_template_items` (`template_id`,`position`,`section`,`label`,`requires_photo`)
SELECT t.id, i.position, i.section, i.label, i.requires_photo FROM `inspection_templates` t
JOIN (
  SELECT 1 AS position, 'Portaria e acesso' AS section, 'Portões e interfone funcionando' AS label, 0 AS requires_photo
  UNION ALL SELECT 2, 'Portaria e acesso', 'Câmeras e iluminação da entrada', 0
  UNION ALL SELECT 3, 'Áreas comuns', 'Limpeza de hall, escadas e corredores', 1
  UNION ALL SELECT 4, 'Áreas comuns', 'Lâmpadas queimadas', 0
  UNION ALL SELECT 5, 'Áreas comuns', 'Lixeiras e coleta de lixo', 0
  UNION ALL SELECT 6, 'Segurança', 'Extintores no lugar e sinalização de saída', 0
  UNION ALL SELECT 7, 'Garagem', 'Garagem limpa e sem vazamentos', 0
) i
WHERE t.name = 'Ronda semanal'
  AND NOT EXISTS (SELECT 1 FROM `inspection_template_items` x WHERE x.template_id = t.id);

INSERT INTO `inspection_template_items` (`template_id`,`position`,`section`,`label`,`requires_photo`)
SELECT t.id, i.position, i.section, i.label, i.requires_photo FROM `inspection_templates` t
JOIN (
  SELECT 1 AS position, 'Fachada e estrutura' AS section, 'Fachada, muros e pintura' AS label, 1 AS requires_photo
  UNION ALL SELECT 2, 'Fachada e estrutura', 'Infiltrações e rachaduras visíveis', 1
  UNION ALL SELECT 3, 'Hidráulica', 'Caixa d''água tampada e sem vazamento', 1
  UNION ALL SELECT 4, 'Hidráulica', 'Bombas e casa de máquinas', 0
  UNION ALL SELECT 5, 'Elétrica', 'Quadros elétricos fechados e identificados', 0
  UNION ALL SELECT 6, 'Elétrica', 'Iluminação de emergência', 0
  UNION ALL SELECT 7, 'Segurança contra incêndio', 'Extintores dentro da validade', 1
  UNION ALL SELECT 8, 'Segurança contra incêndio', 'Hidrantes, mangueiras e portas corta-fogo', 0
  UNION ALL SELECT 9, 'Elevadores', 'Elevadores funcionando e com manutenção em dia', 0
  UNION ALL SELECT 10, 'Áreas de lazer', 'Piscina, salão e playground', 1
  UNION ALL SELECT 11, 'Áreas de lazer', 'Jardins e paisagismo', 0
  UNION ALL SELECT 12, 'Funcionários', 'Uniforme, EPI e postos cobertos', 0
) i
WHERE t.name = 'Vistoria mensal'
  AND NOT EXISTS (SELECT 1 FROM `inspection_template_items` x WHERE x.template_id = t.id);
