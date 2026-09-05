-- Optional task attachments and an open finish time for unfinished work.
ALTER TABLE tasks
    MODIFY COLUMN finish_time DATETIME NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS task_images (
    id INT NOT NULL AUTO_INCREMENT,
    task_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_task_images_task (task_id),
    KEY idx_task_images_uploader (uploaded_by),
    CONSTRAINT fk_task_images_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_images_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
