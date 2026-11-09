-- Reverte 20261108_0519. Rode antes o .down da 0520 (erp_entries.agreement_id aponta para esta tabela).
DROP TABLE IF EXISTS `erp_agreement_originals`;
DROP TABLE IF EXISTS `erp_agreements`;
