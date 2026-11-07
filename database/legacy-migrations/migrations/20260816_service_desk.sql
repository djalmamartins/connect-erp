CREATE TABLE IF NOT EXISTS studio_calendar_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NULL,
    type ENUM('meeting','task','deadline','support') NOT NULL DEFAULT 'meeting',
    status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    assigned_to INT UNSIGNED NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_calendar_range (starts_at, ends_at),
    KEY idx_calendar_assigned (assigned_to),
    CONSTRAINT fk_calendar_assigned_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_calendar_created_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS studio_support_tickets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    protocol CHAR(12) NOT NULL,
    subject VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    area ENUM('general','technical','financial') NOT NULL DEFAULT 'general',
    priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    status ENUM('open','in_progress','waiting_customer','resolved','closed') NOT NULL DEFAULT 'open',
    requester_id INT UNSIGNED NULL,
    assigned_to INT UNSIGNED NULL,
    created_by INT UNSIGNED NOT NULL,
    due_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_support_ticket_protocol (protocol),
    KEY idx_support_ticket_status (status, priority, created_at),
    KEY idx_support_ticket_requester (requester_id),
    KEY idx_support_ticket_assignee (assigned_to),
    CONSTRAINT fk_ticket_requester_user FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_assigned_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_created_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE studio_support_tickets ADD COLUMN IF NOT EXISTS due_at DATETIME NOT NULL AFTER created_by;

CREATE TABLE IF NOT EXISTS studio_support_ticket_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    is_internal TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ticket_message (ticket_id, created_at),
    CONSTRAINT fk_ticket_message_ticket FOREIGN KEY (ticket_id) REFERENCES studio_support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_message_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
