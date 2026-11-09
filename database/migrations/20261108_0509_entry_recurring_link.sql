-- Liga o lançamento gerado ao lançamento fixo de origem (ADR 0027). A chave única
-- (recurring_entry_id, competence) é a última barreira contra gerar o mesmo mês duas vezes.
-- Idempotente (a suíte reaplica as migrations a cada execução).
ALTER TABLE `erp_entries`
  ADD COLUMN IF NOT EXISTS `recurring_entry_id` int unsigned DEFAULT NULL AFTER `supplier_id`,
  ADD UNIQUE INDEX IF NOT EXISTS `uq_erp_entries_recurring_competence` (`recurring_entry_id`, `competence`),
  ADD CONSTRAINT `fk_erp_entries_recurring` FOREIGN KEY IF NOT EXISTS (`recurring_entry_id`) REFERENCES `erp_recurring_entries` (`id`) ON DELETE RESTRICT;
