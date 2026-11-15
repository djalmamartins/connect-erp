-- Reverte 20261115_1100 (ADR 0308). Chamados que vieram do Talk ficam com a origem "api" e sem external_ref
-- (o chamado em si não é apagado). Os tokens de integração deixam de existir.
DROP TABLE IF EXISTS `erp_integration_tokens`;
UPDATE studio_support_tickets SET origin = 'api' WHERE origin = 'talk';
ALTER TABLE studio_support_tickets DROP INDEX IF EXISTS uq_support_ticket_external, DROP COLUMN IF EXISTS external_ref;
ALTER TABLE studio_support_tickets
    MODIFY COLUMN origin ENUM('helpdesk','erp','site','residents','api') NOT NULL DEFAULT 'helpdesk';
