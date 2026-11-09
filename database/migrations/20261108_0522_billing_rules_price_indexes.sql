-- Regras de cobrança e índices de correção (ADR 0040). Padrões decididos pela Connect, que o Djalma
-- pode mudar (issue #46): multa de 2% (Código Civil, art. 1.336, § 1º), juros de 1% ao mês pro rata
-- die, correção pelo IPCA; acordo quebra com parcela mais de 30 dias atrasada ou 2 parcelas vencidas
-- em aberto. condominium_id NULL = padrão da Connect; um condomínio pode ter a própria regra.
-- Idempotente (a suíte reaplica as migrations a cada execução).
CREATE TABLE IF NOT EXISTS `erp_billing_rules` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned DEFAULT NULL,
  `scope_key` int unsigned GENERATED ALWAYS AS (COALESCE(`condominium_id`, 0)) STORED,
  `fine_bp` smallint unsigned NOT NULL DEFAULT 200 COMMENT 'Multa em pontos-base (200 = 2%)',
  `monthly_interest_bp` smallint unsigned NOT NULL DEFAULT 100 COMMENT 'Juros ao mês em pontos-base, pro rata die',
  `price_index` varchar(10) NOT NULL DEFAULT 'IPCA' COMMENT 'IPCA ou NONE (sem correção)',
  `breach_days_late` smallint unsigned NOT NULL DEFAULT 30,
  `breach_open_installments` smallint unsigned NOT NULL DEFAULT 2,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_billing_rules_scope` (`scope_key`),
  CONSTRAINT `fk_erp_billing_rules_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_erp_billing_rules_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `erp_billing_rules` (`condominium_id`, `fine_bp`, `monthly_interest_bp`, `price_index`, `breach_days_late`, `breach_open_installments`)
VALUES (NULL, 200, 100, 'IPCA', 30, 2);

-- Variação mensal do índice (em %, ex.: 0,440000), lançada à mão ou importada de arquivo. Sem
-- acesso à rede: o sistema não busca o índice sozinho. Mês sem valor = correção zero, sinalizada.
CREATE TABLE IF NOT EXISTS `erp_price_indexes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `competence` char(7) NOT NULL COMMENT 'AAAA-MM',
  `rate_percent` decimal(9,6) NOT NULL COMMENT 'Variação do mês em %, pode ser negativa',
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_price_indexes_month` (`code`, `competence`),
  CONSTRAINT `fk_erp_price_indexes_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
