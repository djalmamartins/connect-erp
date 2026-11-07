ALTER TABLE studio_support_tickets
    ADD COLUMN IF NOT EXISTS team VARCHAR(100) NULL AFTER assigned_to,
    ADD COLUMN IF NOT EXISTS category VARCHAR(100) NULL AFTER area,
    ADD COLUMN IF NOT EXISTS tags VARCHAR(500) NULL AFTER category,
    ADD COLUMN IF NOT EXISTS first_response_at DATETIME NULL AFTER due_at,
    ADD COLUMN IF NOT EXISTS work_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER first_response_at;

CREATE TABLE IF NOT EXISTS studio_support_ticket_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    event_type VARCHAR(40) NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ticket_event (ticket_id, created_at),
    CONSTRAINT fk_ticket_event_ticket FOREIGN KEY (ticket_id) REFERENCES studio_support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS studio_support_ticket_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    message_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ticket_attachment (ticket_id, created_at),
    CONSTRAINT fk_ticket_attachment_ticket FOREIGN KEY (ticket_id) REFERENCES studio_support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_attachment_message FOREIGN KEY (message_id) REFERENCES studio_support_ticket_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_attachment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS studio_support_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    body TEXT NOT NULL,
    category VARCHAR(100) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_support_template_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
