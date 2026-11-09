-- Liga o lançamento ao acordo (ADR 0039): agreement_role 'original' = conta renegociada (fica
-- cancelada); 'installment' = parcela do acordo, com o número em agreement_installment.
-- A chave única (agreement_id, agreement_installment) barra gerar a mesma parcela duas vezes.
-- Idempotente (a suíte reaplica as migrations a cada execução).
ALTER TABLE `erp_entries`
  ADD COLUMN IF NOT EXISTS `agreement_id` int unsigned DEFAULT NULL AFTER `invoice_item_id`,
  ADD COLUMN IF NOT EXISTS `agreement_role` enum('original','installment') DEFAULT NULL AFTER `agreement_id`,
  ADD COLUMN IF NOT EXISTS `agreement_installment` smallint unsigned DEFAULT NULL AFTER `agreement_role`,
  ADD INDEX IF NOT EXISTS `idx_erp_entries_agreement` (`agreement_id`, `agreement_role`),
  ADD UNIQUE INDEX IF NOT EXISTS `uq_erp_entries_agreement_installment` (`agreement_id`, `agreement_installment`),
  ADD CONSTRAINT `fk_erp_entries_agreement` FOREIGN KEY IF NOT EXISTS (`agreement_id`) REFERENCES `erp_agreements` (`id`) ON DELETE RESTRICT;
