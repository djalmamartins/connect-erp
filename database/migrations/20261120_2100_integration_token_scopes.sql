-- Token de integração com escopos, validade e IPs, criado e revogado na tela Integrações (ADR 0601).
-- scopes: o que o token pode fazer na API /api/v1/talk. O token que já existe ganha o que o Talk usa hoje
-- (people.read, charges.read, tickets.write), pelo DEFAULT, para nada parar no Raspberry Pi.
-- expires_at: validade opcional. allowed_ips: lista opcional por token (além de TALK_ALLOWED_IPS).
-- last_ip: último IP que usou o token, para conferir na tela. Idempotente.
ALTER TABLE `erp_integration_tokens`
    ADD COLUMN IF NOT EXISTS `scopes` varchar(255) NOT NULL DEFAULT 'people.read,charges.read,tickets.write'
        COMMENT 'Escopos separados por vírgula (ADR 0601)' AFTER `token_hint`,
    ADD COLUMN IF NOT EXISTS `expires_at` datetime DEFAULT NULL COMMENT 'Validade opcional; vencido = recusado' AFTER `created_at`,
    ADD COLUMN IF NOT EXISTS `allowed_ips` varchar(255) DEFAULT NULL COMMENT 'IPs permitidos, separados por vírgula; vazio = qualquer' AFTER `expires_at`,
    ADD COLUMN IF NOT EXISTS `last_ip` varchar(45) DEFAULT NULL COMMENT 'Último IP que usou o token' AFTER `last_used_at`;
