<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require __DIR__ . "/../config/db.php";

function index_exists(mysqli $conn, string $table, string $index): bool
{
    $stmt = $conn->prepare(
        "SELECT 1
         FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
         LIMIT 1"
    );
    $stmt->bind_param("ss", $table, $index);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
         LIMIT 1"
    );
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function foreign_key_exists(mysqli $conn, string $table, string $constraint): bool
{
    $stmt = $conn->prepare(
        "SELECT 1
         FROM information_schema.table_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name = ?
           AND constraint_name = ?
           AND constraint_type = 'FOREIGN KEY'
         LIMIT 1"
    );
    $stmt->bind_param("ss", $table, $constraint);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

$duplicates = $conn->query(
    "SELECT username FROM users GROUP BY username HAVING COUNT(*) > 1 LIMIT 1"
)->fetch_assoc();
$orphans = $conn->query(
    "SELECT t.id
     FROM tasks AS t
     LEFT JOIN users AS u ON u.id = t.created_by
     WHERE u.id IS NULL
     LIMIT 1"
)->fetch_assoc();

if ($duplicates || $orphans) {
    fwrite(STDERR, "Migration preflight failed: duplicate usernames or orphan task creators exist." . PHP_EOL);
    exit(1);
}

$operations = [
    ["users", "uq_users_username", "ALTER TABLE users ADD UNIQUE KEY uq_users_username (username)"],
    ["tasks", "idx_tasks_active_created", "ALTER TABLE tasks ADD KEY idx_tasks_active_created (is_deleted, created_at, id)"],
    ["tasks", "idx_tasks_active_department_created", "ALTER TABLE tasks ADD KEY idx_tasks_active_department_created (is_deleted, department, created_at, id)"],
    ["tasks", "idx_tasks_active_status", "ALTER TABLE tasks ADD KEY idx_tasks_active_status (is_deleted, status)"],
    ["tasks", "idx_tasks_active_category", "ALTER TABLE tasks ADD KEY idx_tasks_active_category (is_deleted, category)"],
    ["tasks", "idx_tasks_active_start", "ALTER TABLE tasks ADD KEY idx_tasks_active_start (is_deleted, start_time)"],
    ["tasks", "idx_tasks_created_by", "ALTER TABLE tasks ADD KEY idx_tasks_created_by (created_by)"],
];

foreach ($operations as [$table, $index, $sql]) {
    if (!index_exists($conn, $table, $index)) {
        $conn->query($sql);
        echo "Added {$index}.", PHP_EOL;
    }
}

if (!foreign_key_exists($conn, "tasks", "fk_tasks_created_by")) {
    $conn->query(
        "ALTER TABLE tasks
         ADD CONSTRAINT fk_tasks_created_by
         FOREIGN KEY (created_by) REFERENCES users(id)
         ON UPDATE RESTRICT ON DELETE RESTRICT"
    );
    echo "Added fk_tasks_created_by.", PHP_EOL;
}

if (!column_exists($conn, "users", "last_activity_at")) {
    $conn->query("ALTER TABLE users ADD COLUMN last_activity_at DATETIME NULL DEFAULT NULL");
    echo "Added users.last_activity_at.", PHP_EOL;
}
if (!index_exists($conn, "users", "idx_users_last_activity")) {
    $conn->query("ALTER TABLE users ADD KEY idx_users_last_activity (is_enabled, is_approved, last_activity_at)");
    echo "Added idx_users_last_activity.", PHP_EOL;
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS auth_remember_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        selector CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        validator_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_auth_remember_selector (selector),
        KEY idx_auth_remember_user (user_id),
        KEY idx_auth_remember_expiry (expires_at),
        CONSTRAINT fk_auth_remember_user
            FOREIGN KEY (user_id) REFERENCES users(id)
            ON UPDATE RESTRICT ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$conn->query(
    "CREATE TABLE IF NOT EXISTS task_activity_logs (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
echo "Database migrations are current.", PHP_EOL;
