-- Reverte 20261108_0535. Os lançamentos importados continuam, só perdem o vínculo com a importação;
-- as linhas do extrato e o registro das importações saem (os arquivos em storage/private/imports
-- ficam e podem ser removidos à mão).
ALTER TABLE `erp_entries`
  DROP FOREIGN KEY IF EXISTS `fk_erp_entries_import`,
  DROP INDEX IF EXISTS `idx_erp_entries_import`,
  DROP COLUMN IF EXISTS `import_id`;
DROP TABLE IF EXISTS `erp_bank_statement_lines`;
DROP TABLE IF EXISTS `erp_imports`;
DELETE FROM `access_permissions` WHERE `slug` IN ('erp.imports.view','erp.imports.manage');
