-- Revisão de 15/11, etapa 2 (ADR 0311): configurações de cobrança da unidade (as opções do modelo 1, editáveis na ficha
-- e em massa na lista) e o Código CIB (Cadastro Imobiliário Brasileiro, LC 214/2025: 7 caracteres + dígito verificador).
-- Idempotente (a suíte reaplica as migrations a cada execução).
ALTER TABLE `erp_units`
  ADD COLUMN IF NOT EXISTS `cib` varchar(8) DEFAULT NULL COMMENT 'Código CIB, 7 caracteres + DV, sem traço' AFTER `number`,
  ADD COLUMN IF NOT EXISTS `due_day` tinyint unsigned DEFAULT NULL COMMENT 'Dia padrão de vencimento (1 a 31, mês curto = último dia)' AFTER `is_vacant`,
  ADD COLUMN IF NOT EXISTS `apportionment_exempt` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Isenta de rateio: fica fora das faturas rateadas' AFTER `due_day`,
  ADD COLUMN IF NOT EXISTS `postage_cents` int unsigned NOT NULL DEFAULT 0 COMMENT 'Porte postal por boleto, 0 = não cobra' AFTER `apportionment_exempt`,
  ADD COLUMN IF NOT EXISTS `postage_payer` enum('ambos','proprietario','inquilino') NOT NULL DEFAULT 'ambos' AFTER `postage_cents`,
  ADD COLUMN IF NOT EXISTS `reads_gas` tinyint(1) NOT NULL DEFAULT 0 AFTER `postage_payer`,
  ADD COLUMN IF NOT EXISTS `reads_water` tinyint(1) NOT NULL DEFAULT 0 AFTER `reads_gas`,
  ADD COLUMN IF NOT EXISTS `reads_power` tinyint(1) NOT NULL DEFAULT 0 AFTER `reads_water`,
  ADD COLUMN IF NOT EXISTS `block_bill_merge` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Fica fora do boleto único de várias unidades' AFTER `reads_power`,
  ADD COLUMN IF NOT EXISTS `billing_responsible` enum('nao_definido','proprietario','inquilino') NOT NULL DEFAULT 'nao_definido' AFTER `block_bill_merge`,
  ADD COLUMN IF NOT EXISTS `bill_address` enum('predio','correios') NOT NULL DEFAULT 'predio' COMMENT 'Endereço do boleto' AFTER `billing_responsible`,
  ADD COLUMN IF NOT EXISTS `bill_email_to` enum('responsavel','proprietario','inquilino','ambos') NOT NULL DEFAULT 'responsavel' AFTER `bill_address`,
  ADD COLUMN IF NOT EXISTS `partner_agency_id` int unsigned DEFAULT NULL COMMENT 'Imobiliária que administra a unidade' AFTER `bill_email_to`;
