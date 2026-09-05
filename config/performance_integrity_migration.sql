-- Performance and integrity hardening for the current tasks/users schema.
--
-- IMPORTANT:
-- 1. Back up the database first.
-- 2. Run both preflight queries and resolve any returned rows.
-- 3. Apply the ALTER statements during a maintenance window.
-- This migration is intentionally not executed automatically by the app.

-- Preflight: this must return no rows before adding uq_users_username.
SELECT username, COUNT(*) AS duplicate_count
FROM users
GROUP BY username
HAVING COUNT(*) > 1;

-- Preflight: this must return 0 before adding fk_tasks_created_by.
SELECT COUNT(*) AS orphan_task_creators
FROM tasks AS t
LEFT JOIN users AS u ON u.id = t.created_by
WHERE u.id IS NULL;

-- Prevent duplicate usernames during concurrent registration/admin updates.
ALTER TABLE users
    ADD UNIQUE KEY uq_users_username (username);

-- Support the active-task scopes, filters and ordering used by Dashboard/Report.
ALTER TABLE tasks
    ADD KEY idx_tasks_active_created (is_deleted, created_at, id),
    ADD KEY idx_tasks_active_department_created (is_deleted, department, created_at, id),
    ADD KEY idx_tasks_active_status (is_deleted, status),
    ADD KEY idx_tasks_active_category (is_deleted, category),
    ADD KEY idx_tasks_active_start (is_deleted, start_time),
    ADD KEY idx_tasks_created_by (created_by);

-- Preserve task history by preventing deletion of a referenced creator.
ALTER TABLE tasks
    ADD CONSTRAINT fk_tasks_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE RESTRICT
        ON DELETE RESTRICT;
