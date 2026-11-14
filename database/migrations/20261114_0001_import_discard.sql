-- Prévias de importação descartadas (ADR 0285): uma prévia parada (o arquivo foi conferido e esquecido) pode ser
-- descartada em lote. A situação ganha discarded, e a linha guarda quem descartou, quando e o motivo. O arquivo fica
-- guardado em storage/private/imports; nada é apagado nem aplicado. Uma prévia descartada não pode ser aplicada; enviar
-- o mesmo arquivo de novo a volta para prévia (Imports::preview). Idempotente (a suíte reaplica as migrations).
ALTER TABLE `erp_imports`
  MODIFY `status` enum('previewed','applied','discarded') NOT NULL DEFAULT 'previewed',
  ADD COLUMN IF NOT EXISTS `discarded_by` int unsigned DEFAULT NULL COMMENT 'ADR 0285' AFTER `applied_at`,
  ADD COLUMN IF NOT EXISTS `discarded_at` datetime DEFAULT NULL COMMENT 'ADR 0285' AFTER `discarded_by`,
  ADD COLUMN IF NOT EXISTS `discard_reason` varchar(255) DEFAULT NULL COMMENT 'ADR 0285' AFTER `discarded_at`;

ALTER TABLE `erp_imports`
  ADD CONSTRAINT `fk_erp_imports_discarded_by` FOREIGN KEY IF NOT EXISTS (`discarded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
