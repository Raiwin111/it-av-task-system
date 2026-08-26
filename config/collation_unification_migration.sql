-- Unify table collation to utf8mb4_unicode_ci across the whole schema.
--
-- Context: `tasks`, `users`, `task_categories` currently use utf8mb4_general_ci
-- while `auth_remember_tokens`, `login_logs`, `task_images`,
-- `team_problem_options`, `task_activity_logs` use utf8mb4_unicode_ci.
-- Mixed collations do not break current queries (joins use integer ids),
-- but they block any future FOREIGN KEY or string comparison across these
-- tables (MySQL/MariaDB error: "Illegal mix of collations") and give
-- inconsistent Thai sort order between pages.
--
-- IMPORTANT:
-- 1. Back up the database first.
-- 2. Run during a maintenance window; CONVERT TO rewrites the table.
-- 3. Run this BEFORE task_category_integrity_migration.sql, since that
--    migration adds a foreign key that requires matching collation between
--    tasks.category and task_categories.code.
-- 4. This migration is intentionally not executed automatically by the app.

ALTER TABLE task_categories
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE tasks
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE users
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
