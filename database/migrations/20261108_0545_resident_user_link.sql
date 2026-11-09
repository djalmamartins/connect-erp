-- Aceite do convite do morador (ADR 0092): liga o cadastro do morador à conta criada no aceite. Uma conta
-- por morador e um morador por conta. Idempotente (a suíte reaplica as migrations a cada execução).
ALTER TABLE `erp_residents`
  ADD COLUMN IF NOT EXISTS `user_id` int unsigned DEFAULT NULL COMMENT 'Conta criada no aceite do convite (ADR 0092)' AFTER `email`,
  ADD UNIQUE INDEX IF NOT EXISTS `uq_erp_residents_user` (`user_id`),
  ADD CONSTRAINT `fk_erp_residents_user` FOREIGN KEY IF NOT EXISTS (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
