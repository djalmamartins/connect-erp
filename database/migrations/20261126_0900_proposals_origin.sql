-- Propostas criadas no ERP (ADR 0653). Idempotente; só apaga a permissão de propostas dos papéis fora da administração.
-- origin: 'site' para o formulário "Solicite uma proposta" (tudo o que já existe) ou como a equipe recebeu o pedido
-- (telefone, whatsapp, email, indicacao, presencial, outro). created_by: quem cadastrou no ERP (NULL quando veio do site).
-- Conta da proposta (calculadora da tela): preço por unidade da administradora e do síndico profissional e o mínimo
-- mensal que compensa, em centavos. Só para conferência interna: não vai no e-mail nem no PDF.
ALTER TABLE `proposals`
  ADD COLUMN IF NOT EXISTS `origin` varchar(20) NOT NULL DEFAULT 'site' AFTER `source_url`,
  ADD COLUMN IF NOT EXISTS `created_by` int(10) unsigned DEFAULT NULL AFTER `origin`,
  ADD COLUMN IF NOT EXISTS `price_admin_unit_cents` int(10) unsigned DEFAULT NULL AFTER `created_by`,
  ADD COLUMN IF NOT EXISTS `price_syndic_unit_cents` int(10) unsigned DEFAULT NULL AFTER `price_admin_unit_cents`,
  ADD COLUMN IF NOT EXISTS `min_monthly_cents` int(10) unsigned DEFAULT NULL AFTER `price_syndic_unit_cents`;

-- ADR 0653: propostas só para administração e desenvolvimento (pedido do Djalma, 26/11). Tira proposals.manage dos
-- outros papéis (o módulo antigo do Studio dava ao Gerente). Exceção por usuário (access_user_overrides) continua valendo.
DELETE rp FROM `access_role_permissions` rp
  JOIN `access_permissions` p ON p.id = rp.permission_id AND p.slug = 'proposals.manage'
  JOIN `access_roles` r ON r.id = rp.role_id
 WHERE r.slug NOT IN ('developer', 'super_admin', 'client_admin');
INSERT IGNORE INTO `access_role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p ON p.slug = 'proposals.manage'
 WHERE r.slug IN ('developer', 'super_admin', 'client_admin');

-- As versões em PDF passam a ser gravadas pelo ERP. Instalações antigas criavam esta tabela só pelo módulo do Studio
-- (components/proposals/database/002); aqui ela é garantida, igual ao schema base. Não muda nada se já existir.
CREATE TABLE IF NOT EXISTS `proposal_responses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `proposal_id` int(10) unsigned NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `template_type` enum('syndic','administrator') NOT NULL DEFAULT 'syndic',
  `subject` varchar(255) NOT NULL,
  `introduction` text NOT NULL,
  `scope` text NOT NULL,
  `commercial_terms` text NOT NULL,
  `payment_terms` varchar(500) DEFAULT NULL,
  `valid_until` date NOT NULL,
  `notes` text DEFAULT NULL,
  `pdf_path` varchar(500) DEFAULT NULL,
  `status` enum('draft','generated','queued','sent','failed') NOT NULL DEFAULT 'draft',
  `queued_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_proposal_responses_proposal` (`proposal_id`,`created_at`),
  KEY `idx_proposal_responses_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `mail_queue`
  ADD COLUMN IF NOT EXISTS `attachments_json` longtext DEFAULT NULL AFTER `body`,
  ADD COLUMN IF NOT EXISTS `proposal_response_id` int(10) unsigned DEFAULT NULL AFTER `notification_message_id`,
  ADD KEY IF NOT EXISTS `idx_mail_queue_proposal_response` (`proposal_response_id`);
