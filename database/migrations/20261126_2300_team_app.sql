-- Apps da equipe no celular: SAC e Meu Dia (ADR 0660).
-- 1) As sessões por token Bearer da equipe usam as mesmas tabelas do app do morador (ADR 0303), separadas pela
--    coluna audience: 'resident' (todas as linhas que já existem) ou 'team'. Um token só vale no público em que nasceu.
-- 2) team_push_devices: o celular de quem é da equipe, por app ('sac' ou 'meu-dia'), para o push de cada app ir só
--    para quem instalou aquele app. Só o token do Expo; nada de dado pessoal além do usuário.
-- 3) team_push_queue: fila dos pushes da equipe (TeamPush::send grava, tools/team-push-dispatch.php envia pelo cron).
--    Texto curto, sem dado financeiro; data_json leva só o caminho da tela no app.
-- Idempotente.
ALTER TABLE `erp_resident_app_tokens`
  ADD COLUMN IF NOT EXISTS `audience` varchar(10) NOT NULL DEFAULT 'resident' COMMENT 'resident ou team (ADR 0660)' AFTER `user_id`;
ALTER TABLE `erp_resident_app_challenges`
  ADD COLUMN IF NOT EXISTS `audience` varchar(10) NOT NULL DEFAULT 'resident' COMMENT 'resident ou team (ADR 0660)' AFTER `user_id`;

CREATE TABLE IF NOT EXISTS `team_push_devices` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `app` varchar(10) NOT NULL COMMENT 'sac ou meu-dia',
  `token` varchar(200) NOT NULL COMMENT 'ExponentPushToken[...]',
  `platform` varchar(10) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team_push_devices_token_app` (`token`, `app`),
  KEY `idx_team_push_devices_user_app` (`user_id`, `app`),
  CONSTRAINT `fk_team_push_devices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `team_push_queue` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `app` varchar(10) NOT NULL,
  `title` varchar(120) NOT NULL,
  `body` varchar(200) NOT NULL,
  `data_json` varchar(500) DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'pending' COMMENT 'pending, sent, skipped, failed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_team_push_queue_status` (`status`, `id`),
  KEY `idx_team_push_queue_user` (`user_id`),
  CONSTRAINT `fk_team_push_queue_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
