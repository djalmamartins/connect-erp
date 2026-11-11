-- Publicação da prestação de contas mensal (ADR 0179): o pacote do mês (AP-101) congelado como foi entregue,
-- com o sha256 do JSON e a versão. Uma versão atual por condomínio e mês; publicar de novo um conteúdo diferente
-- cria a versão seguinte e a anterior fica "superseded". Retirar só muda o status, com motivo; nada é apagado.
-- Idempotente.
CREATE TABLE IF NOT EXISTS `erp_accountability_publications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `competence` char(7) NOT NULL COMMENT 'AAAA-MM',
  `version` smallint unsigned NOT NULL,
  `sha256` char(64) NOT NULL COMMENT 'sha256 de payload_json',
  `payload_json` mediumtext NOT NULL COMMENT 'Pacote da prestação de contas em JSON canônico',
  `status` enum('current','superseded','withdrawn') NOT NULL DEFAULT 'current',
  `notes` varchar(255) DEFAULT NULL,
  `published_by` int unsigned DEFAULT NULL,
  `published_at` datetime NOT NULL,
  `withdrawn_by` int unsigned DEFAULT NULL,
  `withdrawn_at` datetime DEFAULT NULL,
  `withdraw_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_accountability_publications_version` (`condominium_id`, `competence`, `version`),
  KEY `idx_erp_accountability_publications_status` (`condominium_id`, `competence`, `status`),
  CONSTRAINT `fk_erp_accountability_publications_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_accountability_publications_published_by` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_accountability_publications_withdrawn_by` FOREIGN KEY (`withdrawn_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
