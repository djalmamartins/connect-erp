-- Reverte 20261111_0711: remove os vínculos dos anexos e devolve a pasta "Comprovantes" para "Outros".
-- Os arquivos continuam no Disco Virtual (erp_documents e storage/private/documents).
DROP TABLE IF EXISTS `erp_entry_attachments`;
UPDATE `erp_documents` SET `folder` = 'outros' WHERE `folder` = 'comprovantes';
ALTER TABLE `erp_documents`
  MODIFY COLUMN `folder` enum('atas','contratos','plantas','notas_fiscais','outros') NOT NULL;
