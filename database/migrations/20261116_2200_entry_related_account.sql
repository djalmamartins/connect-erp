-- Nova Receita, modelo 8 (ADR 0329): "Conta relacionada", conta do plano de contas que a receita cobre
-- (ex.: "Rateio de obra" ligado à despesa de obras). Opcional. Idempotente (a suíte reaplica as migrations).
ALTER TABLE `erp_entries`
  ADD COLUMN IF NOT EXISTS `related_account_id` int unsigned DEFAULT NULL COMMENT 'Conta relacionada (ADR 0329)' AFTER `cost_center_id`,
  ADD KEY IF NOT EXISTS `idx_erp_entries_related_account` (`related_account_id`),
  ADD CONSTRAINT `fk_erp_entries_related_account` FOREIGN KEY IF NOT EXISTS (`related_account_id`) REFERENCES `erp_chart_accounts` (`id`) ON DELETE RESTRICT;
