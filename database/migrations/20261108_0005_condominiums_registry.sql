-- AP-004: cadastro completo de condomínios (ADR 0011).
-- Colunas novas são NULL no banco para preservar os registros existentes; a obrigatoriedade
-- é aplicada pelo serviço (Source\Services\CondominiumRegistry).
ALTER TABLE `condominiums`
  ADD COLUMN IF NOT EXISTS `legal_name` varchar(160) DEFAULT NULL COMMENT 'Razão social' AFTER `name`,
  ADD COLUMN IF NOT EXISTS `zip_code` char(8) DEFAULT NULL COMMENT 'CEP somente dígitos' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `street` varchar(160) DEFAULT NULL AFTER `zip_code`,
  ADD COLUMN IF NOT EXISTS `number` varchar(20) DEFAULT NULL AFTER `street`,
  ADD COLUMN IF NOT EXISTS `complement` varchar(80) DEFAULT NULL AFTER `number`,
  ADD COLUMN IF NOT EXISTS `district` varchar(100) DEFAULT NULL AFTER `complement`,
  ADD COLUMN IF NOT EXISTS `city` varchar(100) DEFAULT NULL AFTER `district`,
  ADD COLUMN IF NOT EXISTS `state` char(2) DEFAULT NULL COMMENT 'UF' AFTER `city`,
  ADD COLUMN IF NOT EXISTS `phone` varchar(20) DEFAULT NULL COMMENT 'Somente dígitos' AFTER `state`,
  ADD COLUMN IF NOT EXISTS `email` varchar(190) DEFAULT NULL AFTER `phone`,
  ADD COLUMN IF NOT EXISTS `blocks_count` smallint unsigned DEFAULT NULL AFTER `email`,
  ADD COLUMN IF NOT EXISTS `units_count` int unsigned DEFAULT NULL AFTER `blocks_count`,
  ADD COLUMN IF NOT EXISTS `manager_person_id` int unsigned DEFAULT NULL COMMENT 'Síndico; sem FK até existir Pessoas (migra para vínculos na Fase 2)' AFTER `units_count`,
  ADD COLUMN IF NOT EXISTS `contract_start_date` date DEFAULT NULL COMMENT 'Início do contrato de administração' AFTER `manager_person_id`,
  ADD COLUMN IF NOT EXISTS `fee_due_day` tinyint unsigned DEFAULT NULL COMMENT 'Dia de vencimento da taxa (1 a 28)' CHECK (`fee_due_day` BETWEEN 1 AND 28) AFTER `contract_start_date`,
  ADD COLUMN IF NOT EXISTS `bank_code` char(3) DEFAULT NULL COMMENT 'Provisório; migra para o núcleo financeiro' AFTER `fee_due_day`,
  ADD COLUMN IF NOT EXISTS `bank_branch` varchar(10) DEFAULT NULL AFTER `bank_code`,
  ADD COLUMN IF NOT EXISTS `bank_account` varchar(20) DEFAULT NULL AFTER `bank_branch`,
  ADD COLUMN IF NOT EXISTS `created_by` int unsigned DEFAULT NULL AFTER `bank_account`,
  ADD COLUMN IF NOT EXISTS `updated_by` int unsigned DEFAULT NULL AFTER `created_by`,
  ADD INDEX IF NOT EXISTS `idx_condominiums_manager_person` (`manager_person_id`);
