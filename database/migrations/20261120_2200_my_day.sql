-- Meu Dia e vistoria que vira chamado (ADR 0628). Idempotente.
-- inspection_plans.responsible_id: quem recebe o chamado da vistoria do plano (vazio = sem responsável fixo).
-- inspection_plan_tickets: um chamado por plano e por data devida; a chave única impede chamado repetido
--   quando a rotina diária roda mais de uma vez. inspection_id liga a vistoria que fechou o chamado.
-- studio_calendar_events.condominium_id/ticket_id: o compromisso da agenda (já existente no Studio) pode ser de um
--   condomínio e agendar um chamado, para aparecer no Meu Dia e sair da lista "Para agendar".
ALTER TABLE `inspection_plans`
    ADD COLUMN IF NOT EXISTS `responsible_id` int unsigned DEFAULT NULL COMMENT 'Quem recebe o chamado da vistoria (ADR 0628)' AFTER `status`,
    ADD CONSTRAINT `fk_iplan_responsible` FOREIGN KEY IF NOT EXISTS (`responsible_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `inspection_plan_tickets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `plan_id` int unsigned NOT NULL,
  `due_on` date NOT NULL COMMENT 'Data devida da vistoria que gerou o chamado',
  `ticket_id` int unsigned NOT NULL,
  `inspection_id` int unsigned DEFAULT NULL COMMENT 'Vistoria concluída que fechou o chamado',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspection_plan_tickets` (`plan_id`, `due_on`),
  KEY `idx_inspection_plan_tickets_ticket` (`ticket_id`),
  CONSTRAINT `fk_iptk_plan` FOREIGN KEY (`plan_id`) REFERENCES `inspection_plans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_iptk_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `studio_support_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_iptk_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `inspections` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `studio_calendar_events`
    ADD COLUMN IF NOT EXISTS `condominium_id` int unsigned DEFAULT NULL COMMENT 'Condomínio do compromisso (ADR 0628)' AFTER `assigned_to`,
    ADD COLUMN IF NOT EXISTS `ticket_id` int unsigned DEFAULT NULL COMMENT 'Chamado agendado por este compromisso (ADR 0628)' AFTER `condominium_id`,
    ADD INDEX IF NOT EXISTS `idx_calendar_condominium` (`condominium_id`),
    ADD INDEX IF NOT EXISTS `idx_calendar_ticket` (`ticket_id`);

-- Ver o Meu Dia de outra pessoa da equipe (gerente). Cada um sempre vê o próprio.
INSERT IGNORE INTO `access_permissions` (`name`,`slug`,`group_name`,`description`) VALUES
('Ver o Meu Dia da equipe','erp.myday.team','ERP','Ver a agenda e as tarefas do dia de outras pessoas da equipe e marcar compromissos para elas');

INSERT IGNORE INTO `access_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id FROM `access_roles` r JOIN `access_permissions` p
WHERE r.slug IN ('developer','super_admin') AND p.slug = 'erp.myday.team';
