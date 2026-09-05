-- Connect tasks.category to task_categories.code with a real foreign key.
--
-- Context: `task_categories` already holds Hardware / Software / Customer,
-- but tasks.category has always been a free string with no reference to it,
-- and constants.php duplicated the same three options by hand.
--
-- SAFETY: this file does NOT modify any existing row in `tasks`. It only
-- adds a new reference row and a constraint. If any task still has a
-- category value that does not exist in task_categories, the final
-- ALTER TABLE ... ADD CONSTRAINT statement below will FAIL with a foreign
-- key error — on purpose. That failure is the signal to go run
-- task_category_preflight_report.sql, review the list with a human, and
-- decide per task whether to: (1) map it to an existing category,
-- (2) add a new category row, (3) set it to '-' (not specified), or
-- (4) leave it and handle the constraint differently. Nobody/no script
-- should bulk-rewrite those values automatically.
--
-- IMPORTANT:
-- 1. Back up the database first.
-- 2. Run AFTER collation_unification_migration.sql (tasks and
--    task_categories must share the same collation before adding the FK).
-- 3. Run task_category_preflight_report.sql first and resolve anything it
--    returns (manually, with sign-off) before expecting this file's final
--    ALTER TABLE to succeed.
-- 4. This migration is intentionally not executed automatically by the app
--    or by any AI assistant/agent — a human runs it by hand after review.

-- Ensure the "-" (not specified) sentinel exists as a valid category row.
-- This only adds a new row to task_categories; it does not touch `tasks`.
-- Kept hidden from the create/edit dropdowns by the app query
-- (WHERE is_enabled = 1 AND code <> '-'), it exists so that any task a
-- human explicitly decides should be "not specified" has somewhere valid
-- to point to.
INSERT INTO task_categories (code, display_name, is_enabled, sort_order)
VALUES ('-', 'ไม่ระบุ', 1, 0)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

-- Explicit index for the FK (kept separate/named, matching the style of
-- performance_integrity_migration.sql).
ALTER TABLE tasks
    ADD KEY idx_tasks_category (category);

-- This step intentionally fails if task_category_preflight_report.sql
-- still returns rows. Do not "fix" that by adding an UPDATE above this
-- line — resolve each row manually first, then re-run just this ALTER.
--
-- ON UPDATE CASCADE: renaming a category code (rare, via task_categories)
-- keeps existing tasks pointing at the right row.
-- ON DELETE RESTRICT: a category in use cannot be deleted; disable it via
-- task_categories.is_enabled instead (same pattern as users.is_enabled).
ALTER TABLE tasks
    ADD CONSTRAINT fk_tasks_category
        FOREIGN KEY (category) REFERENCES task_categories(code)
        ON UPDATE CASCADE
        ON DELETE RESTRICT;
