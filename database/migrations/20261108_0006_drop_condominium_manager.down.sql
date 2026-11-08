-- Reverte 20261108_0006: recria a coluna nullable e o índice (sem os valores anteriores).
ALTER TABLE `condominiums`
  ADD COLUMN IF NOT EXISTS `manager_person_id` int unsigned DEFAULT NULL COMMENT 'Síndico; sem FK até existir Pessoas (migra para vínculos na Fase 2)' AFTER `units_count`,
  ADD INDEX IF NOT EXISTS `idx_condominiums_manager_person` (`manager_person_id`);
