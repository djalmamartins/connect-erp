-- LGPD (ADR 0603): identificação da Connect no site, encarregado e registro dos aceites e consentimentos.
-- Idempotente (a suíte reaplica as migrations).

-- 1) Razão social, CNPJ e encarregado nas Configurações do Studio (rodapé do site e política de privacidade).
--    TEXT porque a linha de settings (utf8mb3, dezenas de varchar(255)) já está perto do limite de 65.535 bytes.
ALTER TABLE `settings`
  ADD COLUMN IF NOT EXISTS `site_company_name` text DEFAULT NULL COMMENT 'Razão social (ADR 0603)' AFTER `site_name`,
  ADD COLUMN IF NOT EXISTS `site_cnpj` varchar(18) DEFAULT NULL COMMENT 'CNPJ da Connect (ADR 0603)' AFTER `site_company_name`,
  ADD COLUMN IF NOT EXISTS `privacy_officer_name` text DEFAULT NULL COMMENT 'Encarregado de dados, LGPD art. 41 (ADR 0603)' AFTER `site_cnpj`,
  ADD COLUMN IF NOT EXISTS `privacy_email` text DEFAULT NULL COMMENT 'Canal do titular (ADR 0603)' AFTER `privacy_officer_name`;

-- 2) Proposta do site: ciência da política só quando a pessoa marca; marketing separado e opcional.
ALTER TABLE `proposals`
  ADD COLUMN IF NOT EXISTS `marketing_consent_at` datetime DEFAULT NULL COMMENT 'Aceitou receber novidades (ADR 0603)' AFTER `consent_at`;

-- 3) Prova dos aceites e consentimentos (LGPD art. 8º, § 2º: o ônus da prova é do controlador).
--    Só acrescenta linhas: retirar o consentimento grava uma linha nova com granted = 0.
CREATE TABLE IF NOT EXISTS `privacy_consents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `purpose` enum('cookies','terms','privacy','marketing') NOT NULL COMMENT 'cookies = escolha do banner; terms/privacy = aceite do documento; marketing = novidades',
  `granted` tinyint(1) NOT NULL,
  `version` varchar(20) NOT NULL COMMENT 'Versão do documento ou do banner aceita',
  `detail` varchar(255) DEFAULT NULL COMMENT 'Ex.: categorias de cookies escolhidas',
  `channel` varchar(30) NOT NULL COMMENT 'site, proposta, convite, erp, app, celular',
  `user_id` int unsigned DEFAULT NULL,
  `proposal_id` int unsigned DEFAULT NULL,
  `email` varchar(180) DEFAULT NULL COMMENT 'Quando não há conta (proposta)',
  `request_hash` char(64) DEFAULT NULL COMMENT 'sha256 do IP e do navegador, nunca o IP puro',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_privacy_consents_user` (`user_id`, `purpose`, `created_at`),
  KEY `idx_privacy_consents_proposal` (`proposal_id`),
  KEY `idx_privacy_consents_email` (`email`),
  KEY `idx_privacy_consents_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
