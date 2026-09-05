-- =====================================================================
-- Schema พื้นฐานสำหรับติดตั้งใหม่ (โครงสร้างล้วน ไม่มีข้อมูลใดๆ)
-- สร้างจากโครงสร้างฐานข้อมูลล่าสุดของระบบต้นแบบ
-- =====================================================================

DROP TABLE IF EXISTS task_categories;
CREATE TABLE `task_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_task_categories_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS users;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(120) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `department` varchar(20) NOT NULL,
  `role` varchar(20) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `failed_login_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `lock_until` datetime DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_activity_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_last_activity` (`is_enabled`,`is_approved`,`last_activity_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS tasks;
CREATE TABLE `tasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `category` varchar(50) NOT NULL,
  `department` varchar(20) NOT NULL,
  `responsible_name` varchar(100) DEFAULT NULL,
  `location` varchar(100) NOT NULL,
  `work_description` text DEFAULT NULL,
  `work_action` text DEFAULT NULL,
  `problem` text NOT NULL,
  `solution` text NOT NULL,
  `status` varchar(20) NOT NULL,
  `start_time` datetime NOT NULL,
  `finish_time` datetime DEFAULT NULL,
  `remark` text NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_tasks_active_created` (`is_deleted`,`created_at`,`id`),
  KEY `idx_tasks_active_department_created` (`is_deleted`,`department`,`created_at`,`id`),
  KEY `idx_tasks_active_status` (`is_deleted`,`status`),
  KEY `idx_tasks_active_category` (`is_deleted`,`category`),
  KEY `idx_tasks_active_start` (`is_deleted`,`start_time`),
  KEY `idx_tasks_created_by` (`created_by`),
  KEY `idx_tasks_category` (`category`),
  CONSTRAINT `fk_tasks_category` FOREIGN KEY (`category`) REFERENCES `task_categories` (`code`) ON UPDATE CASCADE,
  CONSTRAINT `fk_tasks_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS task_images;
CREATE TABLE `task_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(10) unsigned NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_task_images_task` (`task_id`),
  KEY `idx_task_images_uploader` (`uploaded_by`),
  CONSTRAINT `fk_task_images_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_images_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS task_activity_logs;
CREATE TABLE `task_activity_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `actor_name` varchar(120) NOT NULL,
  `event_type` varchar(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `description` varchar(500) NOT NULL,
  `old_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_task_activity_task_time` (`task_id`,`created_at`,`id`),
  KEY `idx_task_activity_actor_time` (`actor_user_id`,`created_at`,`id`),
  CONSTRAINT `fk_task_activity_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_task_activity_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS equipment;
CREATE TABLE `equipment` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_equipment_name` (`name`),
  KEY `index_equipment_enabled_sort` (`is_enabled`,`sort_order`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS task_equipments;
CREATE TABLE `task_equipments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `equipment_id` int(11) NOT NULL,
  `quantity` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_task_equipment` (`task_id`,`equipment_id`),
  KEY `index_task_equipment_equipment` (`equipment_id`),
  CONSTRAINT `fk_task_equipments_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`),
  CONSTRAINT `fk_task_equipments_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`),
  CONSTRAINT `check_task_equipment_quantity` CHECK (`quantity` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS task_locations;
CREATE TABLE `task_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_task_locations_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS team_problem_options;
CREATE TABLE `team_problem_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `department` varchar(20) NOT NULL,
  `problem_text` varchar(255) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_team_problem` (`department`,`problem_text`),
  KEY `index_team_problem_department` (`department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS login_logs;
CREATE TABLE `login_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `browser` varchar(255) DEFAULT NULL,
  `login_time` datetime NOT NULL DEFAULT current_timestamp(),
  `is_success` tinyint(1) NOT NULL DEFAULT 0,
  `failed_reason` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_logs_username_time` (`username`,`login_time`),
  KEY `idx_login_logs_user_time` (`user_id`,`login_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS auth_remember_tokens;
CREATE TABLE `auth_remember_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `selector` char(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `validator_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_remember_selector` (`selector`),
  KEY `idx_auth_remember_user` (`user_id`),
  KEY `idx_auth_remember_expiry` (`expires_at`),
  CONSTRAINT `fk_auth_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- หมวดปัญหามาตรฐาน
INSERT INTO task_categories (code, display_name, is_enabled, sort_order) VALUES ('Hardware','Hardware',1,1),('Software','Software',1,2),('Customer','Customer',1,3),('-','ไม่ระบุ',1,99) ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), is_enabled = VALUES(is_enabled);

-- อุปกรณ์ AV มาตรฐาน
INSERT INTO equipment (name, is_enabled, sort_order) VALUES ('โปรเจ็คเตอร์',1,1),('จอ LED',1,2),('ไมค์ลอย',1,3),('ระบบเสียงหลักขององค์กร',1,4) ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), sort_order = VALUES(sort_order);

-- สถานที่ตัวอย่าง
INSERT INTO task_locations (name, is_enabled, sort_order) VALUES ('ห้องประชุม A',1,1),('ห้องประชุม B',1,2),('ล็อบบี้',1,3),('ห้องจัดเลี้ยง 1',1,4),('ห้องจัดเลี้ยง 2',1,5),('สำนักงานชั้น 1',1,6),('พื้นที่จอดรถ',1,7),('ห้องเก็บอุปกรณ์',1,8) ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), sort_order = VALUES(sort_order);
