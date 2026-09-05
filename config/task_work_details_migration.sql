ALTER TABLE tasks
    ADD COLUMN work_description TEXT NULL AFTER location,
    ADD COLUMN work_action TEXT NULL AFTER work_description;
