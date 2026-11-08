-- Issue #7, risco 5 (ADR 0023): falhas de login e 2FA por conta e por IP, além do limite por sessão.
-- subject_hash é um HMAC do e-mail/CPF ou do IP; nada em texto.
CREATE TABLE IF NOT EXISTS auth_throttle_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope VARCHAR(16) NOT NULL,
    subject_hash CHAR(64) NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_auth_throttle_subject (scope, subject_hash, created_at),
    KEY idx_auth_throttle_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
