-- Acesso de imobiliárias parceiras e de moradores (ADR 0090): imobiliária com usuários de papel fixo
-- erp_partner, só leitura (AP-148); convite de morador com token em hash, 7 dias e uso único (AP-152);
-- matriz do portal do morador por condomínio, tudo desligado por padrão (AP-153).
-- Idempotente (a suíte reaplica as migrations a cada execução).
CREATE TABLE IF NOT EXISTS `erp_partner_agencies` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `document` varchar(14) NOT NULL COMMENT 'CNPJ, só dígitos',
  `email` varchar(254) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_partner_agencies_document` (`document`),
  CONSTRAINT `fk_erp_partner_agencies_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_partner_agencies_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Um usuário pertence a no máximo uma imobiliária.
CREATE TABLE IF NOT EXISTS `erp_partner_agency_users` (
  `user_id` int unsigned NOT NULL,
  `agency_id` int unsigned NOT NULL,
  `linked_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  KEY `idx_erp_partner_agency_users_agency` (`agency_id`),
  CONSTRAINT `fk_erp_partner_agency_users_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_partner_agency_users_agency` FOREIGN KEY (`agency_id`) REFERENCES `erp_partner_agencies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_partner_agency_users_linked_by` FOREIGN KEY (`linked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Convite do morador: só o hash do token fica aqui.
CREATE TABLE IF NOT EXISTS `erp_resident_invitations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `resident_id` int unsigned NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'sha256 do token; o token só vai no aviso',
  `status` enum('pending','accepted','revoked') NOT NULL DEFAULT 'pending',
  `expires_at` datetime NOT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `accepted_user_id` int unsigned DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_resident_invitations_token` (`token_hash`),
  KEY `idx_erp_resident_invitations_resident` (`resident_id`, `status`),
  KEY `idx_erp_resident_invitations_condominium` (`condominium_id`, `created_at`),
  CONSTRAINT `fk_erp_resident_invitations_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_resident_invitations_resident` FOREIGN KEY (`resident_id`) REFERENCES `erp_residents` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_resident_invitations_user` FOREIGN KEY (`accepted_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_resident_invitations_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Matriz do portal do morador: linha ausente = desligado.
CREATE TABLE IF NOT EXISTS `erp_resident_portal_permissions` (
  `condominium_id` int unsigned NOT NULL,
  `feature` enum('boletos','reservations','occurrences','announcements','documents') NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` int unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`condominium_id`, `feature`),
  CONSTRAINT `fk_erp_resident_portal_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_resident_portal_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissões (prefixo erp. nega sem registro, ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar acessos do ERP','erp.access_admin.view','ERP Configurações','Consultar imobiliárias, convites de moradores e a matriz do portal'),
('Gerenciar acessos do ERP','erp.access_admin.manage','ERP Configurações','Cadastrar imobiliárias, conceder e revogar acesso, convidar moradores e mudar a matriz do portal'),
('Consulta de parceiro','erp.partner.view','ERP Parceiros','Imobiliária parceira: unidades e moradores, com contato mascarado, dos condomínios concedidos');

-- Papel fixo da imobiliária: só entra no ERP e vê a consulta de parceiro. Não é de sistema da plataforma.
INSERT IGNORE INTO `access_roles` (`name`,`slug`,`level`,`description`,`is_system`) VALUES
('Imobiliária parceira','erp_partner',1,'Somente leitura de unidades e moradores, com contato mascarado, nos condomínios concedidos',1);

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.access_admin.view','erp.access_admin.manage');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug = 'erp_partner' AND p.slug IN ('erp.access','erp.partner.view');
