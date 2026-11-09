-- Reverte 20261108_0534. Antes, cancele e remova os recibos, as cobranças extras, as multas e as
-- transferências (as contas a receber geradas perdem o vínculo com a origem; os descontos já
-- aplicados ficam no valor do lançamento, sem a trilha).
DROP TABLE IF EXISTS `erp_bank_transfers`;
ALTER TABLE `erp_entries`
  DROP FOREIGN KEY IF EXISTS `fk_erp_entries_extra_charge`,
  DROP INDEX IF EXISTS `idx_erp_entries_extra_charge`,
  DROP COLUMN IF EXISTS `extra_charge_id`;
DROP TABLE IF EXISTS `erp_extra_charges`;
DROP TABLE IF EXISTS `erp_entry_adjustments`;
DROP TABLE IF EXISTS `erp_unit_document_entries`;
DELETE FROM `erp_unit_documents` WHERE `kind` = 'receipt';
ALTER TABLE `erp_unit_documents`
  MODIFY `kind` enum('debt_certificate','delinquency_declaration','quittance','consent_letter') NOT NULL;
