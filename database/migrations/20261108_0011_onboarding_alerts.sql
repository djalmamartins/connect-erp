-- Avisos de vencimento do checklist de chegada (ADR 0013): guarda a última situação
-- avisada para não repetir o aviso todo dia. Zerado a cada atualização do item.
ALTER TABLE `condominium_onboarding_items`
  ADD COLUMN IF NOT EXISTS `alerted_situation` varchar(20) DEFAULT NULL AFTER `resolved_at`,
  ADD COLUMN IF NOT EXISTS `alerted_at` datetime DEFAULT NULL AFTER `alerted_situation`;
