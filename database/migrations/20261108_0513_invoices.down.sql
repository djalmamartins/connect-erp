-- Reverte 20261108_0513. Rode antes o .down da 0514 (a FK invoice_item_id aponta para os itens).
DROP TABLE IF EXISTS `erp_invoice_items`;
DROP TABLE IF EXISTS `erp_invoices`;
