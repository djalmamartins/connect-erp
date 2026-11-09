-- Quebra de acordo (ADR 0040): estado 'breached' gravado só por ação explícita e auditada, com
-- motivo. As parcelas não mudam sozinhas. Idempotente (a suíte reaplica as migrations).
ALTER TABLE `erp_agreements`
  MODIFY COLUMN `status` enum('active','cancelled','breached') NOT NULL DEFAULT 'active',
  ADD COLUMN IF NOT EXISTS `breached_by` int unsigned DEFAULT NULL AFTER `cancel_reason`,
  ADD COLUMN IF NOT EXISTS `breached_at` datetime DEFAULT NULL AFTER `breached_by`,
  ADD COLUMN IF NOT EXISTS `breach_reason` varchar(255) DEFAULT NULL AFTER `breached_at`,
  ADD CONSTRAINT `fk_erp_agreements_breached_by` FOREIGN KEY IF NOT EXISTS (`breached_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
