CREATE TABLE IF NOT EXISTS system_protected_users (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    reason VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_protected_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO system_protected_users (user_id, reason)
SELECT id, 'Primary system administrator' FROM users WHERE id = 1;
