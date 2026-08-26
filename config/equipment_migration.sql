-- Structured AV equipment. This migration does not alter tasks or existing AV text.
CREATE TABLE IF NOT EXISTS equipment (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_equipment_name (name),
    KEY index_equipment_enabled_sort (is_enabled, sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_equipments (
    id INT NOT NULL AUTO_INCREMENT,
    task_id INT NOT NULL,
    equipment_id INT NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_task_equipment (task_id, equipment_id),
    KEY index_task_equipment_equipment (equipment_id),
    CONSTRAINT fk_task_equipments_task
        FOREIGN KEY (task_id) REFERENCES tasks(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_task_equipments_equipment
        FOREIGN KEY (equipment_id) REFERENCES equipment(id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT check_task_equipment_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed AV Task Input choices. Keep the existing projector master name so
-- historical task relations continue to reference the same row.
INSERT INTO equipment (name, is_enabled, sort_order) VALUES
    ('โปรเจ็คเตอร์', 1, 1),
    ('จอ LED', 1, 2),
    ('ไมค์ลอย', 1, 3),
    ('เครื่องเสียงของทางโรงแรม', 1, 4)
ON DUPLICATE KEY UPDATE
    is_enabled = VALUES(is_enabled),
    sort_order = VALUES(sort_order);
