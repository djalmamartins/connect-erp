ALTER TABLE studio_support_tickets
    DROP FOREIGN KEY IF EXISTS fk_support_ticket_condominium,
    DROP FOREIGN KEY IF EXISTS fk_support_ticket_unit;
ALTER TABLE studio_support_tickets
    DROP INDEX IF EXISTS idx_support_ticket_condominium,
    DROP INDEX IF EXISTS idx_support_ticket_unit,
    DROP INDEX IF EXISTS idx_support_ticket_origin,
    DROP COLUMN IF EXISTS requester_phone,
    DROP COLUMN IF EXISTS unit_id,
    DROP COLUMN IF EXISTS condominium_id,
    DROP COLUMN IF EXISTS origin;
