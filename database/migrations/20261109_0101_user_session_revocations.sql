-- ADR 0035: sair em um host (erp/studio/helpdesk) encerra as sessões do usuário nos outros.
-- Cada host tem o seu cookie de sessão; o logout grava aqui o instante (ms) e toda sessão
-- iniciada antes dele deixa de valer no próximo pedido.
CREATE TABLE IF NOT EXISTS user_session_revocations (
    user_id INT UNSIGNED NOT NULL,
    revoked_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
