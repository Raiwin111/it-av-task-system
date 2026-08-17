<?php
require_once __DIR__ . "/../auth/auth_check.php";
require_once __DIR__ . "/../auth/authorization.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/constants.php";
require_once __DIR__ . "/../includes/helpers.php";
require_once __DIR__ . "/../includes/task_activity.php";

// Always request fresh task data after users return from Task Input.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$app_page_title = "รายการงาน | IT / AV Task Management System";
$role = strtoupper($_SESSION["role"] ?? "USER");
$user_id = (int) ($_SESSION["user_id"] ?? 0);
$can_control_task_status = can_manage_all_tasks();
$account_can_modify = is_account_approved();
$active_nav = "report";
$report_task_csrf = $_SESSION["report_task_csrf"] ??= bin2hex(random_bytes(32));
$report_update_error = "";
$report_update_form_data = null;
$report_location_options = ["เมฆา1", "เมฆา2", "เมฆา3", "วารินทร์", "พิมาน"];
$report_status_options = $task_status_options;

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

    if (!$can_update) {
        header("Location: index.php?error=forbidden");
        exit;
    }

    $title = trim(report_post_string("title"));
    $department = can_manage_all_tasks()
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
    if ($department === "IT" && $status === "in_progress") {
        $finish_input_started = false;
        $finish_time = null;
    }
    if ($status === "completed" && !$finish_time && !$finish_input_started) $finish_time = date("Y-m-d H:i:s");

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
        "finish_work_time" => $finish_work_time_value
    ];

    if (!hash_equals($report_task_csrf, report_post_string("csrf_token"))) {
        http_response_code(419);
        $report_update_error = "คำขอแก้ไขหมดอายุ กรุณาลองใหม่อีกครั้ง";
    } elseif (
        $title === ""
        || $it_problem_missing
        || !in_array($department, $departments, true)
        || ($category !== "-" && !array_key_exists($category, $problem_category_options))
        || !array_key_exists($status, $report_status_options)
        || !$start_time
        || ($finish_input_started && !$finish_time)
        || ($finish_time && $finish_time < $start_time)
    ) {
        $report_update_error = $it_problem_missing
            ? "งาน IT จำเป็นต้องระบุปัญหาที่พบ"
            : "กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วนและตรวจสอบช่วงวันที่กับเวลา";
    } else {
        $update_stmt = $conn->prepare("UPDATE tasks SET title = ?, category = ?, department = ?, responsible_name = ?, location = ?, work_description = ?, work_action = ?, problem = ?, solution = ?, status = ?, start_time = ?, finish_time = ?, remark = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND is_deleted = 0");
        $update_stmt->bind_param("sssssssssssssi", $title, $category, $department, $responsible_name, $location, $work_description, $work_action, $problem, $solution, $status, $start_time, $finish_time, $remark, $update_id);
        if ($update_stmt->execute()) {
            $update_stmt->close();
            record_task_update_activities($conn, $update_id, $existing_task, [
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
            if ($problem !== "-") {
                $option_stmt = $conn->prepare("INSERT IGNORE INTO team_problem_options (department, problem_text, created_by) VALUES (?, ?, ?)");
                $option_stmt->bind_param("ssi", $department, $problem, $user_id);
                $option_stmt->execute();
                $option_stmt->close();
            }
            header("Location: index.php?updated=1");
            exit;
        }
        $update_stmt->close();
        $report_update_error = "ไม่สามารถบันทึกการแก้ไขได้ กรุณาลองอีกครั้ง";
    }
}

// Soft delete keeps the row in MySQL while hiding it from active task lists.
if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST" && ($_POST["action"] ?? "") === "delete") {
    if (!hash_equals($report_task_csrf, report_post_string("csrf_token"))) {
        header("Location: index.php?error=csrf");
        exit;
    }

    $delete_id = (int) ($_POST["task_id"] ?? 0);

    $owner_stmt = $conn->prepare("SELECT created_by, department, status FROM tasks WHERE id = ? AND is_deleted = 0 LIMIT 1");
    $owner_stmt->bind_param("i", $delete_id);
    $owner_stmt->execute();
    $delete_task = $owner_stmt->get_result()->fetch_assoc();
    $owner_stmt->close();

    $can_delete = $delete_task && can_delete_task($delete_task);

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
$report_activity_by_task = load_task_activities(
    $conn,
    array_column($task_rows, "id")
);
if ($task_rows) {
    $report_task_ids = implode(",", array_map(static fn($task) => (int) $task["id"], $task_rows));
    $report_image_result = $conn->query("SELECT task_id, file_path, original_name FROM task_images WHERE task_id IN ({$report_task_ids}) ORDER BY created_at ASC, id ASC");
    while ($report_image = $report_image_result->fetch_assoc()) {
        $report_images_by_task[(int) $report_image["task_id"]][] = [
            "file_path" => $report_image["file_path"],
            "original_name" => $report_image["original_name"]
        ];
    }
}
foreach ($task_rows as &$report_task_row) {
    $report_task_row["images"] = $report_images_by_task[(int) $report_task_row["id"]] ?? [];
    $report_task_row["activity_log"] = $report_activity_by_task[(int) $report_task_row["id"]] ?? [];
}
unset($report_task_row);
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
<div class="app-shell d-flex"><?php require_once __DIR__ . "/../includes/app_sidebar.php"; ?><main class="report-page main-content flex-grow-1 p-4 p-lg-5"><?php if (isset($_GET["updated"])): ?><div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i>บันทึกการแก้ไขเรียบร้อยแล้ว</div><?php endif; ?><?php if (isset($_GET["deleted"])): ?><div class="alert alert-success">ลบงานเรียบร้อยแล้ว</div><?php endif; ?><?php if (($_GET["error"] ?? "") === "forbidden"): ?><div class="alert alert-danger">คุณไม่มีสิทธิ์ดำเนินการกับงานนี้</div><?php endif; ?><?php if (($_GET["error"] ?? "") === "csrf"): ?><div class="alert alert-danger">คำขอลบหมดอายุ กรุณาลองใหม่อีกครั้ง</div><?php endif; ?><?php if (!$account_can_modify): ?><div class="alert alert-info"><i class="bi bi-shield-lock me-1"></i>บัญชีอยู่ระหว่างรอผู้ดูแลกำหนดทีมและสิทธิ์ จึงยังไม่สามารถดูข้อมูลงานได้</div><?php endif; ?><?php if ($report_update_error !== ""): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($report_update_error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?><div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-3 mb-4"><div><h1 class="page-heading h3 fw-bold mb-1">รายการงาน</h1><p class="page-subtitle mb-0">Task List สำหรับค้นหา ติดตาม และจัดการงาน IT / AV</p></div><a class="btn btn-primary align-self-start align-self-lg-auto" href="../task_input/"><i class="bi bi-plus-lg me-2"></i>บันทึกงานใหม่</a></div>
<?php if ($report_filter_error !== ""): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($report_filter_error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>
<section class="report-toolbar mb-4" aria-label="เลือกทีม">
    <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
        <div>
            <div class="small fw-bold text-muted mb-2">เลือกทีม</div>
            <nav class="report-team-switch" aria-label="เลือกทีมในรายการงาน">
                <?php if ($report_can_filter_team): ?>
                    <a class="report-team-link<?php echo $report_filter_department === "" ? " active" : ""; ?>" href="<?php echo htmlspecialchars($report_team_url(""), ENT_QUOTES, "UTF-8"); ?>"><i class="bi bi-grid"></i>ทุกทีม</a>
                    <?php foreach ($departments as $department_option): ?>
                        <a class="report-team-link<?php echo $report_filter_department === $department_option ? " active" : ""; ?>" href="<?php echo htmlspecialchars($report_team_url($department_option), ENT_QUOTES, "UTF-8"); ?>"><i class="bi <?php echo $department_option === "IT" ? "bi-pc-display" : "bi-camera-video"; ?>"></i><?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?></a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="report-team-link active"><i class="bi bi-people"></i>ทีม <?php echo htmlspecialchars($report_department, ENT_QUOTES, "UTF-8"); ?></span>
                <?php endif; ?>
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
                        <td><span class="badge rounded-pill <?php echo $class; ?>"><?php echo $label; ?></span></td>
                        <td class="report-mobile-hidden"><?php echo htmlspecialchars($task["created_by_name"], ENT_QUOTES, "UTF-8"); ?></td>
                        <td class="pe-4 text-end"><div class="report-row-actions d-inline-flex gap-1"><button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#taskModal<?php echo $task["id"]; ?>" aria-label="ดูรายละเอียด <?php echo htmlspecialchars($task["title"], ENT_QUOTES, "UTF-8"); ?>"><i class="bi bi-eye"></i><span class="action-label ms-1">รายละเอียด</span></button><?php if ($can_edit): ?><button class="btn btn-sm btn-outline-secondary report-edit-task" type="button" data-edit-task-id="<?php echo $task["id"]; ?>" aria-label="แก้ไข <?php echo htmlspecialchars($task["title"], ENT_QUOTES, "UTF-8"); ?>"><i class="bi bi-pencil"></i><span class="action-label ms-1">แก้ไข</span></button><?php endif; ?><?php if ($can_delete): ?><button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#deleteTaskModal<?php echo $task["id"]; ?>" aria-label="ลบ <?php echo htmlspecialchars($task["title"], ENT_QUOTES, "UTF-8"); ?>"><i class="bi bi-trash"></i><span class="action-label ms-1">ลบ</span></button><?php endif; ?></div></td>
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
            <form method="post" action="" id="reportEditTaskForm">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="task_id" id="reportEditTaskId">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($report_task_csrf, ENT_QUOTES, "UTF-8"); ?>">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="reportEditTaskModalLabel"><i class="bi bi-pencil-square me-2"></i>แก้ไขงาน</h2>
                        <p class="small text-muted mb-0" id="reportEditTaskSubtitle">เลือกงานที่ต้องการแก้ไข</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body">
                    <section class="report-edit-section">
                        <h3 class="h6 fw-bold mb-3"><i class="bi bi-card-checklist me-2"></i>ข้อมูลงาน</h3>
                        <div class="row g-3">
                            <div class="col-lg-8"><label class="form-label" for="reportEditTitle">ชื่องาน <span class="text-danger">*</span></label><input type="text" class="form-control" id="reportEditTitle" name="title" required></div>
                            <div class="col-lg-4">
                                <label class="form-label" for="reportEditDepartment">ทีม <span class="text-danger">*</span></label>
                                <?php if (in_array($role, ["SUPER", "ADMIN"], true)): ?>
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
                    <section class="report-edit-section">
                        <h3 class="h6 fw-bold mb-3" id="reportEditDetailHeading"><i class="bi bi-file-earmark-text me-2"></i>รายละเอียดและการดำเนินงาน</h3>
                        <div class="row g-3">
                            <div class="col-md-6" id="reportEditWorkDescriptionGroup"><label class="form-label" for="reportEditWorkDescription" id="reportEditWorkDescriptionLabel">รายละเอียดงาน</label><textarea class="form-control" id="reportEditWorkDescription" name="work_description" rows="3"></textarea><div class="form-text" id="reportEditWorkDescriptionHint"></div></div>
                            <div class="col-md-6" id="reportEditWorkActionGroup"><label class="form-label" for="reportEditWorkAction" id="reportEditWorkActionLabel">การดำเนินงาน</label><textarea class="form-control" id="reportEditWorkAction" name="work_action" rows="3"></textarea><div class="form-text" id="reportEditWorkActionHint">งาน AV จะเปลี่ยนเป็น “เสร็จสิ้น” อัตโนมัติเมื่อกรอกการดำเนินงานหรือเวลาสิ้นสุด</div></div>
                            <div class="col-md-6"><label class="form-label" for="reportEditProblem">ปัญหาที่พบ <span class="text-danger d-none" id="reportEditProblemRequired">*</span><span class="report-edit-optional d-none" id="reportEditProblemOptional">ไม่บังคับ</span></label><textarea class="form-control" id="reportEditProblem" name="problem" rows="3"></textarea></div>
                            <div class="col-md-6"><label class="form-label" for="reportEditSolution">วิธีแก้ไขปัญหา <span class="report-edit-optional d-none" id="reportEditSolutionOptional">ไม่บังคับ</span></label><textarea class="form-control" id="reportEditSolution" name="solution" rows="3"></textarea><div class="form-text" id="reportEditSolutionHint">งาน IT จะเปลี่ยนเป็น “เสร็จสิ้น” อัตโนมัติเมื่อกรอกวิธีแก้ไข</div></div>
                            <div class="col-12"><label class="form-label" for="reportEditRemark">หมายเหตุ</label><textarea class="form-control" id="reportEditRemark" name="remark" rows="2"></textarea></div>
                        </div>
                    </section>
                    <section class="report-edit-section mb-0">
                        <h3 class="h6 fw-bold mb-3" id="reportEditTimeHeading"><i class="bi bi-clock-history me-2"></i>ระยะเวลาการดำเนินงาน</h3>
                        <div class="row g-3">
                            <div class="col-md-6 col-lg-3"><label class="form-label" for="reportEditStartDate" id="reportEditStartDateLabel">วันเริ่มดำเนินการ <span class="text-danger">*</span></label><input type="text" class="form-control date-picker" id="reportEditStartDate" name="start_date" required></div>
                            <div class="col-md-6 col-lg-3"><label class="form-label" for="reportEditStartTime" id="reportEditStartTimeLabel">เวลาเริ่มงาน <span class="text-danger">*</span></label><input type="text" class="form-control time-picker" id="reportEditStartTime" name="start_work_time" required></div>
                            <div class="col-md-6 col-lg-3"><label class="form-label" for="reportEditFinishDate" id="reportEditFinishDateLabel">วันที่สิ้นสุด</label><input type="text" class="form-control date-picker" id="reportEditFinishDate" name="finish_date"></div>
                            <div class="col-md-6 col-lg-3"><label class="form-label" for="reportEditFinishTime" id="reportEditFinishTimeLabel">เวลาสิ้นสุดงาน</label><input type="text" class="form-control time-picker" id="reportEditFinishTime" name="finish_work_time"></div>
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
                <div>
                    <h2 class="modal-title fs-5" id="reportFilterModalLabel"><i class="bi bi-sliders2 me-2"></i>ตัวกรองรายการงาน</h2>
                    <p class="small text-muted mb-0 mt-1">เลือกเฉพาะเงื่อนไขที่จำเป็น แล้วกดใช้ตัวกรอง</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <section class="report-filter-section" aria-labelledby="reportWorkFilterHeading">
                    <h3 class="report-filter-heading" id="reportWorkFilterHeading">ข้อมูลงาน</h3>
                    <div class="row g-3">
                        <?php if ($report_can_filter_team): ?>
                            <div class="col-md-6"><label class="form-label" for="reportDepartmentFilter">ทีม</label><select class="form-select" id="reportDepartmentFilter"><option value="">ทุกทีม</option><?php foreach ($departments as $item): ?><option value="<?php echo htmlspecialchars($item, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($item, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div>
                        <?php endif; ?>
                        <div class="col-md-6"><label class="form-label" for="reportStatusFilter">สถานะ</label><select class="form-select" id="reportStatusFilter"><option value="">ทุกสถานะ</option><?php foreach ($report_status_options as $value => $item): ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($item, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div>
                        <div class="col-12"><label class="form-label" for="reportCategoryFilter">ประเภทปัญหา</label><select class="form-select" id="reportCategoryFilter"><option value="">ทุกประเภท</option><?php foreach ($problem_category_options as $value => $item): ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($item, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div>
                    </div>
                </section>
                <section class="report-filter-section mb-0" aria-labelledby="reportDateFilterHeading">
                    <h3 class="report-filter-heading" id="reportDateFilterHeading">ช่วงวันที่สร้างงาน</h3>
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
<?php foreach ($task_rows as $task): ?>
<?php [$label, $class] = status_meta($task["status"]); ?>
<?php $can_edit = can_edit_task($task); ?>
<?php $can_delete = can_delete_task($task); ?>
<?php
$task_is_it = strtoupper((string) $task["department"]) === "IT";
$task_has_category = !in_array(trim((string) $task["category"]), ["", "-"], true);
$task_has_work_description = !in_array(trim((string) $task["work_description"]), ["", "-"], true);
$task_has_work_action = !in_array(trim((string) $task["work_action"]), ["", "-"], true);
$task_has_problem = !in_array(trim((string) $task["problem"]), ["", "-"], true);
$task_has_solution = !in_array(trim((string) $task["solution"]), ["", "-"], true);
$task_has_remark = !in_array(trim((string) $task["remark"]), ["", "-"], true);
$task_duration = report_task_duration($task["start_time"], $task["finish_time"]);
?>
<div class="modal fade task-details-modal" id="taskModal<?php echo $task["id"]; ?>" tabindex="-1" aria-labelledby="taskModalLabel<?php echo $task["id"]; ?>" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="task-details-heading">
                    <span class="task-details-kicker"><i class="bi bi-card-text me-1"></i>รายละเอียดงาน</span>
                    <h2 class="modal-title" id="taskModalLabel<?php echo $task["id"]; ?>"><?php echo htmlspecialchars($task["title"], ENT_QUOTES, "UTF-8"); ?></h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <section class="task-detail-section" aria-labelledby="taskSummaryHeading<?php echo $task["id"]; ?>">
                    <h3 class="task-detail-section-heading" id="taskSummaryHeading<?php echo $task["id"]; ?>"><i class="bi bi-info-circle"></i>ข้อมูลหลัก</h3>
                    <div class="row g-4 task-detail-summary">
                        <div class="col-md-6 task-detail-item" data-detail-field="title"><strong class="task-detail-label">ชื่องาน</strong><div class="task-detail-value"><?php echo htmlspecialchars($task["title"], ENT_QUOTES, "UTF-8"); ?></div></div>
                        <div class="col-6 col-md-3 task-detail-item" data-detail-field="department"><strong class="task-detail-label">ทีม</strong><div class="task-detail-value"><?php echo htmlspecialchars($task["department"], ENT_QUOTES, "UTF-8"); ?></div></div>
                        <div class="col-6 col-md-3 task-detail-item" data-detail-field="status"><strong class="task-detail-label">สถานะ</strong><div class="task-detail-value"><span class="badge rounded-pill <?php echo $class; ?>"><?php echo $label; ?></span></div></div>
                        <?php if ($task_is_it || $task_has_category): ?><div class="col-md-6 task-detail-item" data-detail-field="category"><strong class="task-detail-label">ประเภทปัญหา</strong><div class="task-detail-value"><?php echo htmlspecialchars($problem_category_options[$task["category"]] ?? $task["category"], ENT_QUOTES, "UTF-8"); ?></div></div><?php endif; ?>
                        <div class="col-md-6 task-detail-item" data-detail-field="location"><strong class="task-detail-label">สถานที่</strong><div class="task-detail-value"><?php echo htmlspecialchars($task["location"], ENT_QUOTES, "UTF-8"); ?></div></div>
                    </div>
                </section>

                <?php if ($task_has_work_description || $task_has_work_action || $task_has_remark): ?>
                    <section class="task-detail-section" aria-labelledby="taskNarrativeHeading<?php echo $task["id"]; ?>">
                        <h3 class="task-detail-section-heading" id="taskNarrativeHeading<?php echo $task["id"]; ?>"><i class="bi bi-journal-text"></i><?php echo $task_is_it ? "รายละเอียดงาน" : "รายละเอียดกิจกรรมและอุปกรณ์"; ?></h3>
                        <div class="task-detail-narrative">
                            <?php if ($task_has_work_description): ?><div class="task-detail-field" data-detail-field="work-description"><strong class="task-detail-label"><?php echo $task_is_it ? "รายละเอียดงาน" : "รายละเอียดกิจกรรมและอุปกรณ์ที่ใช้งาน"; ?></strong><div class="task-detail-value"><?php echo nl2br(htmlspecialchars($task["work_description"], ENT_QUOTES, "UTF-8")); ?></div></div><?php endif; ?>
                            <?php if ($task_has_work_action): ?><div class="task-detail-field" data-detail-field="work-action"><strong class="task-detail-label"><?php echo $task_is_it ? "การดำเนินงาน" : "สรุปการดำเนินงาน"; ?></strong><div class="task-detail-value"><?php echo nl2br(htmlspecialchars($task["work_action"], ENT_QUOTES, "UTF-8")); ?></div></div><?php endif; ?>
                            <?php if ($task_has_remark): ?><div class="task-detail-field" data-detail-field="remark"><strong class="task-detail-label">หมายเหตุ</strong><div class="task-detail-value"><?php echo nl2br(htmlspecialchars($task["remark"], ENT_QUOTES, "UTF-8")); ?></div></div><?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($task_is_it || $task_has_problem): ?>
                    <section class="task-detail-section task-detail-section--problem" aria-labelledby="taskProblemHeading<?php echo $task["id"]; ?>">
                        <h3 class="task-detail-section-heading" id="taskProblemHeading<?php echo $task["id"]; ?>"><i class="bi bi-exclamation-circle"></i>ปัญหาที่พบ</h3>
                        <div class="task-detail-value<?php echo $task_has_problem ? "" : " task-detail-empty"; ?>" data-detail-field="problem"><?php echo $task_has_problem ? nl2br(htmlspecialchars($task["problem"], ENT_QUOTES, "UTF-8")) : "ยังไม่มีข้อมูลปัญหา"; ?></div>
                    </section>
                <?php endif; ?>

                <?php if ($task_is_it || $task_has_solution): ?>
                    <section class="task-detail-section task-detail-section--solution" aria-labelledby="taskSolutionHeading<?php echo $task["id"]; ?>">
                        <h3 class="task-detail-section-heading" id="taskSolutionHeading<?php echo $task["id"]; ?>"><i class="bi bi-check2-circle"></i>วิธีแก้ไขปัญหา</h3>
                        <div class="task-detail-value<?php echo $task_has_solution ? "" : " task-detail-empty"; ?>" data-detail-field="solution"><?php echo $task_has_solution ? nl2br(htmlspecialchars($task["solution"], ENT_QUOTES, "UTF-8")) : "ยังไม่ได้บันทึกวิธีแก้ไข"; ?></div>
                    </section>
                <?php endif; ?>

                <section class="task-detail-section" aria-labelledby="taskTimelineHeading<?php echo $task["id"]; ?>">
                    <h3 class="task-detail-section-heading" id="taskTimelineHeading<?php echo $task["id"]; ?>"><i class="bi bi-clock-history"></i>เวลาและผู้รับผิดชอบ</h3>
                    <div class="row g-4 task-detail-meta">
                        <div class="col-sm-6 col-lg-4 task-detail-item"><strong class="task-detail-label">เวลาเริ่มดำเนินการ</strong><div class="task-detail-value"><?php echo thai_date_time($task["start_time"]); ?></div></div>
                        <div class="col-sm-6 col-lg-4 task-detail-item"><strong class="task-detail-label">เวลาสิ้นสุด</strong><div class="task-detail-value"><?php echo thai_date_time($task["finish_time"]); ?></div></div>
                        <?php if ($task_duration !== null): ?><div class="col-sm-6 col-lg-4 task-detail-item" data-detail-field="duration"><strong class="task-detail-label">ระยะเวลาดำเนินการ</strong><div class="task-detail-value"><?php echo htmlspecialchars($task_duration, ENT_QUOTES, "UTF-8"); ?></div></div><?php endif; ?>
                        <div class="col-sm-6 col-lg-3 task-detail-item"><strong class="task-detail-label">ผู้รับผิดชอบ</strong><div class="task-detail-value"><?php echo htmlspecialchars(trim((string) ($task["responsible_name"] ?? "")) ?: $task["created_by_name"], ENT_QUOTES, "UTF-8"); ?></div></div>
                        <div class="col-sm-6 col-lg-3 task-detail-item"><strong class="task-detail-label">อัปเดตล่าสุด</strong><div class="task-detail-value"><?php echo thai_date_time($task["updated_at"]); ?></div></div>
                        <div class="col-12 task-detail-created"><span>สร้างเมื่อ <?php echo thai_date_time($task["created_at"]); ?></span></div>
                    </div>
                </section>

                <div class="task-details-extra"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
                <?php if ($can_edit): ?><button class="btn btn-primary report-edit-task" type="button" data-edit-task-id="<?php echo $task["id"]; ?>"><i class="bi bi-pencil-square me-1"></i>แก้ไขงาน</button><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php foreach ($task_rows as $task): ?><?php if (can_delete_task($task)): ?>
<div class="modal fade" id="deleteTaskModal<?php echo $task["id"]; ?>" tabindex="-1" aria-labelledby="deleteTaskModalLabel<?php echo $task["id"]; ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title fs-5" id="deleteTaskModalLabel<?php echo $task["id"]; ?>">ยืนยันการลบงาน</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">คุณต้องการลบงานนี้หรือไม่ ?</div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button><form method="post" action="" class="m-0"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($report_task_csrf, ENT_QUOTES, "UTF-8"); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="task_id" value="<?php echo $task["id"]; ?>"><button type="submit" class="btn btn-danger">ลบงาน</button></form></div>
    </div></div>
</div>
<?php endif; ?><?php endforeach; ?></main></div>
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
    .report-filter-modal .modal-content { border: 1px solid #d6e0ea; border-radius: .9rem; overflow: hidden; }
    .report-filter-modal .modal-header { align-items: flex-start; background: #f8fafc; }
    .report-filter-section { padding-bottom: 1.15rem; margin-bottom: 1.15rem; border-bottom: 1px solid #e5ebf1; }
    .report-filter-section:last-child { padding-bottom: 0; border-bottom: 0; }
    .report-filter-heading { margin: 0 0 .85rem; color: #244b70; font-size: .95rem; font-weight: 700; }

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
    .task-details-modal .modal-body { min-height: 0; padding: 0 1.5rem; overflow-y: auto; overscroll-behavior: contain; color: #334155; background: #fff; }
    .task-detail-section { padding: 1.25rem 0; border-bottom: 1px solid #e8edf3; }
    .task-detail-section:last-child { border-bottom: 0; }
    .task-detail-section-heading { display: flex; align-items: center; gap: .55rem; margin: 0 0 1rem; color: #153b63; font-size: .94rem; font-weight: 700; }
    .task-detail-section-heading i { color: #1769c2; font-size: 1rem; }
    .task-detail-item { min-width: 0; }
    .task-detail-label { display: block; margin-bottom: .35rem; color: #64748b; font-size: .78rem; font-weight: 600; line-height: 1.35; }
    .task-detail-value { overflow-wrap: anywhere; color: #1e293b; font-size: .94rem; line-height: 1.65; }
    .task-detail-value--multiline { white-space: pre-wrap; }
    .task-detail-summary .task-detail-value { font-weight: 500; }
    .task-detail-field + .task-detail-field { margin-top: 1.1rem; padding-top: 1.1rem; border-top: 1px dashed #dbe3ec; }
    .task-detail-field .task-detail-value { white-space: normal; }
    .task-detail-section--problem .task-detail-section-heading i { color: #c2410c; }
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
    .task-details-modal .task-activity-empty { color: #708398; font-size: .9rem; }
    .task-details-modal .modal-footer { flex: 0 0 auto; gap: .5rem; padding: .9rem 1.5rem; border-top: 1px solid #e2e8f0; background: #f8fafc; }
    @media (max-width: 991.98px) { .task-details-modal .modal-dialog { max-width: calc(100% - 2rem); } }
    @media (max-width: 575.98px) {
        .task-details-modal .modal-dialog { max-width: none; min-height: calc(100% - 1rem); margin: .5rem; }
        .task-details-modal .modal-content { max-height: calc(100dvh - 1rem); border-radius: .8rem; }
        .task-details-modal .modal-header, .task-details-modal .modal-footer { padding: .9rem 1rem; }
        .task-details-modal .modal-body { padding: 0 1rem; }
        .task-detail-section { padding: 1rem 0; }
        .task-detail-section-heading { margin-bottom: .85rem; }
        .task-details-modal .modal-footer .btn { flex: 1 1 auto; }
    }
    .report-edit-modal .modal-content { max-height: calc(100vh - 2rem); overflow: hidden; border: 1px solid #c4d4e2; border-radius: 1rem; box-shadow: 0 18px 48px rgba(15, 35, 57, .2); }
    .report-edit-modal #reportEditTaskForm { display: flex; flex: 1 1 auto; min-height: 0; flex-direction: column; overflow: hidden; }
    .report-edit-modal .modal-header { padding: 1rem 1.35rem; color: #153b63; border-bottom: 1px solid #bfd0df; background: #e7f0f8; }
    .report-edit-modal .modal-body { min-height: 0; padding: 1.25rem; overflow-y: auto; overscroll-behavior: contain; background: #eef3f7; }
    .report-edit-modal .modal-footer { padding: .9rem 1.35rem; border-top: 1px solid #bfd0df; background: #e9f1f7; }
    .report-edit-section { padding: 1rem; margin-bottom: 1rem; border: 1px solid #c5d5e2; border-radius: .8rem; background: #f9fbfd; }
    .report-edit-section h3 { color: #1b4f7f; }
    .report-edit-modal textarea { resize: vertical; }
    .report-edit-modal .task-auto-status { display: flex; min-height: 38px; align-items: center; gap: .7rem; padding: .45rem .65rem; border: 1px solid #c9d9e7; border-radius: .55rem; background: #f5f9fc; }
    .report-edit-modal .task-auto-status small { color: #5f7387; line-height: 1.35; }
    .report-edit-optional { display: inline-flex; margin-left: .35rem; padding: .12rem .42rem; color: #64748b; border-radius: 999px; background: #e8eef4; font-size: .7rem; font-weight: 600; vertical-align: middle; }
    @media (max-width: 575.98px) { .report-edit-modal .modal-dialog { min-height: calc(100% - 1rem); margin: .5rem; } .report-edit-modal .modal-content { max-height: calc(100vh - 1rem); } .report-edit-modal .modal-body { padding: .85rem; } .report-edit-section { padding: .85rem; } }
</style>
<script>
    // Optional values stay readable without resembling disabled form controls.
    document.querySelectorAll(".task-details-modal .task-detail-value").forEach((field) => {
        if (!field.textContent.trim()) field.textContent = "-";
    });

    const taskWorkDetails = <?php echo json_encode($task_rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    taskWorkDetails.forEach((task) => {
        const modal = document.getElementById(`taskModal${task.id}`);
        const modalTitle = modal?.querySelector(".modal-title");
        if (modalTitle) modalTitle.textContent = task.title;

        const extraContainer = modal?.querySelector(".task-details-extra");
        if (!modal || !extraContainer) return;

        const locationValue = modal.querySelector('[data-detail-field="location"] .task-detail-value');
        if (locationValue && !String(task.location || "").trim()) locationValue.textContent = "-";

        const createSectionHeading = (label, iconClass) => {
            const heading = document.createElement("h3");
            heading.className = "task-detail-section-heading";
            const icon = document.createElement("i");
            icon.className = `bi ${iconClass}`;
            heading.append(icon, document.createTextNode(label));
            return heading;
        };

        if (Array.isArray(task.images) && task.images.length) {
            const imageField = document.createElement("section");
            imageField.className = "task-detail-section";
            const imageHeading = createSectionHeading("รูปภาพประกอบงาน", "bi-images");
            const imageGrid = document.createElement("div");
            imageGrid.className = "row g-3 task-image-grid";
            task.images.forEach((image) => {
                const column = document.createElement("div");
                column.className = "col-6 col-md-3";
                const link = document.createElement("a");
                link.className = "task-image-link d-block text-decoration-none";
                link.href = `../${image.file_path}`;
                link.target = "_blank";
                link.rel = "noopener";
                const thumbnail = document.createElement("img");
                thumbnail.src = `../${image.file_path}`;
                thumbnail.alt = image.original_name;
                const caption = document.createElement("span");
                caption.className = "d-block small text-truncate p-2";
                caption.textContent = image.original_name;
                link.append(thumbnail, caption);
                column.append(link);
                imageGrid.append(column);
            });
            imageField.append(imageHeading, imageGrid);
            extraContainer.append(imageField);
        }

        if (Array.isArray(task.activity_log) && task.activity_log.length) {
            const activityField = document.createElement("section");
            activityField.className = "task-detail-section task-activity-panel";
            const activityHeading = createSectionHeading("ประวัติการเปลี่ยนแปลง", "bi-clock-history");
            const activityList = document.createElement("div");
            activityList.className = "task-activity-list";
            const activityIcons = {
                created: "bi-plus-lg",
                updated: "bi-pencil",
                status_changed: "bi-arrow-repeat",
                deleted: "bi-trash"
            };
            task.activity_log.forEach((activity) => {
                const item = document.createElement("div");
                item.className = "task-activity-item";
                const icon = document.createElement("span");
                icon.className = "task-activity-icon";
                const iconGlyph = document.createElement("i");
                iconGlyph.className = `bi ${activityIcons[activity.event_type] || "bi-clock-history"}`;
                icon.append(iconGlyph);
                const content = document.createElement("div");
                const description = document.createElement("div");
                description.className = "fw-semibold";
                description.textContent = activity.description;
                const meta = document.createElement("div");
                meta.className = "task-activity-meta";
                meta.textContent = `${activity.actor_name || "ระบบ"} · ${thaiDate(activity.created_at)}`;
                content.append(description, meta);
                item.append(icon, content);
                activityList.append(item);
            });
            activityField.append(activityHeading, activityList);
            extraContainer.append(activityField);
        }
    });

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
        const canControlTaskStatus = <?php echo json_encode($can_control_task_status); ?>;

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

        const fillCurrentFinishTime = () => {
            const now = new Date();
            const finishDate = field("reportEditFinishDate");
            const finishTime = field("reportEditFinishTime");
            if (finishDate && !finishDate.value) setPickerValue(finishDate, `${String(now.getDate()).padStart(2, "0")}/${String(now.getMonth() + 1).padStart(2, "0")}/${now.getFullYear() + 543}`, "d/m/Y");
            if (finishTime && !finishTime.value) setPickerValue(finishTime, `${String(now.getHours()).padStart(2, "0")}:${String(now.getMinutes()).padStart(2, "0")}`, "H:i");
        };

        const clearFinishTime = () => {
            setPickerValue(field("reportEditFinishDate"), "", "d/m/Y");
            setPickerValue(field("reportEditFinishTime"), "", "H:i");
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
                fillCurrentFinishTime();
            } else if (isIT && !canControlTaskStatus && statusControl.value !== "cancelled") {
                statusControl.value = "in_progress";
                clearFinishTime();
            } else if (isAV && !canControlTaskStatus && statusControl.value !== "cancelled") {
                statusControl.value = hasWorkAction || hasFinishTime ? "completed" : "in_progress";
                if (statusControl.value === "completed" && hasWorkAction && !hasFinishTime) fillCurrentFinishTime();
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
            const modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
            if (currentModal) {
                currentModal.addEventListener("hidden.bs.modal", () => modalInstance.show(), { once: true });
                bootstrap.Modal.getOrCreateInstance(currentModal).hide();
            } else {
                modalInstance.show();
            }
        };

        document.querySelectorAll(".report-edit-task").forEach((button) => {
            button.addEventListener("click", () => {
                const task = taskMap.get(Number(button.dataset.editTaskId));
                const detailModal = button.closest('[id^="taskModal"]');
                openEditModal(task, detailModal);
            });
        });

        locationSelect?.addEventListener("change", updateOtherLocation);
        departmentControl?.addEventListener("change", updateITEditWorkflow);
        solutionControl?.addEventListener("input", updateITEditWorkflow);
        workActionControl?.addEventListener("input", updateITEditWorkflow);
        finishDateControl?.addEventListener("change", updateITEditWorkflow);
        finishTimeControl?.addEventListener("change", updateITEditWorkflow);
        field("reportEditStatus")?.addEventListener("change", (event) => {
            if (event.target.value !== "completed") return;
            fillCurrentFinishTime();
        });

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
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>
