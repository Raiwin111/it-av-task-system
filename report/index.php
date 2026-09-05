<?php
require_once __DIR__ . "/../auth/auth_check.php";
require_once __DIR__ . "/../auth/authorization.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/constants.php";
require_once __DIR__ . "/../includes/helpers.php";
require_once __DIR__ . "/../includes/task_activity.php";
require_once __DIR__ . "/../task_input/image_helpers.php";

// Always request fresh task data after users return from Task Input.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$app_page_title = "Report | IT / AV Task Management System";
$role = strtoupper($_SESSION["role"] ?? "USER");
$user_id = (int) ($_SESSION["user_id"] ?? 0);
$can_control_task_status = can_manage_all_tasks();
$account_can_modify = is_account_approved();
$active_nav = "report";
$report_task_csrf = $_SESSION["report_task_csrf"] ??= bin2hex(random_bytes(32));
$report_update_error = "";
$report_update_form_data = null;
$report_location_options = task_location_options($conn);
$report_status_options = $task_status_options;
$report_equipment_items = [];
$report_equipment_result = $conn->query("SELECT id, name, is_enabled FROM equipment ORDER BY sort_order ASC, name ASC, id ASC");
while ($report_equipment_item = $report_equipment_result->fetch_assoc()) $report_equipment_items[] = $report_equipment_item;
$report_equipment_by_id = [];
foreach ($report_equipment_items as $report_equipment_item) $report_equipment_by_id[(int) $report_equipment_item["id"]] = $report_equipment_item;

function report_post_string(string $key): string
{
    $value = $_POST[$key] ?? "";
    return is_string($value) ? $value : "";
}

function report_get_string(string $key): string
{
    $value = $_GET[$key] ?? "";
    return is_string($value) ? $value : "";
}

function report_task_duration(?string $start_time, ?string $finish_time): ?string
{
    if (!$start_time || !$finish_time) return null;

    $start_timestamp = strtotime($start_time);
    $finish_timestamp = strtotime($finish_time);
    if ($start_timestamp === false || $finish_timestamp === false || $finish_timestamp < $start_timestamp) return null;

    $remaining_minutes = (int) floor(($finish_timestamp - $start_timestamp) / 60);
    if ($remaining_minutes === 0) return "น้อยกว่า 1 นาที";

    $days = intdiv($remaining_minutes, 1440);
    $remaining_minutes %= 1440;
    $hours = intdiv($remaining_minutes, 60);
    $minutes = $remaining_minutes % 60;
    $parts = [];
    if ($days > 0) $parts[] = $days . " วัน";
    if ($hours > 0) $parts[] = $hours . " ชั่วโมง";
    if ($minutes > 0) $parts[] = $minutes . " นาที";
    return implode(" ", $parts);
}

// Report edit modal posts back to this page; permissions are checked again before every update.
if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST" && report_post_string("action") === "update") {
    $update_id = (int) report_post_string("task_id");
    $task_stmt = $conn->prepare("SELECT * FROM tasks WHERE id = ? AND is_deleted = 0 LIMIT 1");
    $task_stmt->bind_param("i", $update_id);
    $task_stmt->execute();
    $existing_task = $task_stmt->get_result()->fetch_assoc();
    $task_stmt->close();

    $can_update = $existing_task && can_edit_task($existing_task);
    // A hidden task is locked for everyone except ADMIN (who manages visibility).
    if ($can_update && (int) $existing_task["is_visible"] === 0 && !can_manage_users()) {
        $can_update = false;
    }

    if (!$can_update) {
        header("Location: index.php?error=forbidden");
        exit;
    }

    $existing_equipment_ids = [];
    $existing_equipment_stmt = $conn->prepare("SELECT equipment_id FROM task_equipments WHERE task_id = ?");
    $existing_equipment_stmt->bind_param("i", $update_id);
    $existing_equipment_stmt->execute();
    $existing_equipment_result = $existing_equipment_stmt->get_result();
    while ($existing_equipment_row = $existing_equipment_result->fetch_assoc()) $existing_equipment_ids[(int) $existing_equipment_row["equipment_id"]] = true;
    $existing_equipment_stmt->close();

    $posted_equipment_rows = [];
    $posted_equipment_ids = is_array($_POST["equipment_id"] ?? null) ? $_POST["equipment_id"] : [];
    $posted_equipment_quantities = is_array($_POST["equipment_quantity"] ?? null) ? $_POST["equipment_quantity"] : [];
    $equipment_selection_invalid = false;
    foreach ($posted_equipment_ids as $index => $posted_equipment_id) {
        $equipment_id = filter_var($posted_equipment_id, FILTER_VALIDATE_INT);
        $quantity = filter_var($posted_equipment_quantities[$index] ?? null, FILTER_VALIDATE_INT);
        if (!$equipment_id && trim((string) $posted_equipment_id) === "") continue;
        $equipment_item = $equipment_id ? ($report_equipment_by_id[$equipment_id] ?? null) : null;
        if (!$equipment_item || !$quantity || $quantity < 1 || ((int) $equipment_item["is_enabled"] !== 1 && !isset($existing_equipment_ids[$equipment_id]))) {
            $equipment_selection_invalid = true;
            continue;
        }
        $posted_equipment_rows[$equipment_id] = ($posted_equipment_rows[$equipment_id] ?? 0) + $quantity;
    }

    $title = trim(report_post_string("title"));
    // Only ADMIN may reassign a task to a different team; SUPER edits within its own team.
    $department = current_role() === "ADMIN"
        ? trim(report_post_string("department"))
        : (string) $existing_task["department"];
    $responsible_name = trim(report_post_string("responsible_name"));
    $location_choice = trim(report_post_string("location"));
    $location = $location_choice === "__other__" ? trim(report_post_string("other_location")) : $location_choice;
    $category = trim(report_post_string("category"));
    $category = $category === "" ? "-" : $category;
    $status = trim(report_post_string("status"));
    if (!$can_control_task_status) {
        $existing_status = (string) $existing_task["status"];
        $status = $existing_status === "cancelled" ? "cancelled" : "pending";
    }
    $work_description = trim(report_post_string("work_description"));
    $work_action = trim(report_post_string("work_action"));
    $problem = trim(report_post_string("problem"));
    $solution = trim(report_post_string("solution"));
    $remark = trim(report_post_string("remark"));
    $it_problem_missing = task_problem_is_required($department, $problem);
    $start_date_value = trim(report_post_string("start_date"));
    $start_work_time_value = trim(report_post_string("start_work_time"));
    $finish_date_value = trim(report_post_string("finish_date"));
    $finish_work_time_value = trim(report_post_string("finish_work_time"));
    $start_time = combine_thai_date_time($start_date_value, $start_work_time_value);
    $finish_input_started = $finish_date_value !== "" || $finish_work_time_value !== "";
    $finish_time = $finish_input_started ? combine_thai_date_time($finish_date_value, $finish_work_time_value) : null;
    $status = task_workflow_status(
        $department,
        $solution,
        $status,
        false,
        $can_control_task_status,
        $work_action,
        $finish_time !== null
    );

    $location = $location === "" ? "-" : $location;
    $work_description = $work_description === "" ? "-" : $work_description;
    $work_action = $work_action === "" ? "-" : $work_action;
    $problem = $problem === "" ? "-" : $problem;
    $solution = $solution === "" ? "-" : $solution;
    $remark = $remark === "" ? "-" : $remark;

    $report_update_form_data = [
        "id" => $update_id,
        "title" => $title,
        "department" => $department,
        "responsible_name" => $responsible_name,
        "location" => $location_choice,
        "other_location" => report_post_string("other_location"),
        "category" => $category,
        "status" => $status,
        "work_description" => $work_description,
        "work_action" => $work_action,
        "problem" => $problem,
        "solution" => $solution,
        "remark" => $remark,
        "start_date" => $start_date_value,
        "start_work_time" => $start_work_time_value,
        "finish_date" => $finish_date_value,
        "finish_work_time" => $finish_work_time_value,
        "equipment" => array_map(
            static fn($equipment_id, $quantity): array => ["equipment_id" => (int) $equipment_id, "quantity" => (int) $quantity],
            array_keys($posted_equipment_rows),
            array_values($posted_equipment_rows)
        )
    ];

    // Image attachments for the edited task: validated once before the DB work.
    [$new_task_images, $task_images_error] = prepare_task_image_uploads("task_images");
    $delete_image_ids = array_values(array_unique(array_filter(
        array_map("intval", is_array($_POST["delete_image_ids"] ?? null) ? $_POST["delete_image_ids"] : []),
        static fn(int $image_id): bool => $image_id > 0
    )));

    if (!hash_equals($report_task_csrf, report_post_string("csrf_token"))) {
        http_response_code(419);
        $report_update_error = "คำขอแก้ไขหมดอายุ กรุณาลองใหม่อีกครั้ง";
    } elseif ($task_images_error !== null) {
        $report_update_error = $task_images_error;
    } elseif (
        $title === ""
        || $it_problem_missing
        || ($department === "AV" && $equipment_selection_invalid)
        || !in_array($department, $departments, true)
        || ($category !== "-" && !array_key_exists($category, $problem_category_options))
        || !array_key_exists($status, $report_status_options)
        || !$start_time
        || ($finish_input_started && !$finish_time)
        || ($finish_time && $finish_time < $start_time)
    ) {
        $report_update_error = $it_problem_missing
            ? "งาน IT จำเป็นต้องระบุปัญหาที่พบ"
            : (($department === "AV" && $equipment_selection_invalid) ? "รายการอุปกรณ์ AV ไม่ถูกต้องหรือถูกปิดใช้งานแล้ว" : "กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วนและตรวจสอบช่วงวันที่กับเวลา");
    } else {
        $conn->begin_transaction();
        $update_stmt = $conn->prepare("UPDATE tasks SET title = ?, category = ?, department = ?, responsible_name = ?, location = ?, work_description = ?, work_action = ?, problem = ?, solution = ?, status = ?, start_time = ?, finish_time = ?, remark = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND is_deleted = 0");
        $update_stmt->bind_param("sssssssssssssi", $title, $category, $department, $responsible_name, $location, $work_description, $work_action, $problem, $solution, $status, $start_time, $finish_time, $remark, $update_id);
        if ($update_stmt->execute()) {
            $update_stmt->close();
            $equipment_saved = true;
            if ($department === "AV" && $posted_equipment_rows) {
                // Preserve historical associations: submitted rows add equipment
                // or update quantity, but this flow never deletes an old row.
                $equipment_stmt = $conn->prepare("INSERT INTO task_equipments (task_id, equipment_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = CURRENT_TIMESTAMP");
                foreach ($posted_equipment_rows as $equipment_id => $quantity) {
                    $equipment_stmt->bind_param("iii", $update_id, $equipment_id, $quantity);
                    if (!$equipment_stmt->execute()) {
                        $equipment_saved = false;
                        break;
                    }
                }
                $equipment_stmt->close();
            }
            $activity_saved = $equipment_saved && record_task_update_activities($conn, $update_id, $existing_task, [
                "title" => $title,
                "category" => $category,
                "department" => $department,
                "responsible_name" => $responsible_name,
                "location" => $location,
                "work_description" => $work_description,
                "work_action" => $work_action,
                "problem" => $problem,
                "solution" => $solution,
                "status" => $status,
                "start_time" => $start_time,
                "finish_time" => $finish_time,
                "remark" => $remark
            ]);
            $problem_option_saved = true;
            if ($equipment_saved && $activity_saved && $problem !== "-") {
                $option_stmt = $conn->prepare("INSERT IGNORE INTO team_problem_options (department, problem_text, created_by) VALUES (?, ?, ?)");
                $option_stmt->bind_param("ssi", $department, $problem, $user_id);
                $problem_option_saved = $option_stmt->execute();
                $option_stmt->close();
            }

            // New attachments: files move into uploads/tasks inside the same transaction.
            $images_saved = true;
            $stored_new_image_paths = [];
            if ($equipment_saved && $activity_saved && $problem_option_saved && $new_task_images) {
                $upload_directory = __DIR__ . "/../uploads/tasks";
                if (!is_dir($upload_directory) && !mkdir($upload_directory, 0755, true)) {
                    $images_saved = false;
                }
                if ($images_saved) {
                    $insert_image_stmt = $conn->prepare("INSERT INTO task_images (task_id, file_path, original_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
                    foreach ($new_task_images as $image) {
                        $file_name = bin2hex(random_bytes(16)) . "." . $image["extension"];
                        $absolute_path = $upload_directory . "/" . $file_name;
                        if (!move_uploaded_file($image["temporary_path"], $absolute_path)) {
                            $images_saved = false;
                            break;
                        }
                        $relative_path = "uploads/tasks/" . $file_name;
                        $original_name = $image["original_name"];
                        $mime_type = $image["mime_type"];
                        $file_size = (int) $image["file_size"];
                        $insert_image_stmt->bind_param("isssii", $update_id, $relative_path, $original_name, $mime_type, $file_size, $user_id);
                        if (!$insert_image_stmt->execute()) {
                            @unlink($absolute_path);
                            $images_saved = false;
                            break;
                        }
                        $stored_new_image_paths[] = $absolute_path;
                    }
                    $insert_image_stmt->close();
                    if ($images_saved) {
                        $images_saved = record_task_activity($conn, $update_id, "updated", "เพิ่มรูปภาพประกอบงาน " . count($new_task_images) . " รูป");
                    }
                }
            }

            // Removed attachments: only rows owned by this task may be deleted.
            $images_deleted = true;
            $image_paths_to_unlink = [];
            if ($equipment_saved && $activity_saved && $problem_option_saved && $images_saved && $delete_image_ids) {
                $id_placeholders = implode(",", array_fill(0, count($delete_image_ids), "?"));
                $select_images_stmt = $conn->prepare("SELECT id, file_path FROM task_images WHERE id IN ({$id_placeholders}) AND task_id = ?");
                $select_images_stmt->bind_param(str_repeat("i", count($delete_image_ids)) . "i", ...[...$delete_image_ids, $update_id]);
                $select_images_stmt->execute();
                $removable_rows = $select_images_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $select_images_stmt->close();
                if ($removable_rows) {
                    $delete_images_stmt = $conn->prepare("DELETE FROM task_images WHERE id IN ({$id_placeholders}) AND task_id = ?");
                    $delete_images_stmt->bind_param(str_repeat("i", count($delete_image_ids)) . "i", ...[...$delete_image_ids, $update_id]);
                    $images_deleted = $delete_images_stmt->execute();
                    $delete_images_stmt->close();
                    if ($images_deleted) {
                        $image_paths_to_unlink = array_map(static fn($row) => $row["file_path"], $removable_rows);
                        record_task_activity($conn, $update_id, "updated", "ลบรูปภาพประกอบงาน " . count($removable_rows) . " รูป");
                    }
                }
            }
            if ($equipment_saved && $activity_saved && $problem_option_saved && $images_saved && $images_deleted) {
                $conn->commit();
                // Files are removed only after the DB transaction survives.
                foreach ($image_paths_to_unlink as $image_path_to_unlink) {
                    $absolute_image_path = __DIR__ . "/../" . ltrim($image_path_to_unlink, "/\\");
                    if (is_file($absolute_image_path)) @unlink($absolute_image_path);
                }
                header("Location: index.php?updated=1");
                exit;
            }
            $conn->rollback();
            foreach ($stored_new_image_paths as $stored_new_image_path) @unlink($stored_new_image_path);
            $report_update_error = "ไม่สามารถบันทึกงานและอุปกรณ์ได้ครบถ้วน กรุณาลองอีกครั้ง";
        } else {
            $update_stmt->close();
            $conn->rollback();
            $report_update_error = "ไม่สามารถบันทึกการแก้ไขได้ กรุณาลองอีกครั้ง";
        }
    }
}

// Soft delete keeps the row in MySQL while hiding it from active task lists.
if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST" && ($_POST["action"] ?? "") === "delete") {
    if (!hash_equals($report_task_csrf, report_post_string("csrf_token"))) {
        header("Location: index.php?error=csrf");
        exit;
    }

    $delete_id = (int) ($_POST["task_id"] ?? 0);

    $owner_stmt = $conn->prepare("SELECT created_by, department, status, is_visible FROM tasks WHERE id = ? AND is_deleted = 0 LIMIT 1");
    $owner_stmt->bind_param("i", $delete_id);
    $owner_stmt->execute();
    $delete_task = $owner_stmt->get_result()->fetch_assoc();
    $owner_stmt->close();

    $can_delete = $delete_task && can_delete_task($delete_task);
    // A hidden task is locked for everyone except ADMIN (who manages visibility).
    if ($can_delete && (int) $delete_task["is_visible"] === 0 && !can_manage_users()) {
        $can_delete = false;
    }

    if (!$can_delete) {
        header("Location: index.php?error=forbidden");
        exit;
    }

    $delete_stmt = $conn->prepare("UPDATE tasks SET is_deleted = 1 WHERE id = ?");
    $delete_stmt->bind_param("i", $delete_id);
    $delete_stmt->execute();
    $delete_stmt->close();
    record_task_activity(
        $conn,
        $delete_id,
        "deleted",
        "ลบงานออกจากรายการ",
        (string) ($delete_task["status"] ?? ""),
        (string) ($delete_task["status"] ?? "")
    );

    header("Location: index.php?deleted=1");
    exit;
}

// Admin-only visibility toggle. A hidden task stays out of every team's KPI cards,
// Report list, and task detail access until an admin turns it back on.
if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST" && report_post_string("action") === "toggle_visibility") {
    if (!hash_equals($report_task_csrf, report_post_string("csrf_token"))) {
        header("Location: index.php?error=csrf");
        exit;
    }
    if (!can_manage_users()) {
        // Visibility control is reserved for ADMIN only; SUPER and USER cannot toggle it.
        header("Location: index.php?error=forbidden");
        exit;
    }

    $visibility_id = (int) report_post_string("task_id");
    $visibility_stmt = $conn->prepare("SELECT department, status, is_visible FROM tasks WHERE id = ? AND is_deleted = 0 LIMIT 1");
    $visibility_stmt->bind_param("i", $visibility_id);
    $visibility_stmt->execute();
    $visibility_task = $visibility_stmt->get_result()->fetch_assoc();
    $visibility_stmt->close();

    if (!$visibility_task) {
        header("Location: index.php?error=forbidden");
        exit;
    }

    $new_visibility = (int) $visibility_task["is_visible"] === 1 ? 0 : 1;
    $visibility_update_stmt = $conn->prepare("UPDATE tasks SET is_visible = ? WHERE id = ?");
    $visibility_update_stmt->bind_param("ii", $new_visibility, $visibility_id);
    $visibility_update_stmt->execute();
    $visibility_update_stmt->close();

    record_task_activity(
        $conn,
        $visibility_id,
        "updated",
        $new_visibility === 1 ? "เปิดให้ทีมอื่นมองเห็นงาน" : "ซ่อนงานจากทีมอื่น (ไม่นับใน KPI และ Report ของทีม)",
        (string) ($visibility_task["status"] ?? ""),
        (string) ($visibility_task["status"] ?? "")
    );

    header("Location: index.php?visibility=1");
    exit;
}
function report_query(mysqli $conn, string $sql, string $types = "", array $params = []): mysqli_result
{
    $stmt = $conn->prepare($sql);
    if ($types !== "") {
        $references = [];
        foreach ($params as $index => $_value) {
            $references[$index] = &$params[$index];
        }
        $stmt->bind_param($types, ...$references);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

function report_filter_date(string $value, bool $end_of_day = false): ?string
{
    if ($value === "") return null;
    if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/D', $value, $matches)) return null;
    $year = (int) $matches[3];
    if ($year > 2400) $year -= 543;
    $month = (int) $matches[2];
    $day = (int) $matches[1];
    if (!checkdate($month, $day, $year)) return null;
    return sprintf("%04d-%02d-%02d %s", $year, $month, $day, $end_of_day ? "23:59:59" : "00:00:00");
}

// SUPER is an operational manager: it can view/manage all teams but cannot
// administer user accounts. USER remains restricted to its assigned team.
$report_department = (string) ($_SESSION["department"] ?? "");
$report_can_filter_team = can_manage_all_tasks();
// ADMIN owns visibility decisions and sees every task. USER and SUPER never
// receive tasks hidden by an admin in their listings.
$scope_conditions = ["t.is_deleted = 0"];
$scope_types = "";
$scope_params = [];
if (!$account_can_modify) {
    $scope_conditions[] = "1 = 0";
} elseif (!$report_can_filter_team) {
    $scope_conditions[] = "t.department = ?";
    $scope_types .= "s";
    $scope_params[] = $report_department;
}
if ($account_can_modify && !can_manage_users()) {
    $scope_conditions[] = "t.is_visible = 1";
}
$scope_where = implode(" AND ", $scope_conditions);

$report_search = trim(report_get_string("q"));
$report_search = mb_substr($report_search, 0, 100);
$report_filter_task_id = max(0, (int) report_get_string("task_id"));
$requested_department = report_get_string("department");
$requested_status = report_get_string("status");
$requested_category = report_get_string("category");
$report_filter_department = $report_can_filter_team && in_array($requested_department, $departments, true)
    ? $requested_department
    : "";
$report_filter_status = array_key_exists($requested_status, $report_status_options)
    ? $requested_status
    : "";
$report_filter_category = array_key_exists($requested_category, $problem_category_options)
    ? $requested_category
    : "";
$report_filter_start = trim(report_get_string("start_date"));
$report_filter_end = trim(report_get_string("end_date"));
$report_start_sql = report_filter_date($report_filter_start);
$report_end_sql = report_filter_date($report_filter_end, true);
$report_filter_error = "";
if (($report_filter_start !== "" && !$report_start_sql) || ($report_filter_end !== "" && !$report_end_sql)) {
    $report_filter_error = "รูปแบบวันที่ในตัวกรองไม่ถูกต้อง";
} elseif ($report_start_sql && $report_end_sql && $report_start_sql > $report_end_sql) {
    $report_filter_error = "วันที่เริ่มต้นต้องไม่อยู่หลังวันที่สิ้นสุด";
}

$filter_conditions = $scope_conditions;
$filter_types = $scope_types;
$filter_params = $scope_params;
if ($report_filter_task_id > 0) {
    $filter_conditions[] = "t.id = ?";
    $filter_types .= "i";
    $filter_params[] = $report_filter_task_id;
}
if ($report_search !== "") {
    $filter_conditions[] = "CONCAT_WS(' ', t.title, t.responsible_name, t.location, t.category, t.work_description, t.work_action, t.problem, t.solution) LIKE ?";
    $filter_types .= "s";
    $filter_params[] = "%" . $report_search . "%";
}
if ($report_filter_department !== "") {
    $filter_conditions[] = "t.department = ?";
    $filter_types .= "s";
    $filter_params[] = $report_filter_department;
}
if ($report_filter_status !== "") {
    $filter_conditions[] = "t.status = ?";
    $filter_types .= "s";
    $filter_params[] = $report_filter_status;
}
if ($report_filter_category !== "") {
    $filter_conditions[] = "t.category = ?";
    $filter_types .= "s";
    $filter_params[] = $report_filter_category;
}
if ($report_filter_error === "" && $report_start_sql) {
    $filter_conditions[] = "t.created_at >= ?";
    $filter_types .= "s";
    $filter_params[] = $report_start_sql;
}
if ($report_filter_error === "" && $report_end_sql) {
    $filter_conditions[] = "t.created_at <= ?";
    $filter_types .= "s";
    $filter_params[] = $report_end_sql;
}
$filter_where = implode(" AND ", $filter_conditions);

$allowed_page_sizes = [10, 25, 50, 100];
$report_page_size = (int) (report_get_string("per_page") ?: 25);
if (!in_array($report_page_size, $allowed_page_sizes, true)) $report_page_size = 25;
$report_total_result = report_query(
    $conn,
    "SELECT COUNT(*) AS total FROM tasks AS t WHERE {$filter_where}",
    $filter_types,
    $filter_params
);
$report_filtered_total = (int) ($report_total_result->fetch_assoc()["total"] ?? 0);
$report_total_pages = max(1, (int) ceil($report_filtered_total / $report_page_size));
$report_page = max(1, (int) (report_get_string("page") ?: 1));
$report_page = min($report_page, $report_total_pages);
$report_offset = ($report_page - 1) * $report_page_size;

$tasks = report_query(
    $conn,
    "SELECT t.*, COALESCE(NULLIF(t.responsible_name, ''), u.department, '-') AS created_by_name
     FROM tasks AS t
     LEFT JOIN users AS u ON u.id = t.created_by
     WHERE {$filter_where}
     ORDER BY t.created_at DESC, t.id DESC
     LIMIT {$report_page_size} OFFSET {$report_offset}",
    $filter_types,
    $filter_params
);
$task_rows = $tasks->fetch_all(MYSQLI_ASSOC);
$report_images_by_task = [];
$report_equipment_by_task = [];
$report_activity_by_task = load_task_activities(
    $conn,
    array_column($task_rows, "id")
);
if ($task_rows) {
    $report_task_ids = implode(",", array_map(static fn($task) => (int) $task["id"], $task_rows));
    $report_image_result = $conn->query("SELECT task_id, id, file_path, original_name FROM task_images WHERE task_id IN ({$report_task_ids}) ORDER BY created_at ASC, id ASC");
    while ($report_image = $report_image_result->fetch_assoc()) {
        $report_images_by_task[(int) $report_image["task_id"]][] = [
            "id" => (int) $report_image["id"],
            "file_path" => $report_image["file_path"],
            "original_name" => $report_image["original_name"]
        ];
    }
    $report_task_equipment_result = $conn->query("SELECT te.task_id, te.equipment_id, te.quantity, e.name, e.is_enabled FROM task_equipments te INNER JOIN equipment e ON e.id = te.equipment_id WHERE te.task_id IN ({$report_task_ids}) ORDER BY e.sort_order ASC, e.name ASC, e.id ASC");
    while ($report_task_equipment = $report_task_equipment_result->fetch_assoc()) {
        $report_equipment_by_task[(int) $report_task_equipment["task_id"]][] = [
            "equipment_id" => (int) $report_task_equipment["equipment_id"],
            "name" => $report_task_equipment["name"],
            "quantity" => (int) $report_task_equipment["quantity"],
            "is_enabled" => (int) $report_task_equipment["is_enabled"]
        ];
    }
}
foreach ($task_rows as &$report_task_row) {
    $report_task_row["images"] = $report_images_by_task[(int) $report_task_row["id"]] ?? [];
    $report_task_row["activity_log"] = $report_activity_by_task[(int) $report_task_row["id"]] ?? [];
    $report_task_row["equipment"] = $report_equipment_by_task[(int) $report_task_row["id"]] ?? [];
}
unset($report_task_row);
// Permission flags are computed on the server so the dynamic detail/delete
// modals can trust them without re-implementing role rules in the browser.
foreach ($task_rows as &$report_task_permission_row) {
    $report_task_permission_row["can_edit"] = can_edit_task($report_task_permission_row);
    $report_task_permission_row["can_delete"] = can_delete_task($report_task_permission_row);
}
unset($report_task_permission_row);
$counts = ["total" => 0, "pending" => 0, "in_progress" => 0, "completed" => 0, "cancelled" => 0];
$count_result = report_query(
    $conn,
    "SELECT t.status, COUNT(*) AS total FROM tasks AS t WHERE {$scope_where} GROUP BY t.status",
    $scope_types,
    $scope_params
);
while ($count_row = $count_result->fetch_assoc()) {
    $status_key = strtolower(str_replace(" ", "_", trim((string) $count_row["status"])));
    $status_key = [
        "รอดำเนินการ" => "pending",
        "กำลังดำเนินการ" => "in_progress",
        "เสร็จสิ้น" => "completed",
        "ยกเลิก" => "cancelled",
    ][$status_key] ?? $status_key;
    $total = (int) $count_row["total"];
    $counts["total"] += $total;
    if (isset($counts[$status_key])) $counts[$status_key] += $total;
}
$report_page_query = array_filter([
    "task_id" => $report_filter_task_id ?: "",
    "q" => $report_search,
    "department" => $report_filter_department,
    "status" => $report_filter_status,
    "category" => $report_filter_category,
    "start_date" => $report_filter_start,
    "end_date" => $report_filter_end,
    "per_page" => $report_page_size,
], static fn($value) => $value !== "");
$report_filter_url_without = static function (string $key) use ($report_page_query): string {
    $query = $report_page_query;
    unset($query["page"], $query[$key]);
    return $query ? "?" . http_build_query($query) : "index.php";
};
$report_active_filters = [];
if ($report_search !== "") $report_active_filters[] = ["q", "ค้นหา", $report_search];
if ($report_filter_department !== "") $report_active_filters[] = ["department", "ทีม", $report_filter_department];
if ($report_filter_status !== "") $report_active_filters[] = ["status", "สถานะ", $report_status_options[$report_filter_status] ?? $report_filter_status];
if ($report_filter_category !== "") $report_active_filters[] = ["category", "ประเภท", $problem_category_options[$report_filter_category] ?? $report_filter_category];
if ($report_filter_start !== "") $report_active_filters[] = ["start_date", "วันที่สร้างตั้งแต่", $report_filter_start];
if ($report_filter_end !== "") $report_active_filters[] = ["end_date", "วันที่สร้างถึง", $report_filter_end];
$report_advanced_filter_count = count(array_filter([
    $report_filter_department,
    $report_filter_status,
    $report_filter_category,
    $report_filter_start,
    $report_filter_end,
], static fn($value) => $value !== ""));
$report_team_url = static function (string $department) use ($report_page_query): string {
    $query = $report_page_query;
    unset($query["page"], $query["department"]);
    if ($department !== "") $query["department"] = $department;
    return "?" . http_build_query($query);
};
$report_page_url = static function (int $page) use ($report_page_query): string {
    return "?" . http_build_query(array_merge($report_page_query, ["page" => $page]));
};
$report_visible_start = $report_filtered_total === 0 ? 0 : $report_offset + 1;
$report_visible_end = min($report_offset + count($task_rows), $report_filtered_total);
$edit_id = isset($_GET["edit"]) ? (int) $_GET["edit"] : 0;
$selected_task = null;
foreach ($task_rows as $task) if ((int) $task["id"] === $edit_id) $selected_task = $task;
require_once __DIR__ . "/../includes/app_header.php";
?>
<div class="app-shell d-flex"><?php require_once __DIR__ . "/../includes/app_sidebar.php"; ?><main class="report-page main-content flex-grow-1 p-4 p-lg-5"><?php if (isset($_GET["updated"])): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i>บันทึกการแก้ไขเรียบร้อยแล้ว</div><?php endif; ?><?php if (isset($_GET["deleted"])): ?><div class="alert alert-success">ลบงานเรียบร้อยแล้ว</div><?php endif; ?><?php if (isset($_GET["visibility"])): ?><div class="alert alert-success"><i class="bi bi-eye me-2"></i>บันทึกการเปลี่ยนการมองเห็นงานเรียบร้อยแล้ว</div><?php endif; ?><?php if (($_GET["error"] ?? "") === "forbidden"): ?><div class="alert alert-danger">คุณไม่มีสิทธิ์ดำเนินการกับงานนี้</div><?php endif; ?><?php if (($_GET["error"] ?? "") === "csrf"): ?><div class="alert alert-danger">คำขอลบหมดอายุ กรุณาลองใหม่อีกครั้ง</div><?php endif; ?><?php if (!$account_can_modify): ?><div class="alert alert-info"><i class="bi bi-shield-lock me-1"></i>บัญชีอยู่ระหว่างรอผู้ดูแลกำหนดทีมและสิทธิ์ จึงยังไม่สามารถดูข้อมูลงานได้</div><?php endif; ?><?php if ($report_update_error !== ""): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($report_update_error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?><div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-3 mb-4"><div><h1 class="page-heading h3 fw-bold mb-1">Report</h1><p class="page-subtitle mb-0">Task List สำหรับค้นหา ติดตาม และจัดการงาน IT / AV</p></div><a class="btn btn-primary align-self-start align-self-lg-auto" href="../task_input/"><i class="bi bi-plus-lg me-2"></i>บันทึกงานใหม่</a></div>
<?php if ($report_filter_error !== ""): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($report_filter_error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>
<section class="report-toolbar mb-4" aria-label="เลือกทีม">
    <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
        <div>
            <div class="small fw-bold text-muted mb-2">เลือกทีม</div>
            <nav class="report-team-switch" aria-label="เลือกทีมใน Report">
                    <?php if (can_manage_users()): ?>
                        <a class="report-team-link<?php echo $report_filter_department === "" ? " active" : ""; ?>" href="<?php echo htmlspecialchars($report_team_url(""), ENT_QUOTES, "UTF-8"); ?>"><i class="bi bi-grid"></i>ทุกทีม</a>
                    <?php endif; ?>
                    <?php foreach ($departments as $department_option): ?>
                        <?php
                        // USER sees only its own team button; SUPER sees every team but no "all";
                        // the data scope is still enforced server-side regardless of the links.
                        $team_visible = $report_can_filter_team || strtoupper($department_option) === strtoupper($report_department);
                        if (!$team_visible) continue;
                        $team_active = $report_can_filter_team
                            ? $report_filter_department === $department_option
                            : strtoupper($department_option) === strtoupper($report_department);
                        ?>
                        <a class="report-team-link<?php echo $team_active ? " active" : ""; ?>" href="<?php echo htmlspecialchars($report_team_url($department_option), ENT_QUOTES, "UTF-8"); ?>"><i class="bi <?php echo $department_option === "IT" ? "bi-pc-display" : "bi-camera-video"; ?>"></i><?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?></a>
                    <?php endforeach; ?>
            </nav>
        </div>
    </div>
</section>
<section class="row g-4 mb-4"><div class="col-sm-6 col-xl-3"><article class="card form-card h-100"><div class="card-body d-flex align-items-center"><span class="report-kpi-icon report-kpi-total d-inline-flex align-items-center justify-content-center me-3"><i class="bi bi-card-checklist"></i></span><div><div class="text-muted small fw-semibold">งานทั้งหมด</div><div class="page-heading h3 fw-bold mb-0"><?php echo $counts["total"]; ?></div></div></div></article></div><div class="col-sm-6 col-xl-3"><article class="card form-card h-100"><div class="card-body d-flex align-items-center"><span class="report-kpi-icon report-kpi-pending d-inline-flex align-items-center justify-content-center me-3"><i class="bi bi-hourglass-split"></i></span><div><div class="text-muted small fw-semibold">รอดำเนินการ</div><div class="page-heading h3 fw-bold mb-0"><?php echo $counts["pending"]; ?></div></div></div></article></div><div class="col-sm-6 col-xl-3"><article class="card form-card h-100"><div class="card-body d-flex align-items-center"><span class="report-kpi-icon report-kpi-progress d-inline-flex align-items-center justify-content-center me-3"><i class="bi bi-tools"></i></span><div><div class="text-muted small fw-semibold">กำลังดำเนินการ</div><div class="page-heading h3 fw-bold mb-0"><?php echo $counts["in_progress"]; ?></div></div></div></article></div><div class="col-sm-6 col-xl-3"><article class="card form-card h-100"><div class="card-body d-flex align-items-center"><span class="report-kpi-icon report-kpi-completed d-inline-flex align-items-center justify-content-center me-3"><i class="bi bi-check-circle-fill"></i></span><div><div class="text-muted small fw-semibold">เสร็จสิ้น</div><div class="page-heading h3 fw-bold mb-0"><?php echo $counts["completed"]; ?></div></div></div></article></div></section>
<section class="card form-card report-list-card">
    <div class="card-header report-list-header d-flex align-items-start justify-content-between gap-3">
        <div>
            <h2 class="section-title report-title d-flex align-items-center gap-2 mb-1"><span class="section-icon report-title-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-table"></i></span><span>รายการงาน</span></h2>
            <div class="text-muted small" id="reportFilteredCount">แสดง <?php echo $report_visible_start; ?>-<?php echo $report_visible_end; ?> จากทั้งหมด <?php echo $report_filtered_total; ?> รายการ</div>
        </div>
    </div>
    <div class="report-list-controls" aria-label="ค้นหาและกรองรายการงาน">
        <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-3">
            <form class="report-search-form" id="reportSearchForm" method="get" action="index.php" role="search">
                <label class="form-label mb-1" for="reportSearchInput">ค้นหางาน</label>
                <div class="input-group report-search-group">
                    <span class="input-group-text" aria-hidden="true"><i class="bi bi-search"></i></span>
                    <input type="search" class="form-control report-search" id="reportSearchInput" name="q" value="<?php echo htmlspecialchars($report_search, ENT_QUOTES, "UTF-8"); ?>" placeholder="ชื่องาน ผู้รับผิดชอบ สถานที่ หรือรายละเอียด" aria-describedby="reportSearchHelp" autocomplete="off">
                    <?php if ($report_search !== ""): ?><a class="btn btn-outline-secondary report-search-clear" href="<?php echo htmlspecialchars($report_filter_url_without("q"), ENT_QUOTES, "UTF-8"); ?>" aria-label="ล้างคำค้น"><i class="bi bi-x-lg"></i></a><?php endif; ?>
                    <button class="btn btn-primary" type="submit" id="reportSearchButton">ค้นหา</button>
                </div>
                <input type="hidden" name="department" value="<?php echo htmlspecialchars($report_filter_department, ENT_QUOTES, "UTF-8"); ?>">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($report_filter_status, ENT_QUOTES, "UTF-8"); ?>">
                <input type="hidden" name="category" value="<?php echo htmlspecialchars($report_filter_category, ENT_QUOTES, "UTF-8"); ?>">
                <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($report_filter_start, ENT_QUOTES, "UTF-8"); ?>">
                <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($report_filter_end, ENT_QUOTES, "UTF-8"); ?>">
                <input type="hidden" name="per_page" value="<?php echo $report_page_size; ?>">
                <div class="form-text" id="reportSearchHelp">ค้นหาจากข้อมูลในระบบ แล้วแสดงผลผ่าน Server-side Filter</div>
            </form>
            <div class="report-header-actions d-flex flex-wrap align-items-center gap-2">
                <button class="filter-toggle btn btn-outline-secondary position-relative" type="button" data-bs-toggle="modal" data-bs-target="#reportFilterModal" aria-label="เปิดตัวกรองเพิ่มเติม"><i class="bi bi-sliders2 me-1"></i><span class="filter-button-label">ตัวกรองเพิ่มเติม</span><?php if ($report_advanced_filter_count > 0): ?><span class="report-filter-count"><?php echo $report_advanced_filter_count; ?></span><?php endif; ?></button>
                <div class="d-flex align-items-center gap-2 report-page-size"><label class="small text-muted mb-0" for="reportRowsPerPage">แสดง</label><select class="form-select report-rows-select" id="reportRowsPerPage" aria-label="จำนวนรายการต่อหน้า"><?php foreach ($allowed_page_sizes as $page_size): ?><option value="<?php echo $page_size; ?>"<?php echo $report_page_size === $page_size ? " selected" : ""; ?>><?php echo $page_size; ?></option><?php endforeach; ?></select><span class="small text-muted text-nowrap">รายการ/หน้า</span></div>
            </div>
        </div>
        <?php if ($report_active_filters): ?>
            <div class="report-active-filters d-flex flex-wrap align-items-center gap-2" aria-label="เงื่อนไขที่กำลังใช้งาน">
                <span class="small fw-semibold text-muted">กำลังใช้:</span>
                <?php foreach ($report_active_filters as [$filter_key, $filter_name, $filter_value]): ?>
                    <a class="report-filter-chip text-decoration-none" href="<?php echo htmlspecialchars($report_filter_url_without($filter_key), ENT_QUOTES, "UTF-8"); ?>" title="ลบตัวกรอง <?php echo htmlspecialchars($filter_name, ENT_QUOTES, "UTF-8"); ?>"><strong><?php echo htmlspecialchars($filter_name, ENT_QUOTES, "UTF-8"); ?>:</strong>&nbsp;<?php echo htmlspecialchars($filter_value, ENT_QUOTES, "UTF-8"); ?><i class="bi bi-x ms-1" aria-hidden="true"></i></a>
                <?php endforeach; ?>
                <a class="report-filter-reset text-decoration-none" href="index.php">ล้างทั้งหมด</a>
            </div>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th class="ps-4 py-3 report-mobile-hidden">ลำดับ</th><th class="report-mobile-hidden">วันที่สร้าง</th><th>ชื่องาน</th><th class="report-mobile-hidden">ทีม</th><th>สถานะ</th><th class="report-mobile-hidden">ผู้รับผิดชอบ</th><th class="pe-4 text-end">การจัดการ</th></tr></thead>
            <tbody id="reportTableBody">
                <?php if (!$task_rows): ?><tr><td colspan="7" class="text-center text-muted py-4">ไม่พบงานตามตัวกรองที่เลือก</td></tr><?php endif; ?>
                <?php foreach ($task_rows as $task_index => $task): ?>
                    <?php [$label, $class] = status_meta($task["status"]); $can_edit = can_edit_task($task); $can_delete = can_delete_task($task); ?>
                    <?php $display_sequence = $report_offset + $task_index + 1; ?>
                    <tr data-search="<?php echo htmlspecialchars(implode(" ", [$task["title"], $task["department"], $task["category"], $problem_category_options[$task["category"]] ?? "", $task["location"], $task["problem"], $task["solution"], $task["created_by_name"]]), ENT_QUOTES, "UTF-8"); ?>" data-title="<?php echo htmlspecialchars($task["title"], ENT_QUOTES, "UTF-8"); ?>" data-department="<?php echo htmlspecialchars($task["department"], ENT_QUOTES, "UTF-8"); ?>" data-status="<?php echo htmlspecialchars($task["status"], ENT_QUOTES, "UTF-8"); ?>" data-category="<?php echo htmlspecialchars($task["category"], ENT_QUOTES, "UTF-8"); ?>" data-created-date="<?php echo htmlspecialchars(substr($task["created_at"], 0, 10), ENT_QUOTES, "UTF-8"); ?>">
                        <td class="ps-4 fw-semibold report-mobile-hidden"><?php echo $display_sequence; ?></td>
                        <td class="report-mobile-hidden"><?php echo thai_date_time($task["created_at"]); ?></td>
                        <td><div class="fw-semibold"><?php echo htmlspecialchars($task["title"], ENT_QUOTES, "UTF-8"); ?></div><div class="report-mobile-meta d-md-none">ลำดับ <?php echo $display_sequence; ?> · <?php echo htmlspecialchars($task["department"], ENT_QUOTES, "UTF-8"); ?></div></td>
                        <td class="report-mobile-hidden"><span class="badge text-bg-light border"><?php echo htmlspecialchars($task["department"], ENT_QUOTES, "UTF-8"); ?></span></td>
                        <td><span class="badge rounded-pill <?php echo $class; ?>"><?php echo $label; ?></span><?php if ($report_can_filter_team && (int) $task["is_visible"] === 0 && can_manage_users()): ?> <span class="badge rounded-pill text-bg-dark" title="งานนี้ถูกซ่อนจากทีมอื่น และไม่นับใน KPI ของทีม"><i class="bi bi-eye-slash me-1"></i>ซ่อนอยู่</span><?php endif; ?></td>
                        <td class="report-mobile-hidden"><?php echo htmlspecialchars($task["created_by_name"], ENT_QUOTES, "UTF-8"); ?></td>
                        <td class="pe-4 text-end"><div class="report-row-actions d-inline-flex gap-1"><button class="btn btn-sm btn-outline-primary report-detail-task" type="button" data-task-id="<?php echo $task["id"]; ?>" aria-label="ดูรายละเอียด <?php echo htmlspecialchars($task["title"], ENT_QUOTES, "UTF-8"); ?>"><i class="bi bi-eye"></i><span class="action-label ms-1">รายละเอียด</span></button><?php if ($can_edit): ?><button class="btn btn-sm btn-outline-secondary report-edit-task" type="button" data-bs-toggle="modal" data-bs-target="#reportEditTaskModal" data-edit-task-id="<?php echo $task["id"]; ?>" aria-controls="reportEditTaskModal" aria-label="แก้ไข <?php echo htmlspecialchars($task["title"], ENT_QUOTES, "UTF-8"); ?>"><i class="bi bi-pencil"></i><span class="action-label ms-1">แก้ไข</span></button><?php endif; ?><?php if (can_manage_users()): ?><form method="post" action="" class="m-0 d-inline"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($report_task_csrf, ENT_QUOTES, "UTF-8"); ?>"><input type="hidden" name="action" value="toggle_visibility"><input type="hidden" name="task_id" value="<?php echo (int) $task["id"]; ?>"><button class="btn btn-sm <?php echo (int) $task["is_visible"] === 1 ? "btn-outline-dark" : "btn-success"; ?> report-toggle-visibility" type="submit" aria-label="<?php echo (int) $task["is_visible"] === 1 ? "ซ่อน" : "เปิดให้เห็น"; ?> <?php echo htmlspecialchars($task["title"], ENT_QUOTES, "UTF-8"); ?>" title="<?php echo (int) $task["is_visible"] === 1 ? "ซ่อนงานจากทีมอื่น" : "เปิดให้ทีมอื่นมองเห็น"; ?>"><i class="bi bi-<?php echo (int) $task["is_visible"] === 1 ? "eye-slash" : "eye"; ?>"></i><span class="action-label ms-1"><?php echo (int) $task["is_visible"] === 1 ? "ซ่อน" : "เปิดให้เห็น"; ?></span></button></form><?php endif; ?><?php if ($can_delete): ?><button class="btn btn-sm btn-outline-danger report-delete-task" type="button" data-task-id="<?php echo $task["id"]; ?>" aria-label="ลบ <?php echo htmlspecialchars($task["title"], ENT_QUOTES, "UTF-8"); ?>"><i class="bi bi-trash"></i><span class="action-label ms-1">ลบ</span></button><?php endif; ?></div></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($report_total_pages > 1): ?>
        <?php $page_start = max(1, $report_page - 2); $page_end = min($report_total_pages, $report_page + 2); ?>
        <div class="d-flex flex-column flex-sm-row align-items-center justify-content-between gap-2 p-3 border-top">
            <span class="small text-muted">หน้า <?php echo $report_page; ?> จาก <?php echo $report_total_pages; ?></span>
            <nav aria-label="การแบ่งหน้ารายการงาน"><ul class="pagination pagination-sm mb-0" id="reportPagination">
                <li class="page-item<?php echo $report_page <= 1 ? " disabled" : ""; ?>"><?php if ($report_page <= 1): ?><span class="page-link">ก่อนหน้า</span><?php else: ?><a class="page-link" href="<?php echo htmlspecialchars($report_page_url($report_page - 1), ENT_QUOTES, "UTF-8"); ?>">ก่อนหน้า</a><?php endif; ?></li>
                <?php if ($page_start > 1): ?><li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($report_page_url(1), ENT_QUOTES, "UTF-8"); ?>">1</a></li><?php if ($page_start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?><?php endif; ?>
                <?php for ($page_number = $page_start; $page_number <= $page_end; $page_number++): ?><li class="page-item<?php echo $page_number === $report_page ? " active" : ""; ?>"><a class="page-link" href="<?php echo htmlspecialchars($report_page_url($page_number), ENT_QUOTES, "UTF-8"); ?>"><?php echo $page_number; ?></a></li><?php endfor; ?>
                <?php if ($page_end < $report_total_pages): ?><?php if ($page_end < $report_total_pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?><li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($report_page_url($report_total_pages), ENT_QUOTES, "UTF-8"); ?>"><?php echo $report_total_pages; ?></a></li><?php endif; ?>
                <li class="page-item<?php echo $report_page >= $report_total_pages ? " disabled" : ""; ?>"><?php if ($report_page >= $report_total_pages): ?><span class="page-link">ถัดไป</span><?php else: ?><a class="page-link" href="<?php echo htmlspecialchars($report_page_url($report_page + 1), ENT_QUOTES, "UTF-8"); ?>">ถัดไป</a><?php endif; ?></li>
            </ul></nav>
        </div>
    <?php else: ?>
        <div class="small text-muted text-center p-3 border-top">ทั้งหมด <?php echo $report_filtered_total; ?> รายการ</div>
    <?php endif; ?>
</section>
<div class="modal fade report-edit-modal" id="reportEditTaskModal" tabindex="-1" aria-labelledby="reportEditTaskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" action="" id="reportEditTaskForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="task_id" id="reportEditTaskId">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($report_task_csrf, ENT_QUOTES, "UTF-8"); ?>">
                <div class="modal-header">
                    <div class="report-edit-heading-wrap d-flex align-items-center gap-3">
                        <span class="report-edit-title-icon d-inline-flex align-items-center justify-content-center" aria-hidden="true"><i class="bi bi-pencil-square"></i></span>
                        <div>
                            <h2 class="modal-title fs-5" id="reportEditTaskModalLabel">แก้ไขงาน</h2>
                            <p class="small text-muted mb-0" id="reportEditTaskSubtitle">เลือกงานที่ต้องการแก้ไข</p>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body">
                    <section class="report-edit-section report-edit-section--primary">
                        <h3 class="report-edit-section-heading"><i class="bi bi-card-checklist"></i>ข้อมูลงาน</h3>
                        <div class="row g-3">
                            <div class="col-lg-8"><label class="form-label" for="reportEditTitle">ชื่องาน <span class="text-danger">*</span></label><input type="text" class="form-control" id="reportEditTitle" name="title" required></div>
                            <div class="col-lg-4">
                                <label class="form-label" for="reportEditDepartment">ทีม <span class="text-danger">*</span></label>
                                <?php if (current_role() === "ADMIN"): ?>
                                    <select class="form-select" id="reportEditDepartment" name="department"><?php foreach ($departments as $department_option): ?><option value="<?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select>
                                <?php else: ?>
                                    <input type="text" class="form-control bg-light" id="reportEditDepartment" readonly>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6"><label class="form-label" for="reportEditResponsible">ชื่อผู้รับผิดชอบ</label><input type="text" class="form-control" id="reportEditResponsible" name="responsible_name" placeholder="หากไม่ระบุ ระบบจะแสดงทีม"></div>
                            <div class="col-md-6"><label class="form-label" for="reportEditLocation">สถานที่</label><select class="form-select" id="reportEditLocation" name="location"><option value="">ไม่ระบุ</option><?php foreach ($report_location_options as $location_option): ?><option value="<?php echo htmlspecialchars($location_option, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($location_option, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?><option value="__other__">อื่นๆ</option></select></div>
                            <div class="col-md-6 d-none" id="reportEditOtherLocationGroup"><label class="form-label" for="reportEditOtherLocation">ระบุสถานที่อื่น</label><input type="text" class="form-control" id="reportEditOtherLocation" name="other_location"></div>
                            <div class="col-md-6<?php echo $can_control_task_status ? "" : " d-none"; ?>" id="reportEditStatusSelectGroup"><label class="form-label" for="reportEditStatus">สถานะ <span class="text-danger">*</span></label><select class="form-select" id="reportEditStatus" name="status"><?php foreach ($report_status_options as $status_value => $status_label): ?><option value="<?php echo htmlspecialchars($status_value, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($status_label, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6<?php echo $can_control_task_status ? " d-none" : ""; ?>" id="reportEditAutoStatusGroup"><label class="form-label">สถานะ</label><div class="task-auto-status"><span class="badge rounded-pill status-pending" id="reportEditAutoStatusBadge">รอดำเนินการ</span><small id="reportEditAutoStatusHint">สถานะถูกกำหนดโดยระบบ</small></div></div>
                            <div class="col-md-6" id="reportEditCategoryGroup"><label class="form-label" for="reportEditCategory">ประเภทปัญหา</label><select class="form-select" id="reportEditCategory" name="category"><option value="-">ไม่ระบุ</option><?php foreach ($problem_category_options as $category_value => $category_label): ?><option value="<?php echo htmlspecialchars($category_value, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($category_label, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div>
                        </div>
                    </section>
                    <section class="report-edit-section report-edit-section--details">
                        <h3 class="report-edit-section-heading" id="reportEditDetailHeading"><i class="bi bi-file-earmark-text"></i>รายละเอียดและการดำเนินงาน</h3>
                        <div class="row g-3">
                            <div class="col-md-6" id="reportEditWorkDescriptionGroup"><label class="form-label" for="reportEditWorkDescription" id="reportEditWorkDescriptionLabel">รายละเอียดงาน</label><textarea class="form-control" id="reportEditWorkDescription" name="work_description" rows="3"></textarea><div class="form-text" id="reportEditWorkDescriptionHint"></div></div>
                            <div class="col-md-6" id="reportEditWorkActionGroup"><label class="form-label" for="reportEditWorkAction" id="reportEditWorkActionLabel">การดำเนินงาน</label><textarea class="form-control" id="reportEditWorkAction" name="work_action" rows="3"></textarea><div class="form-text" id="reportEditWorkActionHint">งาน AV จะเปลี่ยนเป็น “เสร็จสิ้น” อัตโนมัติเมื่อกรอกการดำเนินงานหรือเวลาสิ้นสุด</div></div>
                            <div class="col-12 d-none" id="reportEditEquipmentGroup"><div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2"><label class="form-label mb-0">อุปกรณ์ที่ใช้งาน <span class="report-edit-optional">ถ้ามี</span></label><button class="btn btn-sm btn-outline-primary" type="button" id="reportEditAddEquipment"><i class="bi bi-plus-lg me-1"></i>เพิ่มอุปกรณ์</button></div><div id="reportEditEquipmentRows"></div><div class="form-text">รายการเดิมจะถูกเก็บไว้เพื่อรักษาประวัติงาน สามารถเพิ่มอุปกรณ์หรือปรับจำนวนได้</div></div>
                            <div class="col-md-6"><label class="form-label" for="reportEditProblem">ปัญหาที่พบ <span class="text-danger d-none" id="reportEditProblemRequired">*</span><span class="report-edit-optional d-none" id="reportEditProblemOptional">ถ้ามี</span></label><textarea class="form-control" id="reportEditProblem" name="problem" rows="3"></textarea></div>
                            <div class="col-md-6"><label class="form-label" for="reportEditSolution">วิธีแก้ไขปัญหา <span class="report-edit-optional d-none" id="reportEditSolutionOptional">ถ้ามี</span></label><textarea class="form-control" id="reportEditSolution" name="solution" rows="3"></textarea><div class="form-text" id="reportEditSolutionHint">งาน IT จะเปลี่ยนเป็น “เสร็จสิ้น” อัตโนมัติเมื่อกรอกวิธีแก้ไข</div></div>
                            <div class="col-12"><label class="form-label" for="reportEditRemark">หมายเหตุ</label><textarea class="form-control" id="reportEditRemark" name="remark" rows="2"></textarea></div>
                        </div>
                    </section>
                    <section class="report-edit-section report-edit-section--time mb-0">
                        <h3 class="report-edit-section-heading" id="reportEditTimeHeading"><i class="bi bi-clock-history"></i>ระยะเวลาการดำเนินงาน</h3>
                        <div class="row g-3">
                            <div class="col-md-6 col-lg-3"><label class="form-label" for="reportEditStartDate" id="reportEditStartDateLabel">วันเริ่มดำเนินการ <span class="text-danger">*</span></label><input type="text" class="form-control date-picker" id="reportEditStartDate" name="start_date" required></div>
                            <div class="col-md-6 col-lg-3"><label class="form-label" for="reportEditStartTime" id="reportEditStartTimeLabel">เวลาเริ่มงาน <span class="text-danger">*</span></label><input type="text" class="form-control time-picker" id="reportEditStartTime" name="start_work_time" required></div>
                            <div class="col-md-6 col-lg-3"><label class="form-label" for="reportEditFinishDate" id="reportEditFinishDateLabel">วันที่สิ้นสุด</label><input type="text" class="form-control date-picker" id="reportEditFinishDate" name="finish_date"></div>
                            <div class="col-md-6 col-lg-3"><label class="form-label" for="reportEditFinishTime" id="reportEditFinishTimeLabel">เวลาสิ้นสุดงาน</label><input type="text" class="form-control time-picker" id="reportEditFinishTime" name="finish_work_time"></div>
                        </div>
                    </section>
                    <section class="report-edit-section mb-0">
                        <h3 class="report-edit-section-heading"><i class="bi bi-images"></i>รูปภาพประกอบงาน</h3>
                        <div class="row g-3">
                            <div class="col-12" id="reportEditExistingImages"></div>
                            <div class="col-12"><label class="form-label" for="reportEditTaskImages">เพิ่มรูปภาพใหม่ <span class="report-edit-optional">ถ้ามี</span></label><input type="file" class="form-control" id="reportEditTaskImages" name="task_images[]" multiple accept="image/jpeg,image/png,image/webp"><div class="form-text">JPG, PNG หรือ WebP ไม่เกิน 5 MB ต่อรูป (สูงสุด 5 รูปต่อครั้ง) · เลือกถูกในรูปเดิมด้านบนเพื่อลบออกจากงาน</div></div>
                        </div>
                    </section>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade report-filter-modal" id="reportFilterModal" tabindex="-1" aria-labelledby="reportFilterModalLabel" aria-hidden="true" data-bs-backdrop="true" data-bs-keyboard="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="report-filter-title-wrap d-flex align-items-center gap-3">
                    <span class="report-filter-title-icon d-inline-flex align-items-center justify-content-center" aria-hidden="true"><i class="bi bi-sliders2"></i></span>
                    <div>
                        <h2 class="modal-title fs-5" id="reportFilterModalLabel">ตัวกรองรายการงาน</h2>
                        <p class="small text-muted mb-0 mt-1">เลือกเฉพาะเงื่อนไขที่จำเป็น แล้วกดใช้ตัวกรอง</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <section class="report-filter-section" aria-labelledby="reportWorkFilterHeading">
                    <h3 class="report-filter-heading" id="reportWorkFilterHeading"><i class="bi bi-card-checklist"></i>ข้อมูลงาน</h3>
                    <div class="row g-3">
                        <?php if ($report_can_filter_team): ?>
                            <div class="col-md-6"><label class="form-label" for="reportDepartmentFilter">ทีม</label><select class="form-select" id="reportDepartmentFilter"><?php if (can_manage_users()): ?><option value="">ทุกทีม</option><?php endif; ?><?php foreach ($departments as $item): ?><option value="<?php echo htmlspecialchars($item, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($item, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div>
                        <?php endif; ?>
                        <div class="col-md-6"><label class="form-label" for="reportStatusFilter">สถานะ</label><select class="form-select" id="reportStatusFilter"><option value="">ทุกสถานะ</option><?php foreach ($report_status_options as $value => $item): ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($item, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div>
                        <div class="col-12"><label class="form-label" for="reportCategoryFilter">ประเภทปัญหา</label><select class="form-select" id="reportCategoryFilter"><option value="">ทุกประเภท</option><?php foreach ($problem_category_options as $value => $item): ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($item, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div>
                    </div>
                </section>
                <section class="report-filter-section mb-0" aria-labelledby="reportDateFilterHeading">
                    <h3 class="report-filter-heading" id="reportDateFilterHeading"><i class="bi bi-calendar3"></i>ช่วงวันที่สร้างงาน</h3>
                    <p class="small text-muted mb-3">เลือกวันเริ่มต้น วันสิ้นสุด หรือเลือกทั้งสองวันเพื่อกำหนดช่วง</p>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="reportStartDate">ตั้งแต่วันที่</label><input type="text" class="form-control date-picker" id="reportStartDate" placeholder="วว/ดด/ปปปป"></div>
                        <div class="col-md-6"><label class="form-label" for="reportEndDate">ถึงวันที่</label><input type="text" class="form-control date-picker" id="reportEndDate" placeholder="วว/ดด/ปปปป"></div>
                    </div>
                </section>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-danger me-auto" id="resetReportFilters"><i class="bi bi-arrow-counterclockwise me-1"></i>ล้างตัวกรอง</button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-primary" id="applyReportFilters"><i class="bi bi-check2 me-1"></i>ใช้ตัวกรอง</button>
            </div>
        </div>
    </div>
</div>
<?php // One detail modal and one delete confirmation are filled dynamically from the task payload below. ?>
<div class="modal fade task-details-modal" id="taskDetailModal" tabindex="-1" aria-labelledby="taskDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="task-details-heading">
                    <span class="task-details-kicker"><i class="bi bi-card-text me-1"></i>รายละเอียดงาน</span>
                    <h2 class="modal-title" id="taskDetailModalLabel"></h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body" id="taskDetailBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                <button class="btn btn-primary report-edit-task d-none" type="button" id="taskDetailEditButton" data-edit-task-id="0"><i class="bi bi-pencil-square me-1"></i>แก้ไขงาน</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="deleteTaskModal" tabindex="-1" aria-labelledby="deleteTaskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title fs-5" id="deleteTaskModalLabel">ยืนยันการลบงาน</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">คุณต้องการลบงาน <strong id="deleteTaskTitle"></strong> หรือไม่ ?</div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button><form method="post" action="" class="m-0"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($report_task_csrf, ENT_QUOTES, "UTF-8"); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="task_id" id="deleteTaskId"><button type="submit" class="btn btn-danger">ลบงาน</button></form></div>
    </div></div>
</div>
</main></div>
<div class="task-image-lightbox" id="taskImageLightbox" role="dialog" aria-modal="true" aria-label="ดูรูปภาพประกอบงาน">
    <div class="task-lightbox-stage" data-lightbox-stage>
        <img src="" alt="" data-lightbox-img>
    </div>
    <div class="task-lightbox-toolbar">
        <button class="btn task-lightbox-btn" type="button" data-lightbox-zoom-in aria-label="ซูมเข้า" title="ซูมเข้า"><i class="bi bi-zoom-in"></i></button>
        <button class="btn task-lightbox-btn" type="button" data-lightbox-zoom-out aria-label="ซูมออก" title="ซูมออก"><i class="bi bi-zoom-out"></i></button>
        <button class="btn task-lightbox-btn" type="button" data-lightbox-reset aria-label="ขนาดเดิม" title="ขนาดเดิม"><i class="bi bi-arrows-angle-expand"></i></button>
        <button class="btn task-lightbox-btn task-lightbox-close" type="button" data-lightbox-close aria-label="ปิด" title="ปิด"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="task-lightbox-caption" data-lightbox-caption></div>
</div>
<style>
    /* Report-page-only refinements: compact, bright, and focused on task records. */
    .app-shell { background: #f4f6f9; }
    .report-page { padding: 1.5rem !important; }
    .report-page .page-heading { font-size: 1.65rem; }
    .report-page .page-subtitle { font-size: .9rem; }
    .report-page .form-card { border: 1px solid #dbe3ec; border-radius: .75rem; background: #fff; box-shadow: 0 7px 18px rgba(15, 23, 42, .08); }
    .report-page .form-card .card-header { min-height: auto; padding: .8rem 1rem; border-color: #dbe3ec; background: #f8fafc; }
    .report-page .form-card .card-body { padding: 1rem 1.25rem; }
    .report-page .section-title { font-size: 1.08rem; }
    .report-page .form-label { margin-bottom: .3rem; font-size: .9rem; }
    .report-page .form-control, .report-page .form-select { min-height: 38px; font-size: .9rem; }
    .report-page .btn { font-size: .875rem; }
    .report-page .badge { padding: .36em .62em; font-size: .72rem; font-weight: 700; }
    .report-page .row.g-4 { --bs-gutter-y: 1rem; --bs-gutter-x: 1rem; }
    .report-page .summary-card .card-body, .report-page .row > div > .form-card .card-body { padding: 1rem 1.15rem; }
    .report-page .summary-card .text-muted { font-size: .76rem !important; }
    .report-page .summary-card .h3 { font-size: 1.45rem; }
    .report-page .report-kpi-icon { width: 52px; height: 52px; flex: 0 0 52px; border-radius: .8rem; font-size: 1.4rem; }
    .report-page .report-kpi-total { color: #1769c2; background: #e8f2fd; }
    .report-page .report-kpi-pending { color: #b7791f; background: #fff5dd; }
    .report-page .report-kpi-progress { color: #5b4db1; background: #eeeafe; }
    .report-page .report-kpi-completed { color: #21805c; background: #e3f6ed; }
    .report-filter-card { background: #fbfcfe !important; }
    .report-filter-card .card-header { background: #f1f5f9 !important; }
    .filter-toggle { width: 32px; height: 32px; padding: 0; color: #4b647d; border-color: #cbd8e6; background: #fff; }
    .filter-toggle:hover, .filter-toggle:focus { color: #1f4f7d; border-color: #aac3dc; background: #eef5fc; box-shadow: 0 0 0 .18rem rgba(23, 105, 194, .11); }
    .report-list-card { overflow: hidden; }
    .report-list-card .card-header { position: relative; justify-content: center; background: #fff; }
    .report-record-count { position: absolute; right: 1rem; color: #66788a; font-size: .82rem; }
    .report-page .table { margin-bottom: 0; font-size: .875rem; }
    .report-page .table thead th { position: sticky; top: 72px; z-index: 2; padding-top: .68rem; padding-bottom: .68rem; color: #425b73; border-color: #dbe3ec; background: #eef2f7; font-size: .76rem; letter-spacing: .025em; white-space: nowrap; }
    .report-page .table td { padding-top: .62rem; padding-bottom: .62rem; color: #354b61; border-color: #e5ebf1; }
    .report-page .table tbody tr:nth-of-type(even) > * { background: #fbfcfe; }
    .report-page .table-hover tbody tr:hover > * { color: #263f57; background: #f7faff; }
    .report-page .status-pending { color: #8a5a10; background: #fff2d9; }
    .report-page .status-progress { color: #4e3e97; background: #eeeaff; }
    .report-page .status-completed { color: #176a4a; background: #def4e9; }
    .report-page .status-cancelled { color: #8c3941; background: #fce8ea; }
    @media (max-width: 575.98px) { .report-page { padding: 1rem !important; } .report-page .card-body { padding: .9rem !important; } .report-list-header { align-items: flex-start !important; } .report-header-side { align-items: flex-end !important; } .report-search { width: 125px; } .report-record-count { display: block; } }
    /* Soft report KPI colors distinguish each task state without overpowering the table. */
    .report-page > section.row.g-4 > div:nth-child(1) .form-card,
    .report-page > section.row.g-4 > div:nth-child(2) .form-card,
    .report-page > section.row.g-4 > div:nth-child(3) .form-card,
    .report-page > section.row.g-4 > div:nth-child(4) .form-card { background: #fff; border-color: #d9e3ee; box-shadow: 0 8px 20px rgba(26, 57, 89, .10); }    /* Align Report components with the shared Dashboard and Task Input design language. */
    .report-page .form-card { border-color: #d9e3ee; border-radius: .9rem; background: #fbfcfe; box-shadow: 0 8px 24px rgba(26, 57, 89, .10); }
    .report-page .form-card .card-header { padding: 1.1rem 1.5rem; border-bottom-color: #d9e3ee; background: #f7f9fc; }
    .report-page .form-card .card-body { padding: 1.25rem 1.5rem; }
    .report-page .section-title { font-size: 1.22rem; font-weight: 700; }
    .report-page .form-label { font-size: 1.02rem; font-weight: 600; }
    .report-page .form-control, .report-page .form-select { min-height: 44px; font-size: 1rem; }
    .report-page .btn { font-size: 1rem; font-weight: 600; }
    .report-page .badge { font-size: .86rem; }
    .report-page .table { font-size: 1rem; }
    .report-page .table thead th { font-size: .9rem; }
    .report-list-card { height: auto; min-height: 0; overflow: visible; }
    .report-list-header { align-items: flex-start !important; flex-wrap: wrap; padding-top: .85rem !important; padding-bottom: .8rem !important; }
    .report-title-icon { width: 34px; height: 34px; color: #1769c2; border-radius: .6rem; background: #e8f2fd; box-shadow: 0 3px 9px rgba(23, 105, 194, .14); font-size: 1rem; }
    .report-header-side { min-width: 0; margin-top: -.15rem; }
    .report-header-actions { min-width: 0; }
    .report-search-group { width: 220px; }
    .report-search-group .input-group-text { color: #52677f; border-color: #cbd8e6; background: #fff; }
    .report-search-group .form-control { min-height: 34px; border-left: 0; }
    .filter-toggle { flex: 0 0 34px; height: 34px; }
    .report-record-count { display: block; width: 100%; padding-right: .15rem; text-align: right; }
    @media (max-width: 575.98px) { .report-list-header { flex-direction: column; gap: .6rem !important; } .report-header-side { width: 100%; margin-top: 0; align-items: flex-start !important; } .report-search-group { width: min(100%, 250px); } .report-record-count { text-align: left; } }    /* Keep the report header in normal document flow so controls never overlap. */
    .report-list-card .report-list-header { position: static; justify-content: space-between; align-items: flex-start !important; min-height: 92px; padding: 1rem 1.5rem 1.1rem !important; }
    .report-header-side { display: flex; flex-direction: column; align-items: flex-end; gap: .42rem !important; margin-top: -.2rem; }
    .report-header-actions { display: flex; align-items: center; gap: .5rem !important; }
    .report-record-count { position: static !important; right: auto !important; display: block; width: auto; margin: 0; padding: 0; align-self: flex-end; text-align: right; line-height: 1.25; }
    .report-list-card .table-responsive { overflow-x: auto; overflow-y: visible; }
    @media (max-width: 575.98px) { .report-list-card .report-list-header { min-height: 0; padding: 1rem !important; } .report-header-side { width: 100%; align-items: flex-start; margin-top: 0; } .report-record-count { align-self: flex-start; text-align: left; } }    /* Let Report table headings scroll with rows so they never cover task records. */
    .report-page .table thead th { position: static; top: auto; z-index: auto; }    /* Compact pagination controls keep the existing Report header balanced. */
    .report-rows-select { width: 74px; min-height: 32px !important; padding-top: .15rem; padding-bottom: .15rem; }
    .report-page-size { align-self: flex-end; }
    #reportPagination .page-link { min-width: 34px; text-align: center; }

    /* Task-list controls stay visible and usable without opening the advanced filter. */
    .report-toolbar {
        padding: 1rem 1.1rem;
        border: 1px solid #d9e3ee;
        border-radius: .9rem;
        background: #fff;
        box-shadow: 0 8px 24px rgba(26, 57, 89, .08);
    }
    .report-team-switch { display: flex; flex-wrap: wrap; gap: .55rem; }
    .report-team-link {
        display: inline-flex;
        min-height: 42px;
        align-items: center;
        gap: .5rem;
        padding: .55rem .85rem;
        color: #48627b;
        border: 1px solid #cbd8e6;
        border-radius: .7rem;
        background: #fff;
        font-weight: 600;
        text-decoration: none;
    }
    .report-team-link:hover, .report-team-link:focus {
        color: #174f84;
        border-color: #9dbbd7;
        background: #f0f6fc;
    }
    .report-team-link.active {
        color: #fff;
        border-color: #1769c2;
        background: #1769c2;
        box-shadow: 0 4px 12px rgba(23, 105, 194, .2);
    }
    .report-filter-chip {
        display: inline-flex;
        min-height: 30px;
        align-items: center;
        padding: .25rem .6rem;
        color: #3f5871;
        border: 1px solid #d8e3ee;
        border-radius: 999px;
        background: #f5f8fb;
        font-size: .82rem;
    }
    .report-list-card .report-list-header {
        min-height: 0;
        align-items: center !important;
    }
    .report-header-actions { max-width: 850px; }
    .report-search-group { width: min(100%, 460px); }
    .report-search-group .form-control { min-height: 44px; }
    .filter-toggle {
        position: relative;
        width: auto;
        height: 44px;
        flex: 0 0 auto;
        padding: .55rem .8rem;
    }
    .report-filter-count {
        position: absolute;
        top: -7px;
        right: -7px;
        min-width: 21px;
        padding: .1rem .35rem;
        color: #fff;
        border: 2px solid #fff;
        border-radius: 999px;
        background: #1769c2;
        font-size: .7rem;
        line-height: 1.25;
    }
    .report-rows-select { width: 76px; min-height: 44px !important; }
    .report-mobile-meta { margin-top: .25rem; color: #718096; font-size: .78rem; }
    .report-row-actions { white-space: nowrap; }
    .report-list-controls { padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #d9e3ee; background: #f8fafc; }
    .report-search-form { width: min(100%, 680px); min-width: 0; }
    .report-search-group { width: 100%; }
    .report-search-group:focus-within { border-radius: .45rem; box-shadow: 0 0 0 .2rem rgba(23, 105, 194, .12); }
    .report-search-group:focus-within .form-control,
    .report-search-group:focus-within .input-group-text { border-color: #86b7e5; }
    .report-search-clear { display: inline-flex; align-items: center; justify-content: center; border-color: #cbd8e6; }
    .report-active-filters { margin-top: .85rem; padding-top: .85rem; border-top: 1px dashed #d6e0ea; }
    .report-filter-chip:hover, .report-filter-chip:focus { color: #174f84; border-color: #9dbbd7; background: #edf5fd; }
    .report-filter-reset { margin-left: .15rem; color: #a33a44; font-size: .82rem; font-weight: 600; }
    .report-filter-reset:hover, .report-filter-reset:focus { color: #7f1d1d; text-decoration: underline !important; }
    .report-filter-modal .modal-content { overflow: hidden; border: 1px solid #c8d8e7; border-radius: 1rem; box-shadow: 0 22px 58px rgba(15, 35, 57, .24); }
    .report-filter-modal .modal-header { align-items: center; padding: 1rem 1.25rem; border-bottom: 1px solid #cfe0ef; background: linear-gradient(120deg, #f8fbfe, #eaf3fb); }
    .report-filter-title-wrap { min-width: 0; }
    .report-filter-title-icon { width: 44px; height: 44px; flex: 0 0 44px; color: #fff; border-radius: .75rem; background: linear-gradient(135deg, #2584de, #105a9e); box-shadow: 0 6px 15px rgba(23, 105, 194, .22); font-size: 1.1rem; }
    .report-filter-modal .modal-title { color: #153b63; font-weight: 700; }
    .report-filter-modal .modal-body { padding: 1rem; background: #f2f6fa; }
    .report-filter-section { overflow: hidden; padding: 0 1rem 1rem; margin-bottom: 1rem; border: 1px solid #d7e3ee; border-radius: .8rem; background: #fff; box-shadow: 0 3px 11px rgba(30, 64, 98, .045); }
    .report-filter-section:last-child { margin-bottom: 0; }
    .report-filter-heading { display: flex; align-items: center; gap: .5rem; padding: .72rem 1rem; margin: 0 -1rem 1rem; color: #1f5688; border-bottom: 1px solid #dce8f2; background: #edf5fc; font-size: .95rem; font-weight: 700; }
    .report-filter-heading i { color: #1769c2; }
    .report-filter-modal .form-label { color: #36536f; font-weight: 700; }
    .report-filter-modal .form-control, .report-filter-modal .form-select { border-color: #c7d8e7; background-color: #fbfdff; }
    .report-filter-modal .form-control:focus, .report-filter-modal .form-select:focus { border-color: #5b9bd5; background-color: #fff; box-shadow: 0 0 0 .2rem rgba(23, 105, 194, .12); }
    .report-filter-modal .modal-footer { padding: .85rem 1.25rem; border-top: 1px solid #d2e0ec; background: #f8fafc; }
    .report-filter-modal .modal-footer .btn-primary { box-shadow: 0 5px 13px rgba(23, 105, 194, .2); }

    @media (max-width: 767.98px) {
        .report-toolbar { padding: .85rem; }
        .report-team-switch { display: grid; grid-template-columns: 1fr 1fr; }
        .report-team-link { justify-content: center; }
        .report-team-link:first-child { grid-column: 1 / -1; }
        .report-list-card .report-list-header { flex-direction: column; width: 100%; align-items: stretch !important; }
        .report-header-side, .report-header-actions { width: 100%; align-items: stretch; }
        .report-header-actions { flex-wrap: wrap; justify-content: flex-start !important; }
        .report-search-group { width: 100%; }
        .report-mobile-hidden { display: none; }
        .report-row-actions .action-label { display: none; }
        .report-row-actions .btn {
            width: 36px;
            height: 36px;
            padding: 0;
        }
        .report-page .table { min-width: 0; }
        .report-page-size { align-self: auto; }
        .report-record-count { align-self: flex-start; text-align: left; }
        .report-list-controls { padding: .9rem 1rem; }
        .report-search-form { width: 100%; }
        .report-header-actions { flex-direction: column; align-items: stretch !important; }
        .filter-toggle { width: 100%; }
        .report-page-size { justify-content: space-between; }
        .report-filter-modal .modal-footer { align-items: stretch; }
        .report-filter-modal .modal-footer .btn { flex: 1 1 auto; }
    }
</style>
<style>
    /* Task Details Modal only: hierarchy through type, spacing, and sections. */
    .task-details-modal .modal-dialog { width: auto; max-width: 960px; margin: 1rem auto; }
    .task-details-modal .modal-content { max-height: calc(100dvh - 2rem); overflow: hidden; border: 1px solid #dbe3ec; border-radius: 1rem; box-shadow: 0 20px 55px rgba(15, 23, 42, .2); }
    .task-details-modal .modal-header { flex: 0 0 auto; align-items: flex-start; padding: 1.15rem 1.5rem; border-bottom: 1px solid #e2e8f0; background: #fff; }
    .task-details-heading { min-width: 0; padding-right: 1rem; }
    .task-details-kicker { display: block; margin-bottom: .25rem; color: #1769c2; font-size: .76rem; font-weight: 700; letter-spacing: .04em; }
    .task-details-modal .modal-title { overflow-wrap: anywhere; color: #0f2942; font-size: 1.2rem; font-weight: 700; line-height: 1.35; }
    .task-details-modal .btn-close { flex: 0 0 auto; margin-top: .1rem; }
    .task-details-modal .modal-body { min-height: 0; padding: .2rem 1.25rem 1rem; overflow-y: auto; overscroll-behavior: contain; color: #334155; background: #f3f7fb; }
    .task-detail-section { overflow: hidden; padding: 0 1.1rem 1.1rem; margin: 1rem 0 0; border: 1px solid #d6e2ed; border-radius: .8rem; background: #fff; box-shadow: 0 3px 12px rgba(30, 64, 98, .055); }
    .task-detail-section:last-child { margin-bottom: 0; }
    .task-detail-section-heading { display: flex; align-items: center; gap: .55rem; padding: .78rem 1.1rem; margin: 0 -1.1rem 1rem; color: #153b63; border-bottom: 1px solid #d8e6f2; background: #eaf3fb; font-size: .94rem; font-weight: 700; }
    .task-detail-section-heading i { color: #1769c2; font-size: 1rem; }
    .task-detail-item { min-width: 0; }
    .task-detail-label { display: block; margin-bottom: .35rem; color: #64748b; font-size: .78rem; font-weight: 600; line-height: 1.35; }
    .task-detail-value { overflow-wrap: anywhere; color: #1e293b; font-size: .94rem; line-height: 1.65; }
    .task-detail-value--multiline { white-space: pre-wrap; }
    .task-detail-summary .task-detail-value { font-weight: 500; }
    .task-detail-field + .task-detail-field { margin-top: 1.1rem; padding-top: 1.1rem; border-top: 1px dashed #dbe3ec; }
    .task-detail-field .task-detail-value { white-space: normal; }
    .task-detail-section--problem { border-color: #f2d3bf; }
    .task-detail-section--problem .task-detail-section-heading { color: #9a3412; border-bottom-color: #f3d9c8; background: #fff3e9; }
    .task-detail-section--problem .task-detail-section-heading i { color: #c2410c; }
    .task-detail-section--solution { border-color: #c9e5d1; }
    .task-detail-section--solution .task-detail-section-heading { color: #166534; border-bottom-color: #d4ead9; background: #edf8f0; }
    .task-detail-section--solution .task-detail-section-heading i { color: #15803d; }
    .task-detail-empty { color: #8492a6; font-style: italic; }
    .task-detail-meta { align-items: start; }
    .task-detail-created { color: #8492a6; font-size: .78rem; }
    .task-details-modal .badge { padding: .42rem .68rem; box-shadow: none; font-weight: 600; }
    .task-details-modal .task-image-grid { margin-top: .1rem; }
    .task-details-modal .task-image-link { overflow: hidden; color: #405970; border: 1px solid #dce4ec; border-radius: .65rem; background: #fff; transition: border-color .15s ease, box-shadow .15s ease; }
    .task-details-modal .task-image-link:hover, .task-details-modal .task-image-link:focus { border-color: #8ab4dc; box-shadow: 0 6px 18px rgba(23, 105, 194, .1); }
    .task-details-modal .task-image-link img { width: 100%; height: 120px; object-fit: cover; }
    .task-details-modal .task-activity-list { margin-top: -.25rem; }
    .task-details-modal .task-activity-item { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: .75rem; padding: .85rem 0; border-bottom: 1px solid #edf1f5; }
    .task-details-modal .task-activity-item:last-child { padding-bottom: 0; border-bottom: 0; }
    .task-details-modal .task-activity-icon { display: grid; width: 32px; height: 32px; place-items: center; color: #1769c2; border-radius: 50%; background: #edf5fd; }
    .task-details-modal .task-activity-meta { margin-top: .18rem; color: #8492a6; font-size: .78rem; }
    .task-details-modal .task-activity-expandable { border-radius: .55rem; cursor: pointer; transition: background .15s ease; }
    .task-details-modal .task-activity-expandable:hover { background: #f4f8fc; }
    .task-details-modal .task-activity-chevron { display: inline-block; font-size: .8rem; color: #8492a6; transition: transform .18s ease; }
    .task-details-modal .task-activity-chevron.rotate-180 { transform: rotate(180deg); }
    .task-details-modal .task-activity-details { margin-top: .6rem; padding: .7rem .85rem; border: 1px solid #e2e9f0; border-radius: .55rem; background: #f8fafd; }
    .task-details-modal .task-activity-change { display: flex; flex-direction: column; gap: .25rem; padding: .4rem 0; }
    .task-details-modal .task-activity-change + .task-activity-change { border-top: 1px dashed #e2e9f0; }
    .task-details-modal .task-activity-change-label { color: #1769c2; font-size: .8rem; font-weight: 700; }
    .task-details-modal .task-activity-change-values { display: flex; align-items: flex-start; gap: .5rem; font-size: .88rem; word-break: break-word; }
    .task-details-modal .task-activity-change-values i { color: #8492a6; margin-top: .15rem; }
    .task-details-modal .task-activity-before { color: #9a5b15; background: #fdf3e2; border-radius: .4rem; padding: .1rem .45rem; text-decoration: line-through; }
    .task-details-modal .task-activity-after { color: #14683d; background: #e9f7ee; border-radius: .4rem; padding: .1rem .45rem; }
    .task-details-modal .task-activity-empty { color: #708398; font-size: .9rem; }
    .task-details-modal .modal-footer { flex: 0 0 auto; gap: .5rem; padding: .9rem 1.5rem; border-top: 1px solid #e2e8f0; background: #f8fafc; }
    @media (max-width: 991.98px) { .task-details-modal .modal-dialog { max-width: calc(100% - 2rem); } }
    @media (max-width: 575.98px) {
        .task-details-modal .modal-dialog { max-width: none; min-height: calc(100% - 1rem); margin: .5rem; }
        .task-details-modal .modal-content { max-height: calc(100dvh - 1rem); border-radius: .8rem; }
        .task-details-modal .modal-header, .task-details-modal .modal-footer { padding: .9rem 1rem; }
        .task-details-modal .modal-body { padding: .1rem .75rem .75rem; }
        .task-detail-section { padding: 0 .85rem .9rem; margin-top: .75rem; border-radius: .7rem; }
        .task-detail-section-heading { padding: .7rem .85rem; margin: 0 -.85rem .85rem; }
        .task-details-modal .modal-footer .btn { flex: 1 1 auto; }
    }
     .report-edit-modal .modal-content { max-height: calc(100vh - 2rem); overflow: hidden; border: 1px solid #c4d4e2; border-radius: 1rem; box-shadow: 0 22px 58px rgba(15, 35, 57, .24); }
     .report-edit-modal #reportEditTaskForm { display: flex; flex: 1 1 auto; max-height: 100%; min-height: 0; flex-direction: column; overflow: hidden; }
     .report-edit-modal .modal-header { align-items: center; padding: 1rem 1.35rem; color: #153b63; border-bottom: 1px solid #c9dbea; background: linear-gradient(120deg, #f8fbfe, #e7f1fa); }
     .report-edit-heading-wrap { min-width: 0; }
     .report-edit-title-icon { width: 46px; height: 46px; flex: 0 0 46px; color: #fff; border-radius: .8rem; background: linear-gradient(135deg, #2584de, #105a9e); box-shadow: 0 7px 16px rgba(23, 105, 194, .22); font-size: 1.15rem; }
     .report-edit-modal .modal-title { color: #153b63; font-weight: 700; }
     .report-edit-modal .modal-body { min-height: 0; padding: 1.25rem; overflow-y: auto; overscroll-behavior: contain; background: #eef3f7; }
     .report-edit-modal .modal-footer { padding: .9rem 1.35rem; border-top: 1px solid #c9dbea; background: #f8fafc; }
     .report-edit-modal .modal-footer .btn-primary { min-width: 145px; box-shadow: 0 5px 13px rgba(23, 105, 194, .2); }
     .report-edit-section { overflow: hidden; padding: 0 1rem 1rem; margin-bottom: 1rem; border: 1px solid #cbdbe8; border-radius: .85rem; background: #fff; box-shadow: 0 4px 13px rgba(30, 64, 98, .05); }
     .report-edit-section-heading { display: flex; align-items: center; gap: .55rem; padding: .78rem 1rem; margin: 0 -1rem 1rem; color: #1b4f7f; border-bottom: 1px solid #d9e6f0; background: #edf5fc; font-size: .95rem; font-weight: 700; }
     .report-edit-section-heading i { color: #1769c2; font-size: 1rem; }
     .report-edit-section--details .report-edit-section-heading { color: #4c428d; border-bottom-color: #e1ddf1; background: #f3f1fb; }
     .report-edit-section--details .report-edit-section-heading i { color: #6757b7; }
     .report-edit-section--time .report-edit-section-heading { color: #17624f; border-bottom-color: #d7e9e2; background: #edf8f4; }
     .report-edit-section--time .report-edit-section-heading i { color: #258468; }
     .report-edit-modal .form-label { color: #36536f; font-weight: 700; }
     .report-edit-modal .form-control, .report-edit-modal .form-select { border-color: #c7d8e7; background-color: #fbfdff; }
     .report-edit-modal .form-control:hover, .report-edit-modal .form-select:hover { border-color: #9ebbd5; background-color: #fff; }
     .report-edit-modal .form-control:focus, .report-edit-modal .form-select:focus { border-color: #5b9bd5; background-color: #fff; box-shadow: 0 0 0 .2rem rgba(23, 105, 194, .12); }
     .report-edit-modal #reportEditEquipmentGroup { padding: .85rem; border: 1px dashed #bdd2e4; border-radius: .7rem; background: #f7fbfe; }
     .report-equipment-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: .65rem; align-items: center; margin-bottom: .65rem; }
     .report-equipment-quantity { display: grid; grid-template-columns: 2.35rem 4.25rem 2.35rem; }
     .report-equipment-quantity .btn, .report-equipment-quantity .form-control { border-radius: 0; }
     .report-equipment-quantity .btn:first-child { border-radius: .375rem 0 0 .375rem; }
     .report-equipment-quantity .btn:last-child { border-radius: 0 .375rem .375rem 0; }
     .report-equipment-row .grid-column-1-3 { grid-column: 1 / -1; }
     .report-edit-modal textarea { resize: vertical; }
     .report-edit-modal .task-auto-status { display: flex; min-height: 38px; align-items: center; gap: .7rem; padding: .45rem .65rem; border: 1px solid #c9d9e7; border-radius: .55rem; background: #f5f9fc; }
     .report-edit-modal .task-auto-status small { color: #5f7387; line-height: 1.35; }
     .report-edit-optional { display: inline-flex; margin-left: .35rem; padding: .12rem .42rem; color: #64748b; border-radius: 999px; background: #e8eef4; font-size: .7rem; font-weight: 600; vertical-align: middle; }
     .report-edit-image-card { position: relative; display: inline-flex; flex-direction: column; gap: .3rem; width: 128px; margin: 0 .6rem .6rem 0; padding: .45rem; border: 1px solid #d7e0ea; border-radius: .6rem; background: #fff; cursor: pointer; transition: border-color .15s ease, box-shadow .15s ease; }
     .report-edit-image-card img { width: 100%; height: 78px; object-fit: cover; border-radius: .4rem; }
     .report-edit-image-card .report-edit-image-name { font-size: .74rem; color: #52677f; max-width: 100%; }
     .report-edit-image-card .report-edit-image-remove { display: none; align-items: center; justify-content: center; color: #b02a37; font-size: .74rem; font-weight: 700; }
     .report-edit-image-card .report-edit-image-delete { position: absolute; top: .2rem; right: .2rem; display: grid; place-items: center; width: 26px; height: 26px; padding: 0; color: #fff; border: 1px solid rgba(255, 255, 255, .5); border-radius: .45rem; background: rgba(220, 53, 69, .85); font-size: .78rem; line-height: 1; box-shadow: 0 2px 7px rgba(15, 23, 42, .3); }
     .report-edit-image-card .report-edit-image-delete:hover { background: #b02a37; }
     .report-edit-image-card input { position: absolute; opacity: 0; pointer-events: none; }
     .report-edit-image-card.is-marked, .report-edit-image-card:has(input:checked) { border-color: #dc3545; box-shadow: 0 0 0 .2rem rgba(220, 53, 69, .12); }
     .report-edit-image-card.is-marked .report-edit-image-remove, .report-edit-image-card:has(input:checked) .report-edit-image-remove { display: inline-flex; }
     .report-edit-image-card.is-marked img, .report-edit-image-card:has(input:checked) img { opacity: .45; }
     @media (max-width: 575.98px) { .report-edit-modal .modal-dialog { min-height: calc(100% - 1rem); margin: .5rem; } .report-edit-modal .modal-content { max-height: calc(100vh - 1rem); } .report-edit-modal .modal-header { padding: .85rem 1rem; } .report-edit-title-icon { width: 40px; height: 40px; flex-basis: 40px; } .report-edit-modal .modal-body { padding: .75rem; } .report-edit-section { padding: 0 .8rem .85rem; } .report-edit-section-heading { padding: .7rem .8rem; margin: 0 -.8rem .85rem; } .report-edit-modal .modal-footer { padding: .75rem; } .report-edit-modal .modal-footer .btn { flex: 1 1 auto; } }
    /* Task image lightbox: zoom, pan, and close over the detail modal. */
    .task-image-thumb-wrap { position: relative; display: block; }
    .task-image-zoom-hint { position: absolute; top: .45rem; right: .45rem; display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; color: #fff; border-radius: .5rem; background: rgba(15, 23, 42, .55); opacity: 0; transition: opacity .15s ease; }
    .task-image-link:hover .task-image-zoom-hint, .task-image-link:focus .task-image-zoom-hint { opacity: 1; }
    .task-image-lightbox { position: fixed; inset: 0; z-index: 2000; display: none; flex-direction: column; background: rgba(7, 11, 25, .93); }
    .task-image-lightbox.is-open { display: flex; }
    .task-lightbox-stage { position: relative; flex: 1 1 auto; display: flex; align-items: center; justify-content: center; overflow: hidden; cursor: zoom-in; }
    .task-lightbox-stage img { max-width: 92vw; max-height: 84vh; border-radius: .45rem; box-shadow: 0 28px 70px rgba(0, 0, 0, .6); transform-origin: center center; transition: transform .14s ease-out; user-select: none; -webkit-user-drag: none; }
    .task-lightbox-stage.is-pannable { cursor: grab; }
    .task-lightbox-stage.is-pannable img { transition: none; }
    .task-lightbox-stage.is-dragging { cursor: grabbing; }
    .task-lightbox-toolbar { position: absolute; top: 1rem; right: 1rem; z-index: 2; display: flex; gap: .45rem; }
    .task-lightbox-btn { width: 42px; height: 42px; padding: 0; color: #e7edf7; border: 1px solid rgba(255, 255, 255, .22); border-radius: .6rem; background: rgba(255, 255, 255, .1); font-size: 1.05rem; line-height: 1; }
    .task-lightbox-btn:hover, .task-lightbox-btn:focus { color: #fff; border-color: rgba(255, 255, 255, .4); background: rgba(255, 255, 255, .2); }
    .task-lightbox-close:hover, .task-lightbox-close:focus { color: #fff; border-color: rgba(248, 113, 113, .6); background: rgba(220, 38, 38, .55); }
    .task-lightbox-caption { position: absolute; bottom: 1.1rem; left: 50%; max-width: 82vw; padding: .4rem 1rem; overflow: hidden; color: #e2e8f0; text-overflow: ellipsis; white-space: nowrap; border-radius: 999px; background: rgba(15, 23, 42, .66); font-size: .92rem; transform: translateX(-50%); }
</style>
<script>
    const taskWorkDetails = <?php echo json_encode($task_rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const taskWorkMap = new Map(taskWorkDetails.map((task) => [Number(task.id), task]));
    const problemCategoryOptions = <?php echo json_encode($problem_category_options, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    // One shared detail modal is filled for whichever task row is selected,
    // instead of rendering one modal per table row on the server.
    (() => {
        const detailModalElement = document.getElementById("taskDetailModal");
        if (!detailModalElement) return;

        const escapeHtml = (value) => String(value ?? "").replace(/[&<>'"]/g, (character) => ({ '&': '\u0026amp;', '<': '\u0026lt;', '>': '\u0026gt;', "'": '\u0026#039;', '"': '\u0026quot;' }[character]));
        const statusMeta = (status) => ({ pending: ["รอดำเนินการ", "status-pending"], in_progress: ["กำลังดำเนินการ", "status-progress"], completed: ["เสร็จสิ้น", "status-completed"], cancelled: ["ยกเลิก", "status-cancelled"] }[status] || [status, "status-pending"]);
        const displayValue = (value) => {
            const text = String(value ?? "").trim();
            return text === "-" ? "" : text;
        };
        const thaiDateTime = (value) => {
            const text = String(value ?? "").trim();
            if (!text) return "-";
            const date = new Date(text.replace(" ", "T"));
            if (Number.isNaN(date.getTime())) return "-";
            return new Intl.DateTimeFormat("th-TH-u-ca-buddhist", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit", hour12: false }).format(date) + " น.";
        };
        const durationText = (startValue, finishValue) => {
            const start = new Date(String(startValue || "").replace(" ", "T"));
            const finish = new Date(String(finishValue || "").replace(" ", "T"));
            if (Number.isNaN(start.getTime()) || Number.isNaN(finish.getTime()) || finish < start) return "";
            const totalMinutes = Math.floor((finish - start) / 60000);
            if (totalMinutes === 0) return "น้อยกว่า 1 นาที";
            const days = Math.floor(totalMinutes / 1440);
            const hours = Math.floor((totalMinutes % 1440) / 60);
            const minutes = totalMinutes % 60;
            const parts = [];
            if (days) parts.push(`${days} วัน`);
            if (hours) parts.push(`${hours} ชั่วโมง`);
            if (minutes) parts.push(`${minutes} นาที`);
            return parts.join(" ");
        };
        const multiline = (value) => escapeHtml(value).replace(/\n/g, "<br>");

        const renderTaskDetail = (task) => {
            const isIT = String(task.department || "").toUpperCase() === "IT";
            const [statusLabel, statusClass] = statusMeta(task.status);
            const category = displayValue(task.category);
            const hasCategory = category !== "";
            const hasProblem = displayValue(task.problem) !== "";
            const hasSolution = displayValue(task.solution) !== "";
            const hasWorkDescription = displayValue(task.work_description) !== "";
            const hasWorkAction = displayValue(task.work_action) !== "";
            const hasRemark = displayValue(task.remark) !== "";
            const equipment = Array.isArray(task.equipment) ? task.equipment : [];
            const images = Array.isArray(task.images) ? task.images : [];
            const activity = Array.isArray(task.activity_log) ? task.activity_log : [];
            const duration = durationText(task.start_time, task.finish_time);
            const activityIcons = { created: "bi-plus-lg", updated: "bi-pencil", status_changed: "bi-arrow-repeat", deleted: "bi-trash" };

            const summaryItems = [
                `<div class="col-md-6 task-detail-item"><strong class="task-detail-label">ชื่องาน</strong><div class="task-detail-value">${escapeHtml(task.title)}</div></div>`,
                `<div class="col-6 col-md-3 task-detail-item"><strong class="task-detail-label">ทีม</strong><div class="task-detail-value">${escapeHtml(task.department)}</div></div>`,
                `<div class="col-6 col-md-3 task-detail-item"><strong class="task-detail-label">สถานะ</strong><div class="task-detail-value"><span class="badge rounded-pill ${statusClass}">${escapeHtml(statusLabel)}</span></div></div>`
            ];
            if (isIT || hasCategory) {
                const categoryLabel = problemCategoryOptions[category] || category;
                summaryItems.push(`<div class="col-md-6 task-detail-item"><strong class="task-detail-label">ประเภทปัญหา</strong><div class="task-detail-value">${escapeHtml(categoryLabel || "-")}</div></div>`);
            }
            summaryItems.push(`<div class="col-md-6 task-detail-item"><strong class="task-detail-label">สถานที่</strong><div class="task-detail-value">${escapeHtml(displayValue(task.location) || "-")}</div></div>`);

            const narrativeFields = [];
            if (hasWorkDescription) narrativeFields.push(`<div class="task-detail-field"><strong class="task-detail-label">${isIT ? "รายละเอียดงาน" : "รายละเอียดกิจกรรมและอุปกรณ์ที่ใช้งาน"}</strong><div class="task-detail-value">${multiline(displayValue(task.work_description))}</div></div>`);
            if (!isIT && equipment.length) {
                const chips = equipment.map((item) => `<span class="badge rounded-pill text-bg-light border text-dark fw-normal">${escapeHtml(item.name)} × ${Number(item.quantity)}</span>`).join("");
                narrativeFields.push(`<div class="task-detail-field"><strong class="task-detail-label">อุปกรณ์ที่ใช้งาน</strong><div class="d-flex flex-wrap gap-2 mt-2">${chips}</div></div>`);
            }
            if (hasWorkAction) narrativeFields.push(`<div class="task-detail-field"><strong class="task-detail-label">${isIT ? "การดำเนินงาน" : "สรุปการดำเนินงาน"}</strong><div class="task-detail-value">${multiline(displayValue(task.work_action))}</div></div>`);
            if (hasRemark) narrativeFields.push(`<div class="task-detail-field"><strong class="task-detail-label">หมายเหตุ</strong><div class="task-detail-value">${multiline(displayValue(task.remark))}</div></div>`);

            const durationItem = duration ? `<div class="col-sm-6 col-lg-4 task-detail-item"><strong class="task-detail-label">ระยะเวลาดำเนินการ</strong><div class="task-detail-value">${escapeHtml(duration)}</div></div>` : "";
            const responsible = displayValue(task.responsible_name) || task.created_by_name || "-";

            const imageSection = images.length
                ? `<section class="task-detail-section"><h3 class="task-detail-section-heading"><i class="bi bi-images"></i>รูปภาพประกอบงาน</h3><div class="row g-3 task-image-grid">${images.map((image) => `<div class="col-6 col-md-3"><a class="task-image-link d-block text-decoration-none" href="../${escapeHtml(image.file_path)}" data-lightbox-image data-lightbox-name="${escapeHtml(image.original_name)}"><span class="task-image-thumb-wrap"><img src="../${escapeHtml(image.file_path)}" alt="${escapeHtml(image.original_name)}"><span class="task-image-zoom-hint"><i class="bi bi-arrows-fullscreen"></i></span></span><span class="d-block small text-truncate p-2">${escapeHtml(image.original_name)}</span></a></div>`).join("")}</div></section>`
                : "";
            const activitySection = activity.length
                ? `<section class="task-detail-section task-activity-panel"><h3 class="task-detail-section-heading"><i class="bi bi-clock-history"></i>ประวัติการเปลี่ยนแปลง</h3><div class="task-activity-list">${activity.map((item) => {
                    const changes = Array.isArray(item.details && item.details.changes) ? item.details.changes : [];
                    const expandable = changes.length > 0;
                    return `<div class="task-activity-item${expandable ? " task-activity-expandable" : ""}" ${expandable ? 'role="button" tabindex="0" aria-expanded="false" title="กดเพื่อดูรายละเอียดที่เปลี่ยนแปลง"' : ""}><span class="task-activity-icon"><i class="bi ${activityIcons[item.event_type] || "bi-clock-history"}"></i></span><div class="flex-grow-1"><div class="fw-semibold">${escapeHtml(item.description)}${expandable ? ' <i class="bi bi-chevron-down task-activity-chevron"></i>' : ""}</div><div class="task-activity-meta">${escapeHtml(item.actor_name || "ระบบ")} · ${escapeHtml(thaiDateTime(item.created_at))}</div>${expandable ? `<div class="task-activity-details d-none">${changes.map((change) => `<div class="task-activity-change"><span class="task-activity-change-label">${escapeHtml(change.label)}</span><div class="task-activity-change-values"><span class="task-activity-before">${escapeHtml(change.before)}</span><i class="bi bi-arrow-right"></i><span class="task-activity-after">${escapeHtml(change.after)}</span></div></div>`).join("")}</div>` : ""}</div></div>`;
                }).join("")}</div></section>`
                : "";

            return `
                <section class="task-detail-section"><h3 class="task-detail-section-heading"><i class="bi bi-info-circle"></i>ข้อมูลหลัก</h3><div class="row g-4 task-detail-summary">${summaryItems.join("")}</div></section>
                ${narrativeFields.length ? `<section class="task-detail-section"><h3 class="task-detail-section-heading"><i class="bi bi-journal-text"></i>${isIT ? "รายละเอียดงาน" : "รายละเอียดกิจกรรมและอุปกรณ์"}</h3><div class="task-detail-narrative">${narrativeFields.join("")}</div></section>` : ""}
                ${isIT || hasProblem ? `<section class="task-detail-section task-detail-section--problem"><h3 class="task-detail-section-heading"><i class="bi bi-exclamation-circle"></i>ปัญหาที่พบ</h3><div class="task-detail-value${hasProblem ? "" : " task-detail-empty"}">${hasProblem ? multiline(displayValue(task.problem)) : "ยังไม่มีข้อมูลปัญหา"}</div></section>` : ""}
                ${isIT || hasSolution ? `<section class="task-detail-section task-detail-section--solution"><h3 class="task-detail-section-heading"><i class="bi bi-check2-circle"></i>วิธีแก้ไขปัญหา</h3><div class="task-detail-value${hasSolution ? "" : " task-detail-empty"}">${hasSolution ? multiline(displayValue(task.solution)) : "ยังไม่ได้บันทึกวิธีแก้ไข"}</div></section>` : ""}
                <section class="task-detail-section"><h3 class="task-detail-section-heading"><i class="bi bi-clock-history"></i>เวลาและผู้รับผิดชอบ</h3><div class="row g-4 task-detail-meta">
                    <div class="col-sm-6 col-lg-4 task-detail-item"><strong class="task-detail-label">เวลาเริ่มดำเนินการ</strong><div class="task-detail-value">${escapeHtml(thaiDateTime(task.start_time))}</div></div>
                    <div class="col-sm-6 col-lg-4 task-detail-item"><strong class="task-detail-label">เวลาสิ้นสุด</strong><div class="task-detail-value">${escapeHtml(thaiDateTime(task.finish_time))}</div></div>
                    ${durationItem}
                    <div class="col-sm-6 col-lg-3 task-detail-item"><strong class="task-detail-label">ผู้รับผิดชอบ</strong><div class="task-detail-value">${escapeHtml(responsible)}</div></div>
                    <div class="col-sm-6 col-lg-3 task-detail-item"><strong class="task-detail-label">อัปเดตล่าสุด</strong><div class="task-detail-value">${escapeHtml(thaiDateTime(task.updated_at))}</div></div>
                    <div class="col-12 task-detail-created"><span>สร้างเมื่อ ${escapeHtml(thaiDateTime(task.created_at))}</span></div>
                </div></section>
                ${imageSection}
                ${activitySection}
            `;
        };

        const openDetailModal = (task) => {
            document.getElementById("taskDetailModalLabel").textContent = task.title;
            document.getElementById("taskDetailBody").innerHTML = renderTaskDetail(task);
            const editButton = document.getElementById("taskDetailEditButton");
            editButton.dataset.editTaskId = String(task.id);
            editButton.classList.toggle("d-none", !task.can_edit);
            window.bootstrap?.Modal.getOrCreateInstance(detailModalElement).show();
        };

        document.querySelectorAll(".report-detail-task").forEach((button) => {
            button.addEventListener("click", () => {
                const task = taskWorkMap.get(Number(button.dataset.taskId));
                if (task) openDetailModal(task);
            });
        });

        // Activity history rows with stored changes expand on click to show before/after values.
        const toggleActivityDetails = (element) => {
            const item = element.closest(".task-activity-item.task-activity-expandable");
            if (!item) return;
            const details = item.querySelector(".task-activity-details");
            if (!details) return;
            const expanded = details.classList.toggle("d-none") === false;
            item.setAttribute("aria-expanded", expanded ? "true" : "false");
            item.querySelector(".task-activity-chevron")?.classList.toggle("rotate-180", expanded);
        };
        document.getElementById("taskDetailBody")?.addEventListener("click", (event) => {
            if (event.target.closest("a[data-lightbox-image], button")) return;
            toggleActivityDetails(event.target);
        });
        document.getElementById("taskDetailBody")?.addEventListener("keydown", (event) => {
            if (event.key !== "Enter" && event.key !== " ") return;
            if (!event.target.classList?.contains("task-activity-expandable")) return;
            event.preventDefault();
            toggleActivityDetails(event.target);
        });

        const deleteModalElement = document.getElementById("deleteTaskModal");
        document.querySelectorAll(".report-delete-task").forEach((button) => {
            button.addEventListener("click", () => {
                const task = taskWorkMap.get(Number(button.dataset.taskId));
                if (!task || !deleteModalElement) return;
                document.getElementById("deleteTaskId").value = String(task.id);
                document.getElementById("deleteTaskTitle").textContent = task.title;
                window.bootstrap?.Modal.getOrCreateInstance(deleteModalElement).show();
            });
        });
    })();

    // One reusable edit modal keeps users on the Report page.
    (() => {
        const modalElement = document.getElementById("reportEditTaskModal");
        const form = document.getElementById("reportEditTaskForm");
        if (!modalElement || !form) return;

        const taskMap = new Map(taskWorkDetails.map((task) => [Number(task.id), task]));
        const locationOptions = <?php echo json_encode($report_location_options, JSON_UNESCAPED_UNICODE); ?>;
        const recoveryData = <?php echo json_encode($report_update_form_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const initialEditTaskId = <?php echo ($selected_task && can_edit_task($selected_task)) ? (int) $selected_task["id"] : 0; ?>;
        const field = (id) => document.getElementById(id);
        const locationSelect = field("reportEditLocation");
        const otherLocationGroup = field("reportEditOtherLocationGroup");
        const otherLocationInput = field("reportEditOtherLocation");
        const departmentControl = field("reportEditDepartment");
        const categoryGroup = field("reportEditCategoryGroup");
        const workDescriptionGroup = field("reportEditWorkDescriptionGroup");
        const workDescriptionLabel = field("reportEditWorkDescriptionLabel");
        const workDescriptionHint = field("reportEditWorkDescriptionHint");
        const workActionGroup = field("reportEditWorkActionGroup");
        const workActionLabel = field("reportEditWorkActionLabel");
        const problemControl = field("reportEditProblem");
        const problemRequiredMark = field("reportEditProblemRequired");
        const problemOptionalMark = field("reportEditProblemOptional");
        const solutionControl = field("reportEditSolution");
        const solutionOptionalMark = field("reportEditSolutionOptional");
        const workActionControl = field("reportEditWorkAction");
        const workActionStatusHint = field("reportEditWorkActionHint");
        const solutionStatusHint = field("reportEditSolutionHint");
        const finishDateControl = field("reportEditFinishDate");
        const finishTimeControl = field("reportEditFinishTime");
        const statusControl = field("reportEditStatus");
        const statusSelectGroup = field("reportEditStatusSelectGroup");
        const autoStatusGroup = field("reportEditAutoStatusGroup");
        const autoStatusBadge = field("reportEditAutoStatusBadge");
        const autoStatusHint = field("reportEditAutoStatusHint");
        const detailHeading = field("reportEditDetailHeading");
        const timeHeading = field("reportEditTimeHeading");
        const equipmentGroup = field("reportEditEquipmentGroup");
        const equipmentRows = field("reportEditEquipmentRows");
        const addEquipmentButton = field("reportEditAddEquipment");
        const canControlTaskStatus = <?php echo json_encode($can_control_task_status); ?>;
        const equipmentItems = <?php echo json_encode($report_equipment_items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        const equipmentName = (equipmentId) => equipmentItems.find((item) => Number(item.id) === Number(equipmentId))?.name || "อุปกรณ์";
        const escapeMarkup = (value) => String(value).replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;");
        const activeEquipmentOptions = () => equipmentItems
            .filter((item) => Number(item.is_enabled) === 1)
            .map((item) => `<option value="${Number(item.id)}">${escapeMarkup(item.name)}</option>`)
            .join("");

        const addEquipmentEditRow = (equipmentId = 0, initialQuantity = 1, existing = false) => {
            if (!equipmentRows || (!equipmentId && !activeEquipmentOptions())) return;
            const row = document.createElement("div");
            row.className = "report-equipment-row";
            const equipmentControl = existing
                ? `<div class="form-control bg-light"><input type="hidden" name="equipment_id[]" value="${Number(equipmentId)}">${escapeMarkup(equipmentName(equipmentId))}</div>`
                : `<select class="form-select" name="equipment_id[]"><option value="">เลือกอุปกรณ์</option>${activeEquipmentOptions()}</select>`;
            row.innerHTML = `${equipmentControl}<div class="report-equipment-quantity"><button class="btn btn-outline-secondary" type="button" data-quantity-action="decrease" aria-label="ลดจำนวน">−</button><input class="form-control text-center" type="number" name="equipment_quantity[]" value="${Math.max(1, Number(initialQuantity) || 1)}" min="1" step="1" aria-label="จำนวน"><button class="btn btn-outline-secondary" type="button" data-quantity-action="increase" aria-label="เพิ่มจำนวน">+</button></div>`;
            const quantity = row.querySelector('input[name="equipment_quantity[]"]');
            row.querySelector('[data-quantity-action="decrease"]').addEventListener("click", () => { quantity.value = Math.max(1, (Number(quantity.value) || 1) - 1); });
            row.querySelector('[data-quantity-action="increase"]').addEventListener("click", () => { quantity.value = Math.max(1, (Number(quantity.value) || 1) + 1); });
            if (!existing) {
                const select = row.querySelector("select");
                select.addEventListener("change", () => {
                    if (!select.value) return;
                    const duplicateId = Number(select.value);
                    const duplicateRow = Array.from(equipmentRows.querySelectorAll(".report-equipment-row")).find((candidate) => candidate !== row && Number(candidate.querySelector('input[name="equipment_id[]"]')?.value || candidate.querySelector("select")?.value) === duplicateId);
                    if (!duplicateRow) return;
                    const duplicateQuantity = duplicateRow.querySelector('input[name="equipment_quantity[]"]');
                    duplicateQuantity.value = Math.max(1, Number(duplicateQuantity.value) || 1) + Math.max(1, Number(quantity.value) || 1);
                    row.remove();
                });
                const removeButton = document.createElement("button");
                removeButton.type = "button";
                removeButton.className = "btn btn-sm btn-link text-danger p-0";
                removeButton.textContent = "นำรายการใหม่ออก";
                removeButton.addEventListener("click", () => row.remove());
                const wrapper = document.createElement("div");
                wrapper.className = "grid-column-1-3";
                wrapper.append(removeButton);
                row.append(wrapper);
            }
            equipmentRows.append(row);
        };

        const renderEquipmentEditRows = (items) => {
            equipmentRows?.replaceChildren();
            (Array.isArray(items) ? items : []).forEach((item) => addEquipmentEditRow(item.equipment_id, item.quantity, true));
        };

        addEquipmentButton?.addEventListener("click", () => addEquipmentEditRow());

        const displayValue = (value) => {
            const text = String(value ?? "").trim();
            return text === "-" ? "" : text;
        };

        const setLabelText = (element, text) => {
            if (!element) return;
            const textNode = [...element.childNodes].find((node) => node.nodeType === Node.TEXT_NODE);
            if (textNode) textNode.nodeValue = `${text} `;
        };

        const splitTaskDateTime = (value) => {
            const match = String(value || "").match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
            if (!match) return { date: "", time: "" };
            return {
                date: `${match[3]}/${match[2]}/${Number(match[1]) + 543}`,
                time: `${match[4]}:${match[5]}`
            };
        };

        const setPickerValue = (input, value, format) => {
            if (!input) return;
            if (input._flatpickr && value) {
                input._flatpickr.setDate(value, false, format);
            } else {
                input.value = value || "";
                if (input._flatpickr && !value) input._flatpickr.clear(false);
            }
        };

        const updateOtherLocation = () => {
            if (!locationSelect || !otherLocationGroup || !otherLocationInput) return;
            const isOther = locationSelect.value === "__other__";
            otherLocationGroup.classList.toggle("d-none", !isOther);
            otherLocationInput.required = isOther;
            if (!isOther) otherLocationInput.value = "";
        };

        const updateITEditWorkflow = () => {
            const isIT = departmentControl?.value === "IT";
            const isAV = departmentControl?.value === "AV";
            const hasSolution = Boolean(solutionControl?.value.trim());
            const hasWorkAction = Boolean(workActionControl?.value.trim());
            const hasFinishTime = Boolean(finishDateControl?.value && finishTimeControl?.value);
            if (problemControl) problemControl.required = isIT;
            problemRequiredMark?.classList.toggle("d-none", !isIT);
            problemOptionalMark?.classList.toggle("d-none", isIT);
            solutionOptionalMark?.classList.toggle("d-none", isIT);
            categoryGroup?.classList.toggle("d-none", isAV);
            equipmentGroup?.classList.toggle("d-none", !isAV);
            equipmentRows?.querySelectorAll("select, input, button").forEach((control) => { control.disabled = !isAV; });
            if (addEquipmentButton) addEquipmentButton.disabled = !isAV || !equipmentItems.some((item) => Number(item.is_enabled) === 1);
            workActionGroup?.classList.toggle("d-none", isIT);
            workDescriptionGroup?.classList.toggle("col-md-6", isAV);
            workDescriptionGroup?.classList.toggle("col-12", isIT);
            setLabelText(workDescriptionLabel, isIT ? "รายละเอียดงาน" : "รายละเอียดกิจกรรมและอุปกรณ์ที่ใช้งาน");
            setLabelText(workActionLabel, isIT ? "การดำเนินงาน" : "สรุปการดำเนินงาน");
            if (workDescriptionHint) workDescriptionHint.textContent = isIT
                ? "อธิบายบริบทหรืออาการของงานให้เข้าใจได้รวดเร็ว"
                : "ระบุรายละเอียด Event / Seminar และอุปกรณ์ เช่น กล้อง ไมโครโฟน หรือ Projector";
            if (problemControl) problemControl.placeholder = isIT ? "ระบุปัญหาที่ตรวจพบ" : "กรอกเมื่อพบปัญหาระหว่างดำเนินงาน";
            if (solutionControl) solutionControl.placeholder = isIT ? "ระบุวิธีแก้ไขเมื่อดำเนินการเสร็จ" : "กรอกเมื่อมีการแก้ไขปัญหา";
            if (detailHeading) detailHeading.innerHTML = isIT
                ? '<i class="bi bi-file-earmark-text me-2"></i>Problem → Solution'
                : '<i class="bi bi-calendar-event me-2"></i>รายละเอียด Event / Operation';
            if (timeHeading) timeHeading.innerHTML = '<i class="bi bi-clock-history me-2"></i>วันและเวลาดำเนินงาน';
            setLabelText(field("reportEditStartDateLabel"), "วันเริ่มดำเนินการ");
            setLabelText(field("reportEditStartTimeLabel"), "เวลาเริ่มดำเนินการ");
            setLabelText(field("reportEditFinishDateLabel"), "วันสิ้นสุด");
            setLabelText(field("reportEditFinishTimeLabel"), "เวลาสิ้นสุด");
            workActionStatusHint?.classList.toggle("d-none", !isAV);
            solutionStatusHint?.classList.toggle("d-none", !isIT);
            if (!statusControl) return;
            statusSelectGroup?.classList.toggle("d-none", !canControlTaskStatus);
            autoStatusGroup?.classList.toggle("d-none", canControlTaskStatus);
            if (isIT && hasSolution && !canControlTaskStatus) {
                statusControl.value = "completed";
            } else if (isIT && !canControlTaskStatus && statusControl.value !== "cancelled") {
                statusControl.value = "pending";
            } else if (isAV && !canControlTaskStatus && statusControl.value !== "cancelled") {
                statusControl.value = hasWorkAction || hasFinishTime ? "completed" : "in_progress";
            }
            if (!canControlTaskStatus && autoStatusBadge) {
                const statusMeta = {
                    pending: ["รอดำเนินการ", "status-pending"],
                    in_progress: ["กำลังดำเนินการ", "status-progress"],
                    completed: ["เสร็จสิ้น", "status-completed"],
                    cancelled: ["ยกเลิก", "status-cancelled"]
                }[statusControl.value] || [statusControl.value, "status-pending"];
                autoStatusBadge.className = `badge rounded-pill ${statusMeta[1]}`;
                autoStatusBadge.textContent = statusMeta[0];
                if (autoStatusHint) {
                    autoStatusHint.textContent = isIT
                        ? (hasSolution ? "มีวิธีแก้ไขแล้ว ระบบกำหนดเป็น “เสร็จสิ้น”" : "สถานะงาน IT ถูกกำหนดโดยระบบ")
                        : (hasWorkAction || hasFinishTime
                            ? "มีการดำเนินงานหรือเวลาสิ้นสุดแล้ว ระบบกำหนดเป็น “เสร็จสิ้น”"
                            : "งาน AV จะอยู่ในสถานะ “กำลังดำเนินการ” จนกว่าจะกรอกการดำเนินงานหรือเวลาสิ้นสุด");
                }
            }
        };

        // Script-local escaper: the detail-modal script defines its own, but this
        // edit-modal script runs in a separate scope and needs its own.
        const escapeHtml = (value) => String(value ?? "").replace(/[&<>'"]/g, (character) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#039;", '"': "&quot;" }[character]));
        const existingImagesBox = document.getElementById("reportEditExistingImages");
        const newImagesInput = document.getElementById("reportEditTaskImages");
        const renderEditExistingImages = (images) => {
            if (!existingImagesBox) return;
            newImagesInput && (newImagesInput.value = "");
            if (!images.length) {
                existingImagesBox.innerHTML = '<div class="text-muted small">ยังไม่มีรูปภาพประกอบงาน — เพิ่มได้จากช่องด้านล่าง</div>';
                return;
            }
            existingImagesBox.innerHTML = images.map((image) => `<label class="report-edit-image-card" title="กดปุ่มลบเพื่อเอารูปนี้ออกจากงาน"><input type="checkbox" name="delete_image_ids[]" value="${Number(image.id)}"><img src="../${escapeHtml(image.file_path)}" alt="${escapeHtml(image.original_name)}"><button type="button" class="report-edit-image-delete" aria-label="ลบรูป ${escapeHtml(image.original_name)}" title="ลบรูปนี้"><i class="bi bi-x-lg"></i></button><span class="report-edit-image-name text-truncate">${escapeHtml(image.original_name)}</span><span class="report-edit-image-remove"><i class="bi bi-trash me-1"></i>จะถูกลบเมื่อกดบันทึก</span></label>`).join("");
            existingImagesBox.querySelectorAll(".report-edit-image-delete").forEach((button) => {
                button.addEventListener("click", (event) => {
                    event.preventDefault();
                    const card = button.closest(".report-edit-image-card");
                    const checkbox = card?.querySelector("input[type=checkbox]");
                    if (!checkbox) return;
                    checkbox.checked = !checkbox.checked;
                    card.classList.toggle("is-marked", checkbox.checked);
                });
            });
        };

        const fillEditForm = (task) => {
            const start = task.start_date !== undefined
                ? { date: task.start_date, time: task.start_work_time }
                : splitTaskDateTime(task.start_time);
            const finish = task.finish_date !== undefined
                ? { date: task.finish_date, time: task.finish_work_time }
                : splitTaskDateTime(task.finish_time);
            const rawLocation = displayValue(task.location);
            const selectedLocation = rawLocation === "__other__"
                ? "__other__"
                : (locationOptions.includes(rawLocation) || rawLocation === "" ? rawLocation : "__other__");

            field("reportEditTaskId").value = task.id;
            field("reportEditTitle").value = displayValue(task.title);
            field("reportEditDepartment").value = displayValue(task.department);
            field("reportEditResponsible").value = displayValue(task.responsible_name);
            locationSelect.value = selectedLocation;
            otherLocationInput.value = task.other_location !== undefined
                ? displayValue(task.other_location)
                : (selectedLocation === "__other__" ? rawLocation : "");
            field("reportEditStatus").value = displayValue(task.status) || "pending";
            field("reportEditCategory").value = displayValue(task.category) || "-";
            field("reportEditWorkDescription").value = displayValue(task.work_description);
            field("reportEditWorkAction").value = displayValue(task.work_action);
            field("reportEditProblem").value = displayValue(task.problem);
            field("reportEditSolution").value = displayValue(task.solution);
            field("reportEditRemark").value = displayValue(task.remark);
            renderEquipmentEditRows(task.equipment || []);
            renderEditExistingImages(task.images || []);
            setPickerValue(field("reportEditStartDate"), start.date, "d/m/Y");
            setPickerValue(field("reportEditStartTime"), start.time, "H:i");
            setPickerValue(field("reportEditFinishDate"), finish.date, "d/m/Y");
            setPickerValue(field("reportEditFinishTime"), finish.time, "H:i");
            field("reportEditTaskSubtitle").textContent = `${displayValue(task.department) || "Task"} · ${displayValue(task.title) || "แก้ไขข้อมูลงาน"}`;
            updateOtherLocation();
            updateITEditWorkflow();
        };

        const openEditModal = (task, currentModal = null) => {
            if (!task) return;
            fillEditForm(task);
            if (!window.bootstrap?.Modal) {
                window.addEventListener("load", () => openEditModal(task, currentModal), { once: true });
                return;
            }
            const modalInstance = window.bootstrap.Modal.getOrCreateInstance(modalElement);
            if (currentModal) {
                currentModal.addEventListener("hidden.bs.modal", () => modalInstance.show(), { once: true });
                window.bootstrap.Modal.getOrCreateInstance(currentModal).hide();
            } else {
                modalInstance.show();
            }
        };

        document.querySelectorAll(".report-edit-task").forEach((button) => {
            button.addEventListener("click", (event) => {
                const task = taskMap.get(Number(button.dataset.editTaskId));
                const detailModal = button.closest('[id^="taskModal"]');
                if (detailModal) event.preventDefault();
                openEditModal(task, detailModal);
            });
        });

        locationSelect?.addEventListener("change", updateOtherLocation);
        departmentControl?.addEventListener("change", updateITEditWorkflow);
        solutionControl?.addEventListener("input", updateITEditWorkflow);
        workActionControl?.addEventListener("input", updateITEditWorkflow);
        finishDateControl?.addEventListener("change", updateITEditWorkflow);
        finishTimeControl?.addEventListener("change", updateITEditWorkflow);
        window.addEventListener("load", () => {
            if (recoveryData) {
                openEditModal(recoveryData);
            } else if (initialEditTaskId && taskMap.has(initialEditTaskId)) {
                openEditModal(taskMap.get(initialEditTaskId));
            }
        });
    })();

</script>
<script>
    // Report rows, filters and pagination are evaluated by MySQL so the browser
    // never needs to load every task or create every task modal at once.
    (() => {
        const state = <?php echo json_encode([
            "q" => $report_search,
            "department" => $report_filter_department,
            "status" => $report_filter_status,
            "category" => $report_filter_category,
            "start_date" => $report_filter_start,
            "end_date" => $report_filter_end,
            "per_page" => $report_page_size,
            "page" => $report_page,
            "total_pages" => $report_total_pages,
            "total" => $report_filtered_total,
            "visible_start" => $report_visible_start,
            "visible_end" => $report_visible_end,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const controls = {
            q: document.getElementById("reportSearchInput"),
            department: document.getElementById("reportDepartmentFilter"),
            status: document.getElementById("reportStatusFilter"),
            category: document.getElementById("reportCategoryFilter"),
            start_date: document.getElementById("reportStartDate"),
            end_date: document.getElementById("reportEndDate"),
            per_page: document.getElementById("reportRowsPerPage")
        };
        const searchForm = document.getElementById("reportSearchForm");
        let searchTimer = null;
        let searchIsComposing = false;
        Object.entries(controls).forEach(([key, control]) => {
            if (control) control.value = String(state[key] ?? "");
        });

        const count = document.getElementById("reportFilteredCount");
        if (count) {
            count.textContent = `แสดง ${state.visible_start}-${state.visible_end} จากทั้งหมด ${state.total} รายการ`;
        }

        const applyFilters = () => {
            if (searchTimer) window.clearTimeout(searchTimer);
            const query = new URLSearchParams();
            Object.entries(controls).forEach(([key, control]) => {
                const value = String(control?.value ?? "").trim();
                if (value) query.set(key, value);
            });
            window.location.href = "index.php" + (query.toString() ? "?" + query.toString() : "");
        };

        const scheduleSearch = () => {
            if (searchIsComposing) return;
            if (searchTimer) window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(applyFilters, 650);
        };

        searchForm?.addEventListener("submit", (event) => {
            event.preventDefault();
            applyFilters();
        });
        controls.q?.addEventListener("compositionstart", () => {
            searchIsComposing = true;
            if (searchTimer) window.clearTimeout(searchTimer);
        });
        controls.q?.addEventListener("compositionend", () => {
            searchIsComposing = false;
            scheduleSearch();
        });
        controls.q?.addEventListener("input", scheduleSearch);
        controls.per_page?.addEventListener("change", applyFilters);

        const reset = document.getElementById("resetReportFilters");
        reset?.addEventListener("click", () => {
            ["department", "status", "category", "start_date", "end_date"].forEach((key) => {
                if (controls[key]) controls[key].value = "";
            });
            applyFilters();
        });
        document.getElementById("applyReportFilters")?.addEventListener("click", applyFilters);
    })();
</script>
<script>
    // Image lightbox for task detail pictures: zoom, pan, and close without leaving the page.
    (() => {
        const lightbox = document.getElementById("taskImageLightbox");
        if (!lightbox) return;
        const stage = lightbox.querySelector("[data-lightbox-stage]");
        const image = lightbox.querySelector("[data-lightbox-img]");
        const caption = lightbox.querySelector("[data-lightbox-caption]");
        const zoomInButton = lightbox.querySelector("[data-lightbox-zoom-in]");
        const zoomOutButton = lightbox.querySelector("[data-lightbox-zoom-out]");
        const resetButton = lightbox.querySelector("[data-lightbox-reset]");
        const closeButton = lightbox.querySelector("[data-lightbox-close]");

        const MIN_SCALE = 0.4, MAX_SCALE = 8;
        let scale = 1, offsetX = 0, offsetY = 0;
        let dragging = false, lastX = 0, lastY = 0, movedDuringDrag = false;

        const render = () => {
            image.style.transform = `translate(${offsetX}px, ${offsetY}px) scale(${scale})`;
            stage.classList.toggle("is-pannable", scale > 1);
        };

        // Keep the point under the cursor anchored while the scale changes.
        const zoomAt = (clientX, clientY, factor) => {
            const nextScale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, scale * factor));
            const ratio = nextScale / scale;
            const rect = stage.getBoundingClientRect();
            const pivotX = clientX - rect.left - rect.width / 2;
            const pivotY = clientY - rect.top - rect.height / 2;
            offsetX = pivotX + (offsetX - pivotX) * ratio;
            offsetY = pivotY + (offsetY - pivotY) * ratio;
            scale = nextScale;
            render();
        };

        const resetView = () => { scale = 1; offsetX = 0; offsetY = 0; render(); };

        const open = (src, name) => {
            image.src = src;
            image.alt = name || "รูปภาพประกอบงาน";
            caption.textContent = name || "";
            caption.style.display = name ? "" : "none";
            resetView();
            lightbox.classList.add("is-open");
            closeButton.focus();
        };

        const close = () => {
            lightbox.classList.remove("is-open");
            image.removeAttribute("src");
        };

        // Detail rows are rebuilt dynamically, so listen at the document level.
        document.addEventListener("click", (event) => {
            const link = event.target.closest("a[data-lightbox-image]");
            if (!link) return;
            event.preventDefault();
            open(link.getAttribute("href"), link.dataset.lightboxName || "");
        });

        stage.addEventListener("wheel", (event) => {
            event.preventDefault();
            zoomAt(event.clientX, event.clientY, event.deltaY < 0 ? 1.15 : 1 / 1.15);
        }, { passive: false });

        stage.addEventListener("dblclick", (event) => {
            zoomAt(event.clientX, event.clientY, scale > 1.05 ? 1 / scale : 2.5);
        });

        stage.addEventListener("pointerdown", (event) => {
            if (event.button !== 0) return;
            dragging = true; movedDuringDrag = false;
            lastX = event.clientX; lastY = event.clientY;
            stage.classList.add("is-dragging");
            stage.setPointerCapture(event.pointerId);
        });

        stage.addEventListener("pointermove", (event) => {
            if (!dragging) return;
            const deltaX = event.clientX - lastX, deltaY = event.clientY - lastY;
            if (Math.abs(deltaX) + Math.abs(deltaY) > 2) movedDuringDrag = true;
            offsetX += deltaX; offsetY += deltaY;
            lastX = event.clientX; lastY = event.clientY;
            render();
        });

        const endDrag = () => { dragging = false; stage.classList.remove("is-dragging"); };
        stage.addEventListener("pointerup", endDrag);
        stage.addEventListener("pointercancel", endDrag);

        stage.addEventListener("click", (event) => {
            if (event.target !== stage || movedDuringDrag) return;
            close();
        });

        zoomInButton.addEventListener("click", () => zoomAt(window.innerWidth / 2, window.innerHeight / 2, 1.3));
        zoomOutButton.addEventListener("click", () => zoomAt(window.innerWidth / 2, window.innerHeight / 2, 1 / 1.3));
        resetButton.addEventListener("click", resetView);
        closeButton.addEventListener("click", close);

        document.addEventListener("keydown", (event) => {
            if (!lightbox.classList.contains("is-open") || event.key !== "Escape") return;
            event.preventDefault();
            close();
        });
    })();
</script>
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>
