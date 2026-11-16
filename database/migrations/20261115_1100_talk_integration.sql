-- Integração com o Connect Talk, o atendimento por WhatsApp (ADR 0308; contrato em docs/api-erp.md do repositório talk).
-- 1) Nova origem "talk" nos chamados: toda conversa do Talk vira um chamado do Help Desk.
-- 2) external_ref: o protocolo do Talk. Único por origem, para o POST repetido (rede que caiu) não duplicar o chamado.
-- 3) erp_integration_tokens: token de serviço (máquina a máquina) ligado a um usuário comum de users, com as
--    permissões e o escopo de condomínios que esse usuário já tem. Só o sha256 do token fica no banco; o token só
--    aparece uma vez, no terminal (tools/talk-token.php). Nada é apagado: revogado fica com revoked_at e o motivo.
-- Idempotente.
ALTER TABLE studio_support_tickets
    MODIFY COLUMN origin ENUM('helpdesk','erp','site','residents','api','talk') NOT NULL DEFAULT 'helpdesk';

ALTER TABLE studio_support_tickets
    ADD COLUMN IF NOT EXISTS external_ref VARCHAR(40) NULL COMMENT 'Protocolo do sistema de origem (Talk), ADR 0308' AFTER origin,
    ADD UNIQUE INDEX IF NOT EXISTS uq_support_ticket_external (origin, external_ref);

CREATE TABLE IF NOT EXISTS `erp_integration_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `client` varchar(30) NOT NULL COMMENT 'Sistema que usa o token (talk)',
  `name` varchar(80) NOT NULL,
  `user_id` int unsigned NOT NULL COMMENT 'Usuário de integração: as permissões e o escopo de condomínios dele valem para o token',
  `token_hash` char(64) NOT NULL COMMENT 'sha256 do token Bearer',
  `token_hint` char(4) NOT NULL COMMENT 'Últimos 4 caracteres, só para reconhecer o token na lista',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoke_reason` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_integration_tokens_hash` (`token_hash`),
  KEY `idx_erp_integration_tokens_client` (`client`, `revoked_at`),
  CONSTRAINT `fk_erp_integration_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
