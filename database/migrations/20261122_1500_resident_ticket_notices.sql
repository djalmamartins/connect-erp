-- Avisos do chamado no app do morador e push no celular (ADR 0641). Idempotente.
-- Cada movimentação da equipe no chamado (abertura, resposta ao morador, situação, equipe, responsável) vira um aviso
-- para quem pediu. Nota interna nunca vira aviso. Não é e-mail: o morador continua recebendo só os 5 e-mails da ADR 0601.
CREATE TABLE IF NOT EXISTS `resident_ticket_notices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT 'Quem pediu o chamado (morador com conta)',
  `ticket_id` int(10) unsigned NOT NULL,
  `event` varchar(20) NOT NULL COMMENT 'opened, reply, status, team, assigned',
  `title` varchar(160) NOT NULL,
  `detail` varchar(255) NOT NULL DEFAULT '',
  `tone` varchar(10) NOT NULL DEFAULT 'info' COMMENT 'info, warning, success',
  `push_status` varchar(10) NOT NULL DEFAULT 'none' COMMENT 'none (sem celular), pending, sent, skipped, failed',
  `pushed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_resident_ticket_notices_user` (`user_id`, `created_at`),
  KEY `idx_resident_ticket_notices_push` (`push_status`, `id`),
  KEY `idx_resident_ticket_notices_ticket` (`ticket_id`),
  CONSTRAINT `fk_resident_ticket_notice_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `studio_support_tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resident_ticket_notice_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Avisos do chamado para o app do morador (ADR 0641)';

-- Celulares do morador para o push (token do Expo). Sair do app ou o Expo dizer que o aparelho saiu apaga o token.
CREATE TABLE IF NOT EXISTS `resident_push_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `token` varchar(200) NOT NULL,
  `platform` varchar(10) NOT NULL DEFAULT '' COMMENT 'ios, android',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_resident_push_tokens_token` (`token`),
  KEY `idx_resident_push_tokens_user` (`user_id`),
  CONSTRAINT `fk_resident_push_token_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Celulares do morador para o push (ADR 0641)';

-- Equipe do chamado passa a ser uma lista (Administrativo, Financeiro, Manutenção, Geral). Os textos antigos que já
-- batem com a lista ficam com a grafia certa; os outros ficam como estão até alguém escolher uma equipe da lista.
UPDATE `studio_support_tickets` SET `team` = 'Administrativo' WHERE LOWER(TRIM(`team`)) IN ('administrativo', 'administração', 'administracao', 'adm');
UPDATE `studio_support_tickets` SET `team` = 'Financeiro' WHERE LOWER(TRIM(`team`)) IN ('financeiro', 'finanças', 'financas', 'cobrança', 'cobranca');
UPDATE `studio_support_tickets` SET `team` = 'Manutenção' WHERE LOWER(TRIM(`team`)) IN ('manutenção', 'manutencao');
UPDATE `studio_support_tickets` SET `team` = 'Geral' WHERE LOWER(TRIM(`team`)) IN ('geral');
