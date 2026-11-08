-- Troca gradual de unit_label (texto) por unit_id (FK para erp_units), ADR 0040.
-- 1) cria unit_id nullable nas cinco tabelas; 2) cria as unidades a partir dos textos já
-- gravados (sem bloco, número = texto); 3) liga cada registro à unidade de mesmo texto.
-- unit_label é mantido até a troca ser validada. Idempotente (a suíte reaplica a cada execução).

ALTER TABLE `erp_residents`
  ADD COLUMN IF NOT EXISTS `unit_id` int unsigned DEFAULT NULL AFTER `condominium_id`,
  ADD INDEX IF NOT EXISTS `idx_erp_residents_unit` (`unit_id`),
  ADD CONSTRAINT `fk_erp_residents_unit` FOREIGN KEY IF NOT EXISTS (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE RESTRICT;
ALTER TABLE `erp_board_members`
  ADD COLUMN IF NOT EXISTS `unit_id` int unsigned DEFAULT NULL AFTER `condominium_id`,
  ADD INDEX IF NOT EXISTS `idx_erp_board_members_unit` (`unit_id`),
  ADD CONSTRAINT `fk_erp_board_members_unit` FOREIGN KEY IF NOT EXISTS (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE RESTRICT;
ALTER TABLE `erp_vehicles`
  ADD COLUMN IF NOT EXISTS `unit_id` int unsigned DEFAULT NULL AFTER `condominium_id`,
  ADD INDEX IF NOT EXISTS `idx_erp_vehicles_unit` (`unit_id`),
  ADD CONSTRAINT `fk_erp_vehicles_unit` FOREIGN KEY IF NOT EXISTS (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE RESTRICT;
ALTER TABLE `erp_visitors`
  ADD COLUMN IF NOT EXISTS `unit_id` int unsigned DEFAULT NULL AFTER `condominium_id`,
  ADD INDEX IF NOT EXISTS `idx_erp_visitors_unit` (`unit_id`),
  ADD CONSTRAINT `fk_erp_visitors_unit` FOREIGN KEY IF NOT EXISTS (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE RESTRICT;
ALTER TABLE `erp_pets`
  ADD COLUMN IF NOT EXISTS `unit_id` int unsigned DEFAULT NULL AFTER `condominium_id`,
  ADD INDEX IF NOT EXISTS `idx_erp_pets_unit` (`unit_id`),
  ADD CONSTRAINT `fk_erp_pets_unit` FOREIGN KEY IF NOT EXISTS (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE RESTRICT;

-- Unidades a partir dos textos existentes (registros não excluídos). Não duplica unidade já existente.
INSERT INTO `erp_units` (`condominium_id`, `number`, `type`, `notes`)
SELECT s.condominium_id, s.label, 'outro', 'Criada a partir do texto da unidade (migration 20261108_0402). Revise bloco, número e tipo.'
FROM (
  SELECT condominium_id, TRIM(unit_label) AS label FROM `erp_residents` WHERE deleted_at IS NULL AND TRIM(COALESCE(unit_label, '')) <> ''
  UNION SELECT condominium_id, TRIM(unit_label) FROM `erp_board_members` WHERE deleted_at IS NULL AND TRIM(COALESCE(unit_label, '')) <> ''
  UNION SELECT condominium_id, TRIM(unit_label) FROM `erp_vehicles` WHERE deleted_at IS NULL AND TRIM(COALESCE(unit_label, '')) <> ''
  UNION SELECT condominium_id, TRIM(unit_label) FROM `erp_visitors` WHERE deleted_at IS NULL AND TRIM(COALESCE(unit_label, '')) <> ''
  UNION SELECT condominium_id, TRIM(unit_label) FROM `erp_pets` WHERE deleted_at IS NULL AND TRIM(COALESCE(unit_label, '')) <> ''
) s
WHERE NOT EXISTS (
  SELECT 1 FROM `erp_units` u WHERE u.condominium_id = s.condominium_id AND u.label = s.label AND u.deleted_at IS NULL
);

-- Liga cada registro à unidade de mesmo texto no mesmo condomínio (a mais antiga, se houver duas).
UPDATE `erp_residents` t SET t.unit_id = (SELECT MIN(u.id) FROM `erp_units` u WHERE u.condominium_id = t.condominium_id AND u.label = TRIM(t.unit_label) AND u.deleted_at IS NULL)
  WHERE t.unit_id IS NULL AND TRIM(COALESCE(t.unit_label, '')) <> '';
UPDATE `erp_board_members` t SET t.unit_id = (SELECT MIN(u.id) FROM `erp_units` u WHERE u.condominium_id = t.condominium_id AND u.label = TRIM(t.unit_label) AND u.deleted_at IS NULL)
  WHERE t.unit_id IS NULL AND TRIM(COALESCE(t.unit_label, '')) <> '';
UPDATE `erp_vehicles` t SET t.unit_id = (SELECT MIN(u.id) FROM `erp_units` u WHERE u.condominium_id = t.condominium_id AND u.label = TRIM(t.unit_label) AND u.deleted_at IS NULL)
  WHERE t.unit_id IS NULL AND TRIM(COALESCE(t.unit_label, '')) <> '';
UPDATE `erp_visitors` t SET t.unit_id = (SELECT MIN(u.id) FROM `erp_units` u WHERE u.condominium_id = t.condominium_id AND u.label = TRIM(t.unit_label) AND u.deleted_at IS NULL)
  WHERE t.unit_id IS NULL AND TRIM(COALESCE(t.unit_label, '')) <> '';
UPDATE `erp_pets` t SET t.unit_id = (SELECT MIN(u.id) FROM `erp_units` u WHERE u.condominium_id = t.condominium_id AND u.label = TRIM(t.unit_label) AND u.deleted_at IS NULL)
  WHERE t.unit_id IS NULL AND TRIM(COALESCE(t.unit_label, '')) <> '';
