ALTER TABLE studio_support_tickets
    MODIFY created_by INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS requester_name VARCHAR(160) NULL AFTER requester_id,
    ADD COLUMN IF NOT EXISTS requester_email VARCHAR(190) NULL AFTER requester_name,
    ADD COLUMN IF NOT EXISTS access_token CHAR(64) NULL AFTER requester_email,
    ADD UNIQUE KEY IF NOT EXISTS uq_support_ticket_access_token (access_token);

ALTER TABLE studio_support_ticket_attachments
    MODIFY user_id INT UNSIGNED NULL;
