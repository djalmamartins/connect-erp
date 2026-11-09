-- Reverte 20261108_0523. Acordos quebrados voltam a 'active' antes de o estado sair do enum.
UPDATE `erp_agreements` SET `status` = 'active' WHERE `status` = 'breached';
ALTER TABLE `erp_agreements`
  DROP FOREIGN KEY IF EXISTS `fk_erp_agreements_breached_by`,
  DROP COLUMN IF EXISTS `breach_reason`,
  DROP COLUMN IF EXISTS `breached_at`,
  DROP COLUMN IF EXISTS `breached_by`,
  MODIFY COLUMN `status` enum('active','cancelled') NOT NULL DEFAULT 'active';
