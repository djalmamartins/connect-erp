-- Reverte 20261108_0401. Rode antes o .down da 0402 (as FKs unit_id apontam para esta tabela).
DROP TABLE IF EXISTS `erp_units`;
