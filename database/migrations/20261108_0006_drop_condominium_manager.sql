-- Síndico não fica em condominiums: é o papel 'sindico' em erp_board_members (migration 0201).
-- Remove a coluna provisória criada pela 0005 (ADR 0011). Idempotente.
ALTER TABLE `condominiums`
  DROP INDEX IF EXISTS `idx_condominiums_manager_person`,
  DROP COLUMN IF EXISTS `manager_person_id`;
