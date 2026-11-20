-- Reverte 20261120_1900 (ADR 0600). Apaga a prova dos aceites: só rode com cópia do banco.
DROP TABLE IF EXISTS `privacy_consents`;
ALTER TABLE `proposals` DROP COLUMN IF EXISTS `marketing_consent_at`;
ALTER TABLE `settings`
  DROP COLUMN IF EXISTS `privacy_email`,
  DROP COLUMN IF EXISTS `privacy_officer_name`,
  DROP COLUMN IF EXISTS `site_cnpj`,
  DROP COLUMN IF EXISTS `site_company_name`;
