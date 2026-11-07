-- Tokens de recuperação de senha: só o hash SHA-256, expiração curta e uso único.
-- Substitui o uso de users.forget no fluxo /forget (users.forget permanece para o legado).
CREATE TABLE IF NOT EXISTS `user_password_resets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `requested_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_password_resets_token` (`token_hash`),
  KEY `idx_user_password_resets_user` (`user_id`, `used_at`),
  CONSTRAINT `fk_user_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
