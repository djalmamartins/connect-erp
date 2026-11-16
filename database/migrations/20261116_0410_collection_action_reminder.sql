-- Revisão de 15/11, etapa 6 (ADR 0315): lembrete da próxima ação do atendimento de cobrança.
-- reminded_at marca o contato cujo lembrete já foi para o sino de quem atendeu (ou da equipe), uma vez só.
ALTER TABLE `erp_collection_contacts`
  ADD COLUMN IF NOT EXISTS `reminded_at` datetime NULL DEFAULT NULL COMMENT 'Quando o lembrete da próxima ação foi dado (ADR 0315)' AFTER `next_action_on`,
  ADD KEY IF NOT EXISTS `idx_erp_collection_contacts_next_action` (`next_action_on`, `reminded_at`);
