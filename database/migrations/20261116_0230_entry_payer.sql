-- Revisão de 15/11, etapa 2 (ADR 0311): pagador escolhido da conta a receber. Vazio = o responsável da unidade na hora
-- do boleto (responsável financeiro, senão a regra "Responsável pela cobrança" da unidade). A troca de inquilino ou
-- proprietário (modelo 6) grava aqui quem paga as faturas abertas, conforme a opção escolhida. Idempotente.
ALTER TABLE `erp_entries`
  ADD COLUMN IF NOT EXISTS `payer_resident_id` int unsigned DEFAULT NULL COMMENT 'Vínculo (erp_residents) de quem paga, vazio = responsável da unidade' AFTER `unit_id`,
  ADD KEY IF NOT EXISTS `idx_erp_entries_payer` (`payer_resident_id`),
  ADD CONSTRAINT `fk_erp_entries_payer` FOREIGN KEY IF NOT EXISTS (`payer_resident_id`) REFERENCES `erp_residents` (`id`) ON DELETE SET NULL;
