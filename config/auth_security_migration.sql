-- Authentication security additions for the existing users table.
-- These changes preserve all current accounts and task records.

ALTER TABLE users
    ADD COLUMN failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_enabled,
    ADD COLUMN lock_until DATETIME NULL DEFAULT NULL AFTER failed_login_attempts,
    ADD COLUMN profile_image VARCHAR(255) NULL DEFAULT NULL AFTER lock_until;

-- Keeps an audit trail without storing passwords or session values.
CREATE TABLE IF NOT EXISTS login_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    user_id INT NULL DEFAULT NULL,
    ip_address VARCHAR(45) NULL DEFAULT NULL,
    browser VARCHAR(255) NULL DEFAULT NULL,
    login_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_success TINYINT(1) NOT NULL DEFAULT 0,
    failed_reason VARCHAR(100) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_login_logs_username_time (username, login_time),
    KEY idx_login_logs_user_time (user_id, login_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
