-- Banco Inter como primeiro adaptador bancário real (ADR 0156), só sandbox.
-- 1) Cada conta bancária diz qual adaptador usa ('sandbox' até a credencial ser cadastrada).
-- 2) Boleto guarda o nosso número do banco e o Pix do boleto híbrido.
-- 3) Credenciais no cofre: client_id, client_secret, certificado e chave mTLS ficam num único campo
--    cifrado (AES-256-GCM, chave CONNECT_BANK_VAULT_KEY fora do Git). O banco só vê metadados.
-- 4) Entregas do webhook: corpo cifrado, sha256 para não processar a mesma entrega duas vezes.
-- Idempotente (a suíte reaplica as migrations a cada execução).
ALTER TABLE `erp_bank_accounts`
  ADD COLUMN IF NOT EXISTS `adapter` varchar(30) NOT NULL DEFAULT 'sandbox' COMMENT 'Adaptador bancário (sandbox, inter)' AFTER `wallet`;

ALTER TABLE `erp_bank_charges`
  ADD COLUMN IF NOT EXISTS `bank_our_number` varchar(20) DEFAULT NULL COMMENT 'Nosso número atribuído pelo banco' AFTER `external_id`,
  ADD COLUMN IF NOT EXISTS `pix_txid` varchar(64) DEFAULT NULL AFTER `barcode`,
  ADD COLUMN IF NOT EXISTS `pix_copy_paste` text DEFAULT NULL COMMENT 'Pix copia e cola do boleto híbrido' AFTER `pix_txid`,
  ADD INDEX IF NOT EXISTS `idx_erp_bank_charges_external` (`bank_account_id`, `external_id`);

CREATE TABLE IF NOT EXISTS `erp_bank_credentials` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `bank_account_id` int unsigned NOT NULL,
  `adapter` varchar(30) NOT NULL,
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox',
  `secrets` blob NOT NULL COMMENT 'JSON cifrado no cofre (BankVault); nunca em texto aberto',
  `client_id_hint` varchar(12) DEFAULT NULL COMMENT 'Só os 4 últimos caracteres, para conferência',
  `certificate_subject` varchar(160) DEFAULT NULL,
  `certificate_expires_on` date DEFAULT NULL,
  `webhook_token_hash` char(64) DEFAULT NULL COMMENT 'sha256 do segredo do endereço do webhook',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_bank_credentials_account` (`bank_account_id`),
  UNIQUE KEY `uq_erp_bank_credentials_webhook` (`webhook_token_hash`),
  CONSTRAINT `fk_erp_bank_credentials_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_credentials_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_erp_bank_credentials_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `erp_bank_webhook_deliveries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `condominium_id` int unsigned NOT NULL,
  `bank_account_id` int unsigned NOT NULL,
  `adapter` varchar(30) NOT NULL,
  `sha256` char(64) NOT NULL,
  `payload` blob NOT NULL COMMENT 'Corpo recebido, cifrado no cofre',
  `items_count` int unsigned NOT NULL DEFAULT 0,
  `applied_count` int unsigned NOT NULL DEFAULT 0,
  `status` enum('received','processed','failed') NOT NULL DEFAULT 'received',
  `error` varchar(255) DEFAULT NULL,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_erp_bank_webhook_deliveries_hash` (`bank_account_id`, `sha256`),
  KEY `idx_erp_bank_webhook_deliveries_status` (`status`, `received_at`),
  CONSTRAINT `fk_erp_bank_webhook_deliveries_condominium` FOREIGN KEY (`condominium_id`) REFERENCES `condominiums` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_erp_bank_webhook_deliveries_account` FOREIGN KEY (`bank_account_id`) REFERENCES `erp_bank_accounts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
