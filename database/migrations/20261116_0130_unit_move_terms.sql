-- Revisão de 15/11, etapa 2 (ADR 0311): texto do termo de entrada e de saída da unidade (modelo 2). Um texto por
-- condomínio e tipo, ou o geral da Connect (condominium_id vazio), usado pelos condomínios sem texto próprio.
-- scope_key (0 = geral) deixa a chave única valer também para o geral. Idempotente.
CREATE TABLE IF NOT EXISTS `erp_unit_move_terms` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned DEFAULT NULL COMMENT 'Vazio = texto geral da Connect',
  `scope_key` int unsigned GENERATED ALWAYS AS (IFNULL(`condominium_id`, 0)) STORED,
  `kind` enum('entrada','saida') NOT NULL,
  `body` text NOT NULL COMMENT 'Texto puro com palavras-chave (@condominio, @unidade ...)',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_unit_move_terms_scope` (`scope_key`, `kind`),
  KEY `idx_erp_unit_move_terms_condominium` (`condominium_id`),
  CONSTRAINT `fk_erp_unit_move_terms_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_unit_move_terms_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_unit_move_terms_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
