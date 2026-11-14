-- Sessões do app nativo do morador (ADR 0303).
-- 1) erp_resident_app_tokens: uma linha por aparelho conectado. Só o sha256 do token fica no banco; o token
--    (64 caracteres hexadecimais, 256 bits aleatórios) só existe na resposta do login e no cofre do aparelho
--    (Keychain/Keystore). Vale 90 dias a partir do login e cai antes se ficar 30 dias sem uso. Sair, sair de todos os
--    aparelhos (logout global, ADR 0036) ou desativar o usuário derruba a sessão. Nada é apagado: revogado fica com
--    revoked_at e o motivo.
-- 2) erp_resident_app_challenges: o passo do 2FA no login pelo app (sem sessão nem cookie). Desafio de uso único,
--    só o sha256, 5 minutos e no máximo 5 tentativas.
-- Idempotente.
CREATE TABLE IF NOT EXISTS `erp_resident_app_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'sha256 do token Bearer',
  `device_name` varchar(80) DEFAULT NULL COMMENT 'Nome informado pelo app, só para o morador reconhecer o aparelho',
  `created_at` datetime NOT NULL,
  `created_at_ms` bigint unsigned NOT NULL COMMENT 'Instante do login (ms), comparado com o logout global (ADR 0036)',
  `last_used_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoke_reason` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_resident_app_tokens_hash` (`token_hash`),
  KEY `idx_erp_resident_app_tokens_user` (`user_id`, `revoked_at`),
  CONSTRAINT `fk_erp_resident_app_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erp_resident_app_challenges` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `challenge_hash` char(64) NOT NULL,
  `device_name` varchar(80) DEFAULT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_resident_app_challenges_hash` (`challenge_hash`),
  KEY `idx_erp_resident_app_challenges_user` (`user_id`),
  CONSTRAINT `fk_erp_resident_app_challenges_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
