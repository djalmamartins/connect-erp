-- Lançamento ligado à unidade que deve (conta a receber) e ao item de fatura que o gerou
-- (ADR 0033). A chave única em invoice_item_id é a última barreira contra emitir a mesma fatura
-- duas vezes. Idempotente (a suíte reaplica as migrations a cada execução).
ALTER TABLE `erp_entries`
  ADD COLUMN IF NOT EXISTS `unit_id` int unsigned DEFAULT NULL AFTER `supplier_id`,
  ADD COLUMN IF NOT EXISTS `invoice_item_id` int unsigned DEFAULT NULL AFTER `recurring_entry_id`,
  ADD INDEX IF NOT EXISTS `idx_erp_entries_unit` (`unit_id`),
  ADD UNIQUE INDEX IF NOT EXISTS `uq_erp_entries_invoice_item` (`invoice_item_id`),
  ADD CONSTRAINT `fk_erp_entries_unit` FOREIGN KEY IF NOT EXISTS (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_erp_entries_invoice_item` FOREIGN KEY IF NOT EXISTS (`invoice_item_id`) REFERENCES `erp_invoice_items` (`id`) ON DELETE RESTRICT;
