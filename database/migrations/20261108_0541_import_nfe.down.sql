-- Reverte 20261108_0541: as contas a pagar lançadas continuam; saem o registro das NF-e e o tipo nfe.
DROP TABLE IF EXISTS `erp_nfe_documents`;
DELETE FROM `erp_imports` WHERE `kind` = 'nfe';
ALTER TABLE `erp_imports`
  DROP COLUMN IF EXISTS `options_json`,
  MODIFY `kind` enum('ofx','entries','unit_entries','payables','fractions','delinquents','readings') NOT NULL;
