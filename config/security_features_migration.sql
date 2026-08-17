-- Security features introduced after the initial authentication hardening.
-- Back up the database before applying this migration.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS last_activity_at DATETIME NULL DEFAULT NULL,
    ADD KEY IF NOT EXISTS idx_users_last_activity (is_enabled, is_approved, last_activity_at);

CREATE TABLE IF NOT EXISTS auth_remember_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    selector CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    validator_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_remember_selector (selector),
    KEY idx_auth_remember_user (user_id),
    KEY idx_auth_remember_expiry (expires_at),
    CONSTRAINT fk_auth_remember_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
