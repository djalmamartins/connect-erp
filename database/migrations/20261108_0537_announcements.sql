-- Comunicação pela outbox (ADR 0059): comunicados gerais (AP-111), aviso de cobrança (AP-137),
-- envio agendado (AP-138) e endereços de comunicação (AP-139). O envio é o da fila (ADR 0055):
-- fora de produção só log ou caixa de teste. Idempotente (a suíte reaplica as migrations).
CREATE TABLE IF NOT EXISTS `erp_announcements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `kind` enum('general','collection') NOT NULL COMMENT 'general = comunicado; collection = aviso de cobrança',
  `subject` varchar(120) NOT NULL,
  `body` text DEFAULT NULL COMMENT 'Texto do comunicado (o aviso de cobrança usa o modelo)',
  `audience` enum('all','units') NOT NULL DEFAULT 'all',
  `send_at` datetime NOT NULL COMMENT 'Quando entra na fila (agora ou agendado)',
  `units_count` int unsigned NOT NULL DEFAULT 0,
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int unsigned DEFAULT NULL,
  `cancelled_by` int unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_erp_announcements_list` (`condominium_id`, `kind`, `send_at`),
  CONSTRAINT `fk_erp_announcements_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_announcements_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_announcements_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quem desmarcar não recebe comunicado geral por e-mail (AP-139). Aviso de cobrança segue o opt-in
-- billing_by_email (ADR 0056). Padrão 0 = recebe, como antes.
ALTER TABLE `erp_residents`
  ADD COLUMN IF NOT EXISTS `notices_opt_out` tinyint(1) NOT NULL DEFAULT 0 AFTER `billing_by_email`;

-- Permissões (prefixo erp. nega sem registro, ADR 0006).
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Visualizar comunicados','erp.announcements.view','ERP Comunicação','Consultar comunicados, avisos de cobrança, agendados e endereços de comunicação'),
('Gerenciar comunicados','erp.announcements.manage','ERP Comunicação','Criar, agendar e cancelar comunicados e avisos de cobrança');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug IN ('erp.announcements.view','erp.announcements.manage');
