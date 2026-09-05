-- Self-registration fields. Existing users stay approved and active.
ALTER TABLE users
    ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 1 AFTER is_enabled,
    ADD COLUMN full_name VARCHAR(120) NULL DEFAULT NULL AFTER username,
    ADD COLUMN email VARCHAR(150) NULL DEFAULT NULL AFTER full_name,
    ADD UNIQUE KEY uq_users_email (email);
