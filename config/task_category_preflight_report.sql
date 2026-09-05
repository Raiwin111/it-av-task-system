-- READ-ONLY report. Does not modify any data. Safe to run any time.
--
-- Purpose: list every task whose `category` value does not match any row
-- in `task_categories`, so a human can decide what each one should become
-- BEFORE any schema constraint or data change is applied. See
-- task_category_integrity_migration.sql for the next step, which will
-- refuse to run (FK error) until this list is empty or explicitly handled.

SELECT
    id,
    title,
    department,
    category AS current_category,
    created_at
FROM tasks
WHERE category NOT IN (SELECT code FROM task_categories)
   OR category = ''
   OR category IS NULL
ORDER BY created_at;
