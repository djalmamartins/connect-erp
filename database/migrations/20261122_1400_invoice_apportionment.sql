-- Formas de rateio da fatura (ADR 0640): percentual definido de cada unidade e o registro de como a fatura foi rateada.
ALTER TABLE `erp_units`
  ADD COLUMN IF NOT EXISTS `apportion_percent` decimal(7,4) DEFAULT NULL COMMENT 'Percentual definido na convenção para o rateio (0 a 100)' AFTER `ideal_fraction`;

ALTER TABLE `erp_invoices`
  ADD COLUMN IF NOT EXISTS `method` varchar(12) DEFAULT NULL COMMENT 'Forma de rateio: fixed, equal, fraction, percent, parking ou custom' AFTER `due_on`,
  ADD COLUMN IF NOT EXISTS `participants` varchar(10) DEFAULT NULL COMMENT 'Quem participa: all, blocks ou units' AFTER `method`,
  ADD COLUMN IF NOT EXISTS `participant_blocks` varchar(255) DEFAULT NULL COMMENT 'Blocos ou torres participantes, separados por vírgula' AFTER `participants`,
  ADD COLUMN IF NOT EXISTS `requested_cents` bigint unsigned DEFAULT NULL COMMENT 'Total pedido para ratear (vazio no valor fixo por unidade e nos valores informados)' AFTER `participant_blocks`;
