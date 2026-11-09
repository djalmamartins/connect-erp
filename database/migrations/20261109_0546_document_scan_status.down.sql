-- Reverte 20261109_0546: tira o resultado do antivírus dos arquivos do Disco Virtual. Os arquivos continuam.
ALTER TABLE `erp_documents` DROP COLUMN IF EXISTS `scanned_at`;
ALTER TABLE `erp_documents` DROP COLUMN IF EXISTS `scan_status`;
