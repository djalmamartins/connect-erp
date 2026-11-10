-- Reverte 20261109_0708: remove o vínculo com a importação e a tabela das importações patrimoniais. Os
-- lançamentos patrimoniais já gravados continuam; só deixam de apontar para a importação de origem.
ALTER TABLE `erp_patrimonial_entries`
  DROP INDEX IF EXISTS `idx_erp_patrimonial_entries_import`,
  DROP COLUMN IF EXISTS `import_id`;
DROP TABLE IF EXISTS `erp_patrimonial_imports`;
