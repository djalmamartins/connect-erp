-- Continuações do Operacional (ADR 0053): chamada da lista de espera (quem foi chamado e quando,
-- sem reservar sozinho) e a próxima manutenção gerada pela periodicidade (previous_id único: uma
-- manutenção gera no máximo uma próxima). Idempotente (a suíte reaplica as migrations).
ALTER TABLE `erp_waitlist`
  ADD COLUMN IF NOT EXISTS `called_at` datetime DEFAULT NULL AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `called_by` int unsigned DEFAULT NULL AFTER `called_at`,
  ADD COLUMN IF NOT EXISTS `called_for_reservation_id` int unsigned DEFAULT NULL AFTER `called_by`,
  ADD CONSTRAINT `fk_erp_waitlist_called_by` FOREIGN KEY IF NOT EXISTS (`called_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_erp_waitlist_called_for` FOREIGN KEY IF NOT EXISTS (`called_for_reservation_id`) REFERENCES `erp_reservations` (`id`) ON DELETE RESTRICT;

ALTER TABLE `erp_maintenances`
  ADD COLUMN IF NOT EXISTS `previous_id` int unsigned DEFAULT NULL AFTER `document_id`,
  ADD UNIQUE INDEX IF NOT EXISTS `uq_erp_maintenances_previous` (`previous_id`),
  ADD CONSTRAINT `fk_erp_maintenances_previous` FOREIGN KEY IF NOT EXISTS (`previous_id`) REFERENCES `erp_maintenances` (`id`) ON DELETE RESTRICT;
