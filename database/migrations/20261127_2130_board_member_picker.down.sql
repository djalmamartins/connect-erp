-- Reversão da ADR 0683: tira os vínculos do membro e a duração do mandato e apaga os síndicos profissionais.
ALTER TABLE `erp_board_members`
  DROP FOREIGN KEY IF EXISTS `fk_erp_board_members_resident`,
  DROP FOREIGN KEY IF EXISTS `fk_erp_board_members_professional`;
ALTER TABLE `erp_board_members`
  DROP KEY IF EXISTS `idx_erp_board_members_resident`,
  DROP KEY IF EXISTS `idx_erp_board_members_professional`,
  DROP COLUMN IF EXISTS `resident_id`,
  DROP COLUMN IF EXISTS `professional_manager_id`,
  DROP COLUMN IF EXISTS `term_years`;
DROP TABLE IF EXISTS `erp_professional_managers`;
