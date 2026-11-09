-- Antivírus no upload do Disco Virtual (ADR 0098): resultado da verificação de cada arquivo.
-- clean = verificado e limpo; unscanned = modo off ou envio anterior a esta migration; infected e error ficam
-- reservados (no modo enforce o arquivo recusado não é gravado). Idempotente (a suíte reaplica as migrations).
ALTER TABLE `erp_documents`
  ADD COLUMN IF NOT EXISTS `scan_status` enum('clean','infected','unscanned','error') NOT NULL DEFAULT 'unscanned' COMMENT 'Antivírus (ADR 0098)' AFTER `sha256`,
  ADD COLUMN IF NOT EXISTS `scanned_at` datetime DEFAULT NULL COMMENT 'Quando o antivírus respondeu' AFTER `scan_status`;
