-- Síndico e conselho escolhidos entre os moradores do prédio e os síndicos profissionais (ADR 0686, pedido do Djalma,
-- 27/11/2026). Idempotente.
-- erp_professional_managers: síndicos profissionais da Connect (pessoa ou empresa), usados por todos os condomínios,
-- como fornecedores e advogados (escopo da administradora, ADR-002). Sem condominium_id de propósito: o mesmo síndico
-- profissional atende vários prédios; o vínculo com cada um fica em erp_board_members.
CREATE TABLE IF NOT EXISTS `erp_professional_managers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(160) NOT NULL,
  `document` varchar(14) DEFAULT NULL COMMENT 'CPF ou CNPJ, somente dígitos',
  `contact_name` varchar(160) DEFAULT NULL COMMENT 'Pessoa responsável quando é empresa',
  `email` varchar(254) NOT NULL,
  `phone` varchar(13) NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_erp_professional_managers_status` (`deleted_at`, `status`),
  KEY `idx_erp_professional_managers_document` (`document`),
  CONSTRAINT `fk_erp_professional_managers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_professional_managers_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Membro ligado ao morador escolhido (resident_id) ou ao síndico profissional (professional_manager_id), e a duração
-- do mandato (1 ou 2 anos; o máximo é 2). Registros antigos ficam com os três vazios e continuam valendo.
ALTER TABLE `erp_board_members`
  ADD COLUMN IF NOT EXISTS `resident_id` int(10) unsigned DEFAULT NULL AFTER `unit_id`,
  ADD COLUMN IF NOT EXISTS `professional_manager_id` int(10) unsigned DEFAULT NULL AFTER `resident_id`,
  ADD COLUMN IF NOT EXISTS `term_years` tinyint(3) unsigned DEFAULT NULL AFTER `unit_label`,
  ADD KEY IF NOT EXISTS `idx_erp_board_members_resident` (`resident_id`),
  ADD KEY IF NOT EXISTS `idx_erp_board_members_professional` (`professional_manager_id`);

ALTER TABLE `erp_board_members`
  ADD CONSTRAINT `fk_erp_board_members_resident` FOREIGN KEY IF NOT EXISTS (`resident_id`) REFERENCES `erp_residents` (`id`),
  ADD CONSTRAINT `fk_erp_board_members_professional` FOREIGN KEY IF NOT EXISTS (`professional_manager_id`) REFERENCES `erp_professional_managers` (`id`);
