-- Task audit history for create, update, status-change and soft-delete events.
-- Back up the database before applying this migration.

CREATE TABLE IF NOT EXISTS task_activity_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT NOT NULL,
    actor_user_id INT NULL,
    actor_name VARCHAR(120) NOT NULL,
    event_type VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    description VARCHAR(500) NOT NULL,
    old_status VARCHAR(30) NULL,
    new_status VARCHAR(30) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_task_activity_task_time (task_id, created_at, id),
    KEY idx_task_activity_actor_time (actor_user_id, created_at, id),
    CONSTRAINT fk_task_activity_task
        FOREIGN KEY (task_id) REFERENCES tasks(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_task_activity_actor
        FOREIGN KEY (actor_user_id) REFERENCES users(id)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
