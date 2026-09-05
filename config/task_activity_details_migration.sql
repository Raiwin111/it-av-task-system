-- Stores the before/after values behind each "updated" activity entry so the
-- task detail history can expand and show exactly what changed.
ALTER TABLE task_activity_logs
    ADD COLUMN details TEXT NULL AFTER new_status;
