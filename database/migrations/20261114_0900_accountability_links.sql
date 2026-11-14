-- Link público da prestação de contas para os moradores (ADR 0300).
-- 1) erp_accountability_links: um link por condomínio, tipo (mensal ou anual) e competência (AAAA-MM ou AAAA), com o
--    livro agrupado congelado em JSON (sha256 e versão). O token (32 caracteres hexadecimais aleatórios) é o que vai no
--    link; corrigir e gerar de novo mantém o token e sobe a versão. Revogar desliga o token; gerar de novo depois cria
--    um token novo. Nada é apagado.
-- 2) erp_accountability_alerts: os avisos do prazo do 5º dia útil já dados à equipe (uma vez por situação).
-- 3) Tipo de anexo novo "conta" (conta ou fatura do fornecedor) em erp_entry_attachments.
-- Idempotente.
CREATE TABLE IF NOT EXISTS `erp_accountability_links` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `kind` enum('monthly','annual') NOT NULL DEFAULT 'monthly',
  `competence` varchar(7) NOT NULL COMMENT 'AAAA-MM (mensal) ou AAAA (anual)',
  `token` char(32) NOT NULL COMMENT 'Parte secreta do link público',
  `status` enum('active','revoked') NOT NULL DEFAULT 'active',
  `version` smallint unsigned NOT NULL DEFAULT 1,
  `sha256` char(64) NOT NULL COMMENT 'sha256 de payload_json',
  `payload_json` longtext NOT NULL COMMENT 'Livro agrupado (sem caminho de arquivo) em JSON canônico',
  `generated_by` int unsigned DEFAULT NULL,
  `generated_at` datetime NOT NULL,
  `sent_version` smallint unsigned DEFAULT NULL COMMENT 'Versão enviada por e-mail aos moradores',
  `sent_at` datetime DEFAULT NULL,
  `revoked_by` int unsigned DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoke_reason` varchar(255) DEFAULT NULL,
  `view_count` int unsigned NOT NULL DEFAULT 0,
  `last_viewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_accountability_links_period` (`condominium_id`, `kind`, `competence`),
  UNIQUE KEY `uq_erp_accountability_links_token` (`token`),
  CONSTRAINT `fk_erp_accountability_links_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_accountability_links_generated_by` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_accountability_links_revoked_by` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erp_accountability_alerts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `competence` char(7) NOT NULL COMMENT 'AAAA-MM do livro',
  `situation` enum('due_soon','overdue') NOT NULL,
  `notified` smallint unsigned NOT NULL DEFAULT 0 COMMENT 'Pessoas da equipe avisadas',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_accountability_alerts` (`condominium_id`, `competence`, `situation`),
  CONSTRAINT `fk_erp_accountability_alerts_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `erp_entry_attachments`
  MODIFY COLUMN `kind` enum('conta','nota_fiscal','comprovante','recibo','outro') NOT NULL;
