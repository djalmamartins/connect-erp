-- Reverte 20261120_2300_sac_widget (ADR 0631). O balão volta a não mostrar a equipe.
ALTER TABLE `users`
    DROP INDEX IF EXISTS `idx_users_sac_seen`,
    DROP COLUMN IF EXISTS `sac_public_at`,
    DROP COLUMN IF EXISTS `sac_public`,
    DROP COLUMN IF EXISTS `sac_seen_at`;
