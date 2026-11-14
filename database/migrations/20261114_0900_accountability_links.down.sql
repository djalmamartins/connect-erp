-- Reverte 20261114_0900: remove os links públicos e os avisos do prazo. Anexos do tipo "conta" passam a "outro"
-- (o arquivo continua no Disco Virtual) antes de tirar o tipo da lista.
DROP TABLE IF EXISTS `erp_accountability_links`;
DROP TABLE IF EXISTS `erp_accountability_alerts`;
UPDATE `erp_entry_attachments` SET `kind` = 'outro' WHERE `kind` = 'conta';
ALTER TABLE `erp_entry_attachments`
  MODIFY COLUMN `kind` enum('nota_fiscal','comprovante','recibo','outro') NOT NULL;
