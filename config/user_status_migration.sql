-- Run once to support Active / Disabled user accounts.
ALTER TABLE users
    ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER role;
