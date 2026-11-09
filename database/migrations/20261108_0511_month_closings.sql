-- Fechamento mensal (AP-030, ADR 0031): uma linha por condomínio e competência. Competência
-- fechada trava lançamentos (criação, alteração e exclusão). Reabrir exige motivo; tudo auditado.
CREATE TABLE IF NOT EXISTS `erp_month_closings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `competence` char(7) NOT NULL COMMENT 'AAAA-MM',
  `status` enum('closed','reopened') NOT NULL,
  `revenue_cents` bigint unsigned NOT NULL DEFAULT 0 COMMENT 'Receitas válidas da competência no fechamento',
  `expense_cents` bigint unsigned NOT NULL DEFAULT 0,
  `open_revenue_cents` bigint unsigned NOT NULL DEFAULT 0 COMMENT 'Parte ainda não recebida',
  `open_expense_cents` bigint unsigned NOT NULL DEFAULT 0 COMMENT 'Parte ainda não paga',
  `summary_json` longtext DEFAULT NULL COMMENT 'Resumo por conta e por conta bancária no momento do fechamento',
  `closed_by` int unsigned DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `reopened_by` int unsigned DEFAULT NULL,
  `reopened_at` datetime DEFAULT NULL,
  `reopen_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_month_closings_competence` (`condominium_id`, `competence`),
  CONSTRAINT `fk_erp_month_closings_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_month_closings_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_month_closings_reopened_by` FOREIGN KEY (`reopened_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
