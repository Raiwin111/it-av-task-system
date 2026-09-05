-- Configurable task locations so the location dropdown comes from the database
-- instead of being hardcoded in three different files. Managed by ADMIN in the
-- System Config page, following the same pattern as task_categories.

CREATE TABLE IF NOT EXISTS task_locations (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_task_locations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic demo locations that fit any hotel / office / facility operation.
INSERT IGNORE INTO task_locations (name, is_enabled, sort_order) VALUES
    ('ห้องประชุม A', 1, 1),
    ('ห้องประชุม B', 1, 2),
    ('ล็อบบี้', 1, 3),
    ('ห้องจัดเลี้ยง 1', 1, 4),
    ('ห้องจัดเลี้ยง 2', 1, 5),
    ('สำนักงานชั้น 1', 1, 6),
    ('พื้นที่จอดรถ', 1, 7),
    ('ห้องเก็บอุปกรณ์', 1, 8);
