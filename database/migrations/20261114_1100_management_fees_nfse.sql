-- Honorários da administradora e pedidos de NFS-e (ADR 0402). Não é cobrança de SaaS (ADR-002): é o contrato
-- de administração que a Connect mantém com cada condomínio que administra, e a nota que ela emite a ele.
-- Nesta etapa o pedido da nota só fica registrado; o envio à API Nacional da NFS-e vem depois, com a chave
-- nfse.enabled (erp_settings), que nasce desligada. Idempotente (a suíte reaplica as migrations).
CREATE TABLE IF NOT EXISTS `erp_management_contracts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `monthly_fee_cents` bigint unsigned NOT NULL COMMENT 'Valor mensal dos honorários',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `due_day` tinyint unsigned NOT NULL CHECK (`due_day` BETWEEN 1 AND 28),
  `service_description` varchar(255) NOT NULL,
  `status` enum('active','suspended','ended') NOT NULL DEFAULT 'active',
  `notes` varchar(500) DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_management_contracts_condominium` (`condominium_id`),
  CONSTRAINT `fk_erp_management_contracts_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_management_contracts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_management_contracts_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Um pedido de nota por contrato e competência; um pedido cancelado libera a competência para novo pedido.
CREATE TABLE IF NOT EXISTS `erp_nfse_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `contract_id` int unsigned NOT NULL,
  `condominium_id` int unsigned NOT NULL,
  `competence` char(7) NOT NULL COMMENT 'AAAA-MM',
  `amount_cents` bigint unsigned NOT NULL,
  `service_description` varchar(255) NOT NULL,
  `status` enum('requested','issued','failed','cancelled') NOT NULL DEFAULT 'requested',
  `open_key` tinyint unsigned GENERATED ALWAYS AS (IF(`status` = 'cancelled', NULL, 1)) STORED,
  `nfse_number` varchar(20) DEFAULT NULL,
  `access_key` varchar(60) DEFAULT NULL COMMENT 'Chave de acesso devolvida pela API Nacional',
  `cancel_reason` varchar(255) DEFAULT NULL,
  `requested_by` int unsigned DEFAULT NULL,
  `cancelled_by` int unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_nfse_requests_open` (`contract_id`, `competence`, `open_key`),
  KEY `idx_erp_nfse_requests_condominium` (`condominium_id`, `competence`),
  CONSTRAINT `fk_erp_nfse_requests_contract` FOREIGN KEY (`contract_id`) REFERENCES `erp_management_contracts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_nfse_requests_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_nfse_requests_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_nfse_requests_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissões (prefixo erp. nega sem registro, ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar honorários','erp.management_fees.view','ERP Honorários','Consultar os contratos de administração e os pedidos de nota'),
('Gerenciar honorários','erp.management_fees.manage','ERP Honorários','Alterar contratos de administração e solicitar ou cancelar pedidos de nota');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.management_fees.view','erp.management_fees.manage');
