-- Reverte 20261127_2200: as caixinhas viram aplicações comuns, com o mesmo banco, agência, conta e vínculo.
ALTER TABLE `erp_bank_accounts`
  DROP COLUMN IF EXISTS `is_pocket`;
