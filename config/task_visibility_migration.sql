-- Adds admin-controlled visibility for tasks.
-- is_visible = 1 (default): every team sees the task in KPI cards and Report.
-- is_visible = 0: only ADMIN/SUPER roles still see it; all other teams do not.
ALTER TABLE tasks
    ADD COLUMN is_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER is_deleted;
