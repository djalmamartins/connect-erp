-- Reverte 20261120_2100_integration_token_scopes (ADR 0623). Os tokens continuam valendo, sem escopo nem validade.
ALTER TABLE `erp_integration_tokens`
    DROP COLUMN IF EXISTS `last_ip`,
    DROP COLUMN IF EXISTS `allowed_ips`,
    DROP COLUMN IF EXISTS `expires_at`,
    DROP COLUMN IF EXISTS `scopes`;
