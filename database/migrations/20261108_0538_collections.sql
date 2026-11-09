-- Cobrança e multas (ADR 0080): multas pendentes com a origem (AP-116), atendimento de cobrança
-- (AP-117) e acompanhamento da cobrança judicial (AP-039). A cobrança judicial só marca e acompanha:
-- não envia nada a advogado nem a tribunal e não cria encargo. Idempotente (a suíte reaplica).

-- Origem da multa avulsa (ADR 0057): ocorrência ou reserva de área comum que a motivou (opcional).
ALTER TABLE `erp_extra_charges`
  ADD COLUMN IF NOT EXISTS `occurrence_id` int unsigned DEFAULT NULL AFTER `related_entry_id`,
  ADD COLUMN IF NOT EXISTS `reservation_id` int unsigned DEFAULT NULL AFTER `occurrence_id`,
  ADD CONSTRAINT `fk_erp_extra_charges_occurrence` FOREIGN KEY IF NOT EXISTS (`occurrence_id`) REFERENCES `erp_occurrences` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_erp_extra_charges_reservation` FOREIGN KEY IF NOT EXISTS (`reservation_id`) REFERENCES `erp_reservations` (`id`) ON DELETE RESTRICT;

-- Atendimento de cobrança (AP-117): cada contato com a unidade em atraso, com o resultado, a
-- promessa de pagamento e a próxima ação. Registro histórico: não se edita nem se apaga.
CREATE TABLE IF NOT EXISTS `erp_collection_contacts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `unit_id` int unsigned NOT NULL,
  `channel` enum('phone','email','whatsapp','in_person','letter') NOT NULL,
  `outcome` enum('promise','negotiating','no_answer','refused','paid_claimed','other') NOT NULL,
  `contact_name` varchar(160) DEFAULT NULL COMMENT 'Com quem falou',
  `promise_on` date DEFAULT NULL,
  `promise_cents` bigint unsigned DEFAULT NULL,
  `next_action_on` date DEFAULT NULL,
  `note` varchar(1000) NOT NULL,
  `open_cents` bigint unsigned NOT NULL DEFAULT 0 COMMENT 'Valor vencido em aberto no momento do contato',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_collection_contacts_unit` (`unit_id`, `created_at`),
  KEY `idx_erp_collection_contacts_next` (`condominium_id`, `next_action_on`),
  CONSTRAINT `fk_erp_collection_contacts_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_collection_contacts_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_collection_contacts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cobrança judicial (AP-039): o caso da unidade, as contas que ele cobre (valor na abertura) e o
-- andamento. Um caso aberto por unidade (open_key). Nada sai daqui para advogado ou tribunal.
CREATE TABLE IF NOT EXISTS `erp_legal_cases` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `unit_id` int unsigned NOT NULL,
  `lawyer_id` int unsigned DEFAULT NULL,
  `status` enum('preparing','filed','suspended','agreement','closed') NOT NULL DEFAULT 'preparing',
  `case_number` varchar(40) DEFAULT NULL COMMENT 'Número do processo, quando houver',
  `court` varchar(160) DEFAULT NULL,
  `opened_on` date NOT NULL,
  `claimed_cents` bigint unsigned NOT NULL COMMENT 'Soma das contas na abertura, sem encargos',
  `closed_on` date DEFAULT NULL,
  `close_reason` varchar(255) DEFAULT NULL,
  `open_key` tinyint GENERATED ALWAYS AS (CASE WHEN `status` <> 'closed' THEN 1 ELSE NULL END) STORED,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_legal_cases_open` (`unit_id`, `open_key`),
  KEY `idx_erp_legal_cases_list` (`condominium_id`, `status`),
  CONSTRAINT `fk_erp_legal_cases_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_legal_cases_unit` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_legal_cases_lawyer` FOREIGN KEY (`lawyer_id`) REFERENCES `erp_lawyers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_legal_cases_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erp_legal_case_entries` (
  `case_id` int unsigned NOT NULL,
  `entry_id` int unsigned NOT NULL,
  `amount_cents` bigint unsigned NOT NULL,
  PRIMARY KEY (`case_id`, `entry_id`),
  KEY `idx_erp_legal_case_entries_entry` (`entry_id`),
  CONSTRAINT `fk_erp_legal_case_entries_case` FOREIGN KEY (`case_id`) REFERENCES `erp_legal_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_legal_case_entries_entry` FOREIGN KEY (`entry_id`) REFERENCES `erp_entries` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erp_legal_case_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `case_id` int unsigned NOT NULL,
  `kind` enum('opened','note','status','closed') NOT NULL,
  `from_status` varchar(20) DEFAULT NULL,
  `to_status` varchar(20) DEFAULT NULL,
  `note` varchar(1000) NOT NULL,
  `happened_on` date NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_legal_case_events_case` (`case_id`, `happened_on`),
  CONSTRAINT `fk_erp_legal_case_events_case` FOREIGN KEY (`case_id`) REFERENCES `erp_legal_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_legal_case_events_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissões (prefixo erp. nega sem registro, ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar cobrança','erp.collections.view','ERP Cobrança','Consultar multas pendentes, atendimento de cobrança e casos judiciais'),
('Gerenciar cobrança','erp.collections.manage','ERP Cobrança','Registrar contatos de cobrança e abrir e acompanhar casos judiciais');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.collections.view','erp.collections.manage');
