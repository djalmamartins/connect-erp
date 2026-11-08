-- Áreas vinculáveis (vagas, depósitos, boxes) passam a apontar a unidade cadastrada
-- (unit_id → erp_units), como os cadastros da 20261108_0402 (ADR 0017, ADR 0018).
-- Numerada depois da 0401, que cria erp_units. Vaga livre continua sem unidade (NULL).
-- unit_label é mantido até a troca ser validada. Idempotente.
ALTER TABLE `erp_linkable_areas`
  ADD COLUMN IF NOT EXISTS `unit_id` int unsigned DEFAULT NULL AFTER `identifier`,
  ADD INDEX IF NOT EXISTS `idx_erp_linkable_areas_unit` (`unit_id`),
  ADD CONSTRAINT `fk_erp_linkable_areas_unit` FOREIGN KEY IF NOT EXISTS (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE RESTRICT;

-- Unidades a partir dos textos já gravados, sem duplicar as existentes.
INSERT INTO `erp_units` (`condominium_id`, `number`, `type`, `notes`)
SELECT s.condominium_id, s.label, 'outro', 'Criada a partir do texto da unidade (migration 20261108_0404). Revise bloco, número e tipo.'
FROM (
  SELECT DISTINCT condominium_id, TRIM(unit_label) AS label FROM `erp_linkable_areas`
  WHERE deleted_at IS NULL AND TRIM(COALESCE(unit_label, '')) <> ''
) s
WHERE NOT EXISTS (
  SELECT 1 FROM `erp_units` u WHERE u.condominium_id = s.condominium_id AND u.label = s.label AND u.deleted_at IS NULL
);

-- Liga cada área à unidade de mesmo texto no mesmo condomínio (a mais antiga, se houver duas).
UPDATE `erp_linkable_areas` t SET t.unit_id = (SELECT MIN(u.id) FROM `erp_units` u WHERE u.condominium_id = t.condominium_id AND u.label = TRIM(t.unit_label) AND u.deleted_at IS NULL)
  WHERE t.unit_id IS NULL AND TRIM(COALESCE(t.unit_label, '')) <> '';
