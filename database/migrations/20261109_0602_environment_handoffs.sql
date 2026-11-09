-- Troca de ambiente entre hosts (ADR 0034): erp.<domínio> e studio.<domínio> têm cookies de
-- sessão separados. O alternador emite um código de uso único (60 s) no host de origem, já
-- autenticado e com o 2FA concluído, e o host de destino troca o código pela sessão.
-- Só o hash SHA-256 do código fica no banco.
CREATE TABLE IF NOT EXISTS auth_environment_handoffs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    environment VARCHAR(20) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_ip VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_env_handoff_token (token_hash),
    KEY idx_env_handoff_expires (expires_at),
    CONSTRAINT fk_env_handoff_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
