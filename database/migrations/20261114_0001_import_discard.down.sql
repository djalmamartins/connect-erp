-- Reverte 20261114_0001: as prévias descartadas voltam a ser prévias (ninguém as aplicou), e saem as colunas do
-- descarte. Nenhum arquivo é tocado.
UPDATE `erp_imports` SET `status` = 'previewed' WHERE `status` = 'discarded';

ALTER TABLE `erp_imports`
  DROP FOREIGN KEY IF EXISTS `fk_erp_imports_discarded_by`;

ALTER TABLE `erp_imports`
  DROP COLUMN IF EXISTS `discard_reason`,
  DROP COLUMN IF EXISTS `discarded_at`,
  DROP COLUMN IF EXISTS `discarded_by`,
  MODIFY `status` enum('previewed','applied') NOT NULL DEFAULT 'previewed';
