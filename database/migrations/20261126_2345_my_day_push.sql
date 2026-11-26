-- Push do app Meu Dia (ADR 0659). Idempotente.
-- Registro do que já foi avisado no celular da equipe, para cada aviso sair uma vez só:
--   kind 'assigned': alguém marcou uma tarefa para você (ref = id do compromisso em studio_calendar_events);
--   kind 'morning':  resumo das 7h com as tarefas atrasadas e os compromissos do dia (ref = AAAA-MM-DD).
-- O envio em si usa os aparelhos e o serviço de push da equipe (ADR 0657).
CREATE TABLE IF NOT EXISTS `my_day_push_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `kind` varchar(20) NOT NULL,
  `ref` varchar(40) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_my_day_push_log` (`user_id`, `kind`, `ref`),
  KEY `idx_my_day_push_log_created` (`created_at`),
  CONSTRAINT `fk_my_day_push_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Avisos do Meu Dia já enviados ao celular (ADR 0659)';
