-- Reverte 20261108_0508. Rode antes o .down da 0509 (erp_entries.recurring_entry_id aponta para esta tabela).
DROP TABLE IF EXISTS `erp_recurring_entries`;
