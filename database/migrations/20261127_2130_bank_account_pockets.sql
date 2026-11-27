-- Caixinha (ADR 0685): aplicação no mesmo banco da conta corrente, como os bancos fazem hoje. A caixinha usa
-- o banco, a agência e a conta da conta corrente ligada (parent_bank_account_id), copiados pelo cadastro, e
-- por isso não entra na regra "esta conta já está cadastrada". Só acrescenta a coluna; idempotente.
ALTER TABLE `erp_bank_accounts`
  ADD COLUMN IF NOT EXISTS `is_pocket` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Caixinha: aplicação no mesmo banco da conta corrente (ADR 0685)' AFTER `parent_bank_account_id`;
