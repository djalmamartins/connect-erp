-- Balão do SAC com a equipe online (ADR 0629). Idempotente.
-- sac_seen_at: última atividade da pessoa nas telas de atendimento (ERP e SAC); online = nos últimos 5 minutos.
-- sac_public / sac_public_at: a própria pessoa autoriza mostrar a foto e o primeiro nome no balão do site (LGPD).
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `sac_seen_at` datetime DEFAULT NULL COMMENT 'Última atividade no atendimento (ADR 0629)',
    ADD COLUMN IF NOT EXISTS `sac_public` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Autorizou foto e primeiro nome no balão do SAC',
    ADD COLUMN IF NOT EXISTS `sac_public_at` datetime DEFAULT NULL COMMENT 'Quando autorizou ou retirou a autorização',
    ADD INDEX IF NOT EXISTS `idx_users_sac_seen` (`sac_public`, `sac_seen_at`);
