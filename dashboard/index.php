<?php
require_once __DIR__ . "/../auth/auth_check.php";
require_once __DIR__ . "/../auth/authorization.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/constants.php";
require_once __DIR__ . "/../includes/helpers.php";
require_once __DIR__ . "/../includes/task_activity.php";

$dashboard_counts = ["total" => 0, "pending" => 0, "in_progress" => 0, "completed" => 0, "cancelled" => 0];
$dashboard_role = strtoupper($_SESSION["role"] ?? "USER");
$dashboard_user_id = (int) ($_SESSION["user_id"] ?? 0);
$dashboard_is_view_only = !is_account_approved();
$dashboard_department = $conn->real_escape_string((string) ($_SESSION["department"] ?? ""));
$active_nav = "dashboard";

function dashboard_filter_date(string $value, bool $end_of_day = false): ?string
{
    $value = trim($value);
    if ($value === "") return null;
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $matches)) {
        $year = (int) $matches[3];
        if ($year > 2400) $year -= 543;
        if (!checkdate((int) $matches[2], (int) $matches[1], $year)) return null;
        return sprintf("%04d-%02d-%02d %s", $year, $matches[2], $matches[1], $end_of_day ? "23:59:59" : "00:00:00");
    }
    return null;
}

// Dashboard team scope: SUPER/ADMIN may inspect all teams; USER stays inside its assigned team.
$dashboard_can_filter_team = can_manage_all_tasks();
$dashboard_requested_team = is_string($_GET["team"] ?? null) ? $_GET["team"] : "";
$dashboard_requested_status = is_string($_GET["status"] ?? null) ? $_GET["status"] : "";
$dashboard_requested_category = is_string($_GET["category"] ?? null) ? $_GET["category"] : "";
$dashboard_filter_team = $dashboard_can_filter_team && in_array($dashboard_requested_team, $departments, true) ? $dashboard_requested_team : "";
$dashboard_filter_status = array_key_exists($dashboard_requested_status, $task_status_options) ? $dashboard_requested_status : "";
$dashboard_filter_category = array_key_exists($dashboard_requested_category, $problem_category_options) ? $dashboard_requested_category : "";
$dashboard_filter_start = is_string($_GET["start_date"] ?? null) ? trim($_GET["start_date"]) : "";
$dashboard_filter_end = is_string($_GET["end_date"] ?? null) ? trim($_GET["end_date"]) : "";
$filter_start_sql = dashboard_filter_date($dashboard_filter_start);
$filter_end_sql = dashboard_filter_date($dashboard_filter_end, true);
$dashboard_filter_errors = [];
$dashboard_date_range_invalid = false;
if ($dashboard_filter_start !== "" && !$filter_start_sql) {
    $dashboard_filter_errors[] = "รูปแบบวันที่เริ่มต้นไม่ถูกต้อง";
}
if ($dashboard_filter_end !== "" && !$filter_end_sql) {
    $dashboard_filter_errors[] = "รูปแบบวันที่สิ้นสุดไม่ถูกต้อง";
}
if ($filter_start_sql && $filter_end_sql && $filter_start_sql > $filter_end_sql) {
    $dashboard_filter_errors[] = "วันที่เริ่มต้นต้องไม่อยู่หลังวันที่สิ้นสุด";
    $dashboard_date_range_invalid = true;
}
$dashboard_date_filters_valid = $dashboard_filter_errors === [];

// Build one server-side scope reused by every Dashboard query.
// Previous logic generated the table-alias version with str_replace().
// Explicit condition arrays preserve the same behavior and prevent future field-name replacement mistakes.
$dashboard_conditions = $dashboard_is_view_only ? ["1 = 0"] : ["is_deleted = 0"];
$dashboard_conditions_t = $dashboard_is_view_only ? ["1 = 0"] : ["t.is_deleted = 0"];
if (!$dashboard_is_view_only && !$dashboard_can_filter_team) {
    $dashboard_conditions[] = "department = '{$dashboard_department}'";
    $dashboard_conditions_t[] = "t.department = '{$dashboard_department}'";
} elseif (!$dashboard_is_view_only && $dashboard_filter_team !== "") {
    $team_sql = $conn->real_escape_string($dashboard_filter_team);
    $dashboard_conditions[] = "department = '{$team_sql}'";
    $dashboard_conditions_t[] = "t.department = '{$team_sql}'";
}
if ($dashboard_filter_status !== "") {
    $status_sql = $conn->real_escape_string($dashboard_filter_status);
    $dashboard_conditions[] = "status = '{$status_sql}'";
    $dashboard_conditions_t[] = "t.status = '{$status_sql}'";
}
if ($dashboard_filter_category !== "") {
    $category_sql = $conn->real_escape_string($dashboard_filter_category);
    $dashboard_conditions[] = "category = '{$category_sql}'";
    $dashboard_conditions_t[] = "t.category = '{$category_sql}'";
}
if ($dashboard_date_filters_valid && $filter_start_sql) {
    $start_sql = $conn->real_escape_string($filter_start_sql);
    $dashboard_conditions[] = "start_time >= '{$start_sql}'";
    $dashboard_conditions_t[] = "t.start_time >= '{$start_sql}'";
}
if ($dashboard_date_filters_valid && $filter_end_sql) {
    $end_sql = $conn->real_escape_string($filter_end_sql);
    $dashboard_conditions[] = "start_time <= '{$end_sql}'";
    $dashboard_conditions_t[] = "t.start_time <= '{$end_sql}'";
}
$dashboard_task_where = implode(" AND ", $dashboard_conditions);
$dashboard_task_where_t = implode(" AND ", $dashboard_conditions_t);
$dashboard_team_conditions = $dashboard_can_filter_team
    ? array_values(array_filter($dashboard_conditions, static fn(string $condition): bool => !str_starts_with($condition, "department = ")))
    : $dashboard_conditions;
$dashboard_team_where = implode(" AND ", $dashboard_team_conditions);
$dashboard_filter_query = array_filter([
    "team" => $dashboard_filter_team,
    "status" => $dashboard_filter_status,
    "category" => $dashboard_filter_category,
    "start_date" => $dashboard_date_filters_valid && $filter_start_sql ? $dashboard_filter_start : "",
    "end_date" => $dashboard_date_filters_valid && $filter_end_sql ? $dashboard_filter_end : ""
], static fn($value) => $value !== "");
$dashboard_report_query = $dashboard_filter_query;
if (isset($dashboard_report_query["team"])) {
    $dashboard_report_query["department"] = $dashboard_report_query["team"];
    unset($dashboard_report_query["team"]);
}
$dashboard_report_url = "../report/" . ($dashboard_report_query ? "?" . http_build_query($dashboard_report_query) : "");
$dashboard_active_filter_labels = [];
if ($dashboard_filter_team !== "") $dashboard_active_filter_labels[] = "ทีม: " . $dashboard_filter_team;
if ($dashboard_filter_status !== "") $dashboard_active_filter_labels[] = "สถานะ: " . ($task_status_options[$dashboard_filter_status] ?? $dashboard_filter_status);
if ($dashboard_filter_category !== "") $dashboard_active_filter_labels[] = "ประเภทปัญหา: " . ($problem_category_options[$dashboard_filter_category] ?? $dashboard_filter_category);
if ($dashboard_date_filters_valid && $dashboard_filter_start !== "") $dashboard_active_filter_labels[] = "ตั้งแต่: " . $dashboard_filter_start;
if ($dashboard_date_filters_valid && $dashboard_filter_end !== "") $dashboard_active_filter_labels[] = "ถึง: " . $dashboard_filter_end;
$count_result = $conn->query("SELECT status, COUNT(*) AS total FROM tasks WHERE {$dashboard_task_where} GROUP BY status");
while ($row = $count_result->fetch_assoc()) {
    $key = strtolower(str_replace(" ", "_", trim($row["status"])));
    $key = ["รอดำเนินการ" => "pending", "กำลังดำเนินการ" => "in_progress", "เสร็จสิ้น" => "completed", "ยกเลิก" => "cancelled"][$key] ?? $key;
    $dashboard_counts["total"] += (int) $row["total"];
    if (isset($dashboard_counts[$key])) $dashboard_counts[$key] += (int) $row["total"];
}
// All users can view recent tasks. Pagination keeps the dashboard table compact.
$recent_tasks_per_page = 5;
$recent_task_total_result = $conn->query("SELECT COUNT(*) AS total FROM tasks WHERE {$dashboard_task_where}");
$recent_task_total = (int) ($recent_task_total_result->fetch_assoc()["total"] ?? 0);
$recent_task_total_pages = max(1, (int) ceil($recent_task_total / $recent_tasks_per_page));
$recent_task_page_value = $_GET["recent_page"] ?? 1;
$recent_task_page = max(1, is_scalar($recent_task_page_value) ? (int) $recent_task_page_value : 1);
$recent_task_page = min($recent_task_page, $recent_task_total_pages);
$recent_task_offset = ($recent_task_page - 1) * $recent_tasks_per_page;
$recent_tasks = $conn->query("SELECT t.*, COALESCE(NULLIF(t.responsible_name, ''), u.department, '-') AS created_by_name FROM tasks t LEFT JOIN users u ON u.id = t.created_by WHERE {$dashboard_task_where_t} ORDER BY t.created_at DESC, t.id DESC LIMIT {$recent_tasks_per_page} OFFSET {$recent_task_offset}");
$recent_task_rows = $recent_tasks->fetch_all(MYSQLI_ASSOC);
$recent_activity_by_task = load_task_activities($conn, array_column($recent_task_rows, "id"));
$recent_image_stmt = $conn->prepare("SELECT file_path, original_name FROM task_images WHERE task_id = ? ORDER BY created_at ASC, id ASC");
foreach ($recent_task_rows as &$recent_task_row) {
    $recent_task_id = (int) $recent_task_row["id"];
    $recent_image_stmt->bind_param("i", $recent_task_id);
    $recent_image_stmt->execute();
    $recent_task_row["images"] = $recent_image_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recent_task_row["activity_log"] = $recent_activity_by_task[$recent_task_id] ?? [];
    $recent_task_row["can_edit"] = can_edit_task($recent_task_row);
}
$dashboard_counts["active"] = $dashboard_counts["pending"] + $dashboard_counts["in_progress"];
$dashboard_status_percentages = [];
foreach (["pending", "in_progress", "completed"] as $status_key) {
    $dashboard_status_percentages[$status_key] = $dashboard_counts["total"] > 0
        ? (int) round(($dashboard_counts[$status_key] / $dashboard_counts["total"]) * 100)
        : 0;
}

$dashboard_team_counts = [];
foreach ($departments as $department_option) {
    $dashboard_team_counts[$department_option] = [
        "total" => 0,
        "pending" => 0,
        "in_progress" => 0,
        "completed" => 0,
        "active" => 0,
    ];
}
$team_count_result = $conn->query(
    "SELECT department, status, COUNT(*) AS total
     FROM tasks
     WHERE {$dashboard_team_where}
     GROUP BY department, status"
);
while ($team_count_row = $team_count_result->fetch_assoc()) {
    $team_name = (string) $team_count_row["department"];
    if (!isset($dashboard_team_counts[$team_name])) continue;
    $status_key = strtolower(str_replace(" ", "_", trim((string) $team_count_row["status"])));
    $status_key = ["รอดำเนินการ" => "pending", "กำลังดำเนินการ" => "in_progress", "เสร็จสิ้น" => "completed"][$status_key] ?? $status_key;
    $count = (int) $team_count_row["total"];
    $dashboard_team_counts[$team_name]["total"] += $count;
    if (isset($dashboard_team_counts[$team_name][$status_key])) {
        $dashboard_team_counts[$team_name][$status_key] += $count;
    }
}
foreach ($dashboard_team_counts as &$team_counts) {
    $team_counts["active"] = $team_counts["pending"] + $team_counts["in_progress"];
}
unset($team_counts);
$dashboard_all_team_total = array_sum(array_column($dashboard_team_counts, "total"));

$dashboard_common_tasks = array_fill_keys($departments, []);
$common_task_result = $conn->query(
    "SELECT department, title, COUNT(*) AS occurrence_count, MAX(created_at) AS latest_created_at
     FROM tasks
     WHERE {$dashboard_team_where} AND TRIM(title) <> ''
     GROUP BY department, title
     ORDER BY department ASC, occurrence_count DESC, latest_created_at DESC"
);
while ($common_task_row = $common_task_result->fetch_assoc()) {
    $team_name = (string) $common_task_row["department"];
    if (!isset($dashboard_common_tasks[$team_name]) || count($dashboard_common_tasks[$team_name]) >= 5) continue;
    $dashboard_common_tasks[$team_name][] = [
        "title" => (string) $common_task_row["title"],
        "count" => (int) $common_task_row["occurrence_count"],
    ];
}
$dashboard_common_visible_teams = $dashboard_can_filter_team
    ? $departments
    : array_values(array_filter($departments, static fn(string $department): bool => $department === (string) ($_SESSION["department"] ?? "")));
$dashboard_common_initial_team = in_array($dashboard_filter_team, $dashboard_common_visible_teams, true)
    ? $dashboard_filter_team
    : ($dashboard_common_visible_teams[0] ?? "IT");

unset($recent_task_row);
$recent_image_stmt->close();
$recent_tasks->data_seek(0);
$dashboard_scope_label = $dashboard_can_filter_team
    ? ($dashboard_filter_team !== "" ? "ทีม " . $dashboard_filter_team : "ทุกทีม")
    : "ทีม " . ($_SESSION["department"] ?? "-");
function dashboard_status_meta(string $status): array { return task_status_meta($status); }
// Historical task counts are real database data; the selected chart range is changed in the browser.
$trend_data = ["day" => [], "week" => [], "month" => [], "year" => []];
$thai_weekdays = [1 => "จันทร์", 2 => "อังคาร", 3 => "พุธ", 4 => "พฤหัสบดี", 5 => "ศุกร์", 6 => "เสาร์", 7 => "อาทิตย์"];
$thai_date = static function (int $time): string { return date("d/m/", $time) . (date("Y", $time) + 543); };
$trend_range_text = [];
$oldest_task_result = $conn->query("SELECT MIN(start_time) AS oldest_created_at FROM tasks WHERE {$dashboard_task_where}");
$oldest_task_row = $oldest_task_result->fetch_assoc();
$history_start_day = !empty($oldest_task_row["oldest_created_at"])
    ? strtotime(date("Y-m-d", strtotime($oldest_task_row["oldest_created_at"])))
    : strtotime(date("Y-m-d"));
$today_start = strtotime(date("Y-m-d"));
$current_week_start = strtotime("monday this week", $today_start);
$current_week_end = strtotime("+6 days", $current_week_start);
$daily_keys = [];
for ($day_time = $current_week_start; $day_time <= $current_week_end; $day_time = strtotime("+1 day", $day_time)) {
    $date_key = date("Y-m-d", $day_time);
    $daily_keys[$date_key] = count($trend_data["day"]);
    $trend_data["day"][] = ["label" => $thai_weekdays[(int) date("N", $day_time)], "value" => 0];
}
$trend_range_text["day"] = "สัปดาห์ปัจจุบัน · " . $thai_date($current_week_start) . " - " . $thai_date($current_week_end);
$weekly_keys = [];
$history_week_start = strtotime("monday this week", $history_start_day);
for ($week_time = $history_week_start; $week_time <= $current_week_start; $week_time = strtotime("+1 week", $week_time)) {
    $date_key = date("o-W", $week_time);
    $weekly_keys[$date_key] = count($trend_data["week"]);
    $week_number = (int) floor((date("j", $week_time) - 1) / 7) + 1;
    $week_range_start = max($history_start_day, $week_time);
    $week_range_end = min($today_start, strtotime("+6 days", $week_time));
    $trend_data["week"][] = ["label" => ["สัปดาห์ที่ " . $week_number, $thai_date($week_range_start) . " - " . $thai_date($week_range_end)], "value" => 0];
}
$week_start = $current_week_start;
$trend_range_text["week"] = "ช่วงวันที่ " . $thai_date(strtotime("-7 weeks", $week_start)) . " - " . $thai_date(strtotime("+6 days", $week_start));
$monthly_keys = [];
$history_month_start = strtotime(date("Y-m-01", $history_start_day));
$current_month_start = strtotime(date("Y-m-01", $today_start));
for ($month_time = $history_month_start; $month_time <= $current_month_start; $month_time = strtotime("+1 month", $month_time)) {
    $date_key = date("Y-m", $month_time);
    $monthly_keys[$date_key] = count($trend_data["month"]);
    $month_range_start = max($history_start_day, $month_time);
    $month_range_end = min($today_start, strtotime("last day of this month", $month_time));
    $trend_data["month"][] = ["label" => ["เดือนที่ " . date("n", $month_time) . " ของปี " . (date("Y", $month_time) + 543), $thai_date($month_range_start) . " - " . $thai_date($month_range_end)], "value" => 0];
}
$trend_range_text["month"] = "ช่วงวันที่ " . $thai_date(strtotime(date("Y-m-01") . " -11 months")) . " - " . $thai_date(time());
$year_keys = [];
for ($year = (int) date("Y", $history_start_day); $year <= (int) date("Y", $today_start); $year++) {
    $year_key = (string) $year;
    $year_keys[$year_key] = count($trend_data["year"]);
    $trend_data["year"][] = ["label" => (string) ($year + 543), "value" => 0];
}
$trend_result = $conn->query("SELECT start_time FROM tasks WHERE {$dashboard_task_where} ORDER BY start_time ASC");
while ($trend_row = $trend_result->fetch_assoc()) {
    $created_time = strtotime($trend_row["start_time"]);
    $day_key = date("Y-m-d", $created_time);
    $week_key = date("o-W", $created_time);
    $month_key = date("Y-m", $created_time);
    $year_key = date("Y", $created_time);
    if (isset($daily_keys[$day_key])) $trend_data["day"][$daily_keys[$day_key]]["value"]++;
    if (isset($weekly_keys[$week_key])) $trend_data["week"][$weekly_keys[$week_key]]["value"]++;
    if (isset($monthly_keys[$month_key])) $trend_data["month"][$monthly_keys[$month_key]]["value"]++;
    if (!isset($year_keys[$year_key])) {
        $year_keys[$year_key] = count($trend_data["year"]);
        $trend_data["year"][] = ["label" => (string) ((int) $year_key + 543), "value" => 0];
    }
    $trend_data["year"][$year_keys[$year_key]]["value"]++;
}
if (!$trend_data["year"]) $trend_data["year"][] = ["label" => (string) (date("Y") + 543), "value" => 0];
$trend_range_text["day"] = "สัปดาห์ปัจจุบัน · " . $thai_date($current_week_start) . " - " . $thai_date($current_week_end);
$trend_range_text["week"] = "ช่วงวันที่ " . $thai_date($history_start_day) . " - " . $thai_date($today_start);
$trend_range_text["month"] = "ช่วงวันที่ " . $thai_date($history_start_day) . " - " . $thai_date($today_start);
$trend_range_text["year"] = "ช่วงปี " . $trend_data["year"][0]["label"] . " - " . $trend_data["year"][count($trend_data["year"]) - 1]["label"];
$app_page_title = "Dashboard | IT / AV Task Management System";
$app_stylesheets = ["../dashboard/dashboard.css?v=" . (string) filemtime(__DIR__ . "/dashboard.css")];
require_once __DIR__ . "/../includes/app_header.php";
?>
<div class="app-shell d-flex"><?php require_once __DIR__ . "/../includes/app_sidebar.php"; ?>
<main class="main-content flex-grow-1 p-4 p-lg-5">
    <div class="d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-3 mb-4">
        <div>
            <h1 class="page-heading h3 fw-bold mb-1">ภาพรวมงาน IT / AV</h1>
            <p class="text-muted mb-0">ดูสถานะงานที่ต้องติดตามและงานล่าสุด · <?php echo htmlspecialchars($dashboard_scope_label, ENT_QUOTES, "UTF-8"); ?></p>
        </div>
        <div class="dashboard-header-actions d-flex flex-wrap gap-2">
            <button class="btn dashboard-filter-button" type="button" data-bs-toggle="modal" data-bs-target="#dashboardFilterModal" aria-label="เปิดตัวกรองแดชบอร์ด" title="เปิดตัวกรองแดชบอร์ด">
                <i class="bi bi-funnel-fill me-2" aria-hidden="true"></i>ตัวกรอง
                <?php if ($dashboard_active_filter_labels): ?><span class="dashboard-filter-count"><?php echo count($dashboard_active_filter_labels); ?></span><?php endif; ?>
            </button>
        </div>
    </div>

    <?php if ($dashboard_filter_errors): ?>
        <div class="alert alert-danger d-flex align-items-start gap-2 mb-4" role="alert">
            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
            <div><strong>ไม่สามารถใช้ตัวกรองช่วงวันที่ได้</strong><div class="small"><?php echo htmlspecialchars(implode(" · ", $dashboard_filter_errors), ENT_QUOTES, "UTF-8"); ?></div></div>
        </div>
    <?php endif; ?>

    <section class="dashboard-toolbar mb-4" aria-label="เลือกทีมและตัวกรองปัจจุบัน">
        <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
            <div>
                <div class="small fw-bold text-muted mb-2">เลือกภาพรวมตามทีม</div>
                <nav class="team-switch" aria-label="เลือกทีม">
                    <?php if ($dashboard_can_filter_team): ?>
                        <?php
                        $all_team_query = $dashboard_filter_query;
                        unset($all_team_query["team"]);
                        ?>
                        <a class="team-switch-link<?php echo $dashboard_filter_team === "" ? " active" : ""; ?>" href="?<?php echo htmlspecialchars(http_build_query($all_team_query), ENT_QUOTES, "UTF-8"); ?>">
                            <i class="bi bi-grid"></i>ทุกทีม <span class="team-count"><?php echo $dashboard_all_team_total; ?></span>
                        </a>
                        <?php foreach ($departments as $department_option): ?>
                            <?php $team_query = array_merge($dashboard_filter_query, ["team" => $department_option]); ?>
                            <a class="team-switch-link<?php echo $dashboard_filter_team === $department_option ? " active" : ""; ?>" href="?<?php echo htmlspecialchars(http_build_query($team_query), ENT_QUOTES, "UTF-8"); ?>">
                                <i class="bi <?php echo $department_option === "IT" ? "bi-pc-display" : "bi-camera-video"; ?>"></i><?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?>
                                <span class="team-count"><?php echo $dashboard_team_counts[$department_option]["total"] ?? 0; ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php $own_team = (string) ($_SESSION["department"] ?? "-"); ?>
                        <span class="team-switch-link active"><i class="bi <?php echo $own_team === "AV" ? "bi-camera-video" : "bi-pc-display"; ?>"></i>ทีม <?php echo htmlspecialchars($own_team, ENT_QUOTES, "UTF-8"); ?><span class="team-count"><?php echo $dashboard_counts["total"]; ?></span></span>
                    <?php endif; ?>
                </nav>
            </div>
            <?php if ($dashboard_active_filter_labels): ?>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($dashboard_active_filter_labels as $filter_label): ?>
                        <span class="dashboard-filter-chip"><?php echo htmlspecialchars($filter_label, ENT_QUOTES, "UTF-8"); ?></span>
                    <?php endforeach; ?>
                    <a class="dashboard-filter-chip text-decoration-none" href="index.php"><i class="bi bi-x-circle me-1"></i>ล้างทั้งหมด</a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="modal fade dashboard-filter-modal" id="dashboardFilterModal" tabindex="-1" aria-labelledby="dashboardFilterHeading" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form method="get" action="">
                    <div class="modal-header px-4 py-3">
                        <div>
                            <h2 class="modal-title h5 fw-bold page-heading mb-1" id="dashboardFilterHeading"><i class="bi bi-funnel me-2"></i>ตัวกรองแดชบอร์ด</h2>
                            <p class="small text-muted mb-0">ใช้ตัวกรองชุดเดียวกันกับ KPI กราฟ และรายการงาน โดยช่วงวันที่อิงวันเริ่มดำเนินการ</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <?php if ($dashboard_can_filter_team): ?>
                                <div class="col-md-4">
                                    <label class="form-label" for="dashboardTeam">ทีม</label>
                                    <select class="form-select" id="dashboardTeam" name="team">
                                        <option value="">ทุกทีม</option>
                                        <?php foreach ($departments as $department_option): ?>
                                            <option value="<?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?>"<?php echo $dashboard_filter_team === $department_option ? " selected" : ""; ?>><?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php else: ?>
                                <div class="col-md-4">
                                    <label class="form-label">ทีม</label>
                                    <div class="form-control bg-light d-flex align-items-center"><i class="bi bi-people me-2 text-primary"></i><?php echo htmlspecialchars($_SESSION["department"] ?? "-", ENT_QUOTES, "UTF-8"); ?></div>
                                </div>
                            <?php endif; ?>
                            <div class="col-md-4">
                                <label class="form-label" for="dashboardStatus">สถานะ</label>
                                <select class="form-select" id="dashboardStatus" name="status">
                                    <option value="">ทุกสถานะ</option>
                                    <?php foreach ($task_status_options as $status_value => $status_label): ?>
                                        <option value="<?php echo htmlspecialchars($status_value, ENT_QUOTES, "UTF-8"); ?>"<?php echo $dashboard_filter_status === $status_value ? " selected" : ""; ?>><?php echo htmlspecialchars($status_label, ENT_QUOTES, "UTF-8"); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="dashboardCategory">ประเภทปัญหา</label>
                                <select class="form-select" id="dashboardCategory" name="category">
                                    <option value="">ทุกประเภท</option>
                                    <?php foreach ($problem_category_options as $category_value => $category_label): ?>
                                        <option value="<?php echo htmlspecialchars($category_value, ENT_QUOTES, "UTF-8"); ?>"<?php echo $dashboard_filter_category === $category_value ? " selected" : ""; ?>><?php echo htmlspecialchars($category_label, ENT_QUOTES, "UTF-8"); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="dashboardStartDate">ตั้งแต่วันที่</label>
                                <input type="text" class="form-control date-picker<?php echo ($dashboard_filter_start !== "" && !$filter_start_sql) || $dashboard_date_range_invalid ? " is-invalid" : ""; ?>" id="dashboardStartDate" name="start_date" value="<?php echo htmlspecialchars($dashboard_filter_start, ENT_QUOTES, "UTF-8"); ?>" placeholder="วว/ดด/พ.ศ." aria-describedby="dashboardDateHelp">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="dashboardEndDate">ถึงวันที่</label>
                                <input type="text" class="form-control date-picker<?php echo ($dashboard_filter_end !== "" && !$filter_end_sql) || $dashboard_date_range_invalid ? " is-invalid" : ""; ?>" id="dashboardEndDate" name="end_date" value="<?php echo htmlspecialchars($dashboard_filter_end, ENT_QUOTES, "UTF-8"); ?>" placeholder="วว/ดด/พ.ศ." aria-describedby="dashboardDateHelp">
                            </div>
                        </div>
                        <div id="dashboardDateHelp" class="form-text mt-2">รูปแบบวันที่ พ.ศ. เช่น 24/07/2569</div>
                        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3 mt-4 pt-3 border-top">
                            <div class="d-flex flex-wrap gap-2">
                                <?php if ($dashboard_active_filter_labels): ?>
                                    <?php foreach ($dashboard_active_filter_labels as $filter_label): ?>
                                        <span class="dashboard-filter-chip"><i class="bi bi-check-circle me-1"></i><?php echo htmlspecialchars($filter_label, ENT_QUOTES, "UTF-8"); ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="small text-muted">ยังไม่ได้เลือกตัวกรองเพิ่มเติม</span>
                                <?php endif; ?>
                            </div>
                            <div class="dashboard-filter-result text-nowrap"><i class="bi bi-list-check me-1"></i>พบ <?php echo $dashboard_counts["total"]; ?> งาน</div>
                        </div>
                    </div>
                    <div class="modal-footer px-4 py-3">
                        <a class="btn btn-outline-secondary me-auto" href="index.php"><i class="bi bi-arrow-counterclockwise me-1"></i>ล้างตัวกรอง</a>
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">ยกเลิก</button>
                        <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>แสดงผล</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <section class="row g-3 g-xl-4 mb-4" aria-label="ตัวเลขสรุปงาน">
        <div class="col-6 col-xl-3"><article class="card summary-card h-100"><div class="card-body d-flex align-items-center"><div class="metric-icon metric-total d-inline-flex align-items-center justify-content-center me-3"><i class="bi bi-card-checklist"></i></div><div><div class="metric-label">งานทั้งหมด</div><div class="metric-value fw-bold"><?php echo $dashboard_counts["total"]; ?> <span class="fs-6">งาน</span></div></div></div></article></div>
        <div class="col-6 col-xl-3"><article class="card summary-card h-100"><div class="card-body d-flex align-items-center"><div class="metric-icon metric-pending d-inline-flex align-items-center justify-content-center me-3"><i class="bi bi-hourglass-split"></i></div><div><div class="metric-label">รอดำเนินการ</div><div class="metric-value fw-bold"><?php echo $dashboard_counts["pending"]; ?> <span class="fs-6">งาน</span></div></div></div></article></div>
        <div class="col-6 col-xl-3"><article class="card summary-card h-100"><div class="card-body d-flex align-items-center"><div class="metric-icon metric-progress d-inline-flex align-items-center justify-content-center me-3"><i class="bi bi-tools"></i></div><div><div class="metric-label">กำลังดำเนินการ</div><div class="metric-value fw-bold"><?php echo $dashboard_counts["in_progress"]; ?> <span class="fs-6">งาน</span></div></div></div></article></div>
        <div class="col-6 col-xl-3"><article class="card summary-card h-100"><div class="card-body d-flex align-items-center"><div class="metric-icon metric-completed d-inline-flex align-items-center justify-content-center me-3"><i class="bi bi-check-circle-fill"></i></div><div><div class="metric-label">เสร็จสิ้น</div><div class="metric-value fw-bold"><?php echo $dashboard_counts["completed"]; ?> <span class="fs-6">งาน</span></div></div></div></article></div>
    </section>

    <section class="row g-4 mb-5" aria-label="สถานะและแนวโน้มงาน">
        <div class="col-lg-5">
            <article class="card monitor-widget h-100">
                <div class="card-header py-3 px-4"><h2 class="page-heading h5 fw-bold mb-1">งานที่ต้องติดตาม</h2><p class="text-muted small mb-0"><?php echo htmlspecialchars($dashboard_scope_label, ENT_QUOTES, "UTF-8"); ?></p></div>
                <div class="card-body p-4">
                    <div class="active-work-callout d-flex align-items-center justify-content-between gap-3 p-3 mb-4">
                        <div><div class="small text-muted fw-semibold">ยังไม่เสร็จ</div><div class="small text-muted">รอดำเนินการ + กำลังดำเนินการ</div></div>
                        <strong class="page-heading h3 mb-0"><?php echo $dashboard_counts["active"]; ?> <span class="fs-6">งาน</span></strong>
                    </div>
                    <?php
                    $status_overview = [
                        ["pending", "รอดำเนินการ", "status-overview-pending"],
                        ["in_progress", "กำลังดำเนินการ", "status-overview-progress"],
                        ["completed", "เสร็จสิ้น", "status-overview-completed"],
                    ];
                    ?>
                    <?php foreach ($status_overview as [$status_key, $status_label, $status_class]): ?>
                        <div class="status-overview-row">
                            <div class="d-flex align-items-center justify-content-between mb-2"><span><?php echo $status_label; ?></span><strong><?php echo $dashboard_counts[$status_key]; ?> งาน</strong></div>
                            <div class="status-overview-track" role="progressbar" aria-label="<?php echo $status_label; ?>" aria-valuenow="<?php echo $dashboard_status_percentages[$status_key]; ?>" aria-valuemin="0" aria-valuemax="100"><div class="status-overview-fill <?php echo $status_class; ?>" style="width:<?php echo $dashboard_status_percentages[$status_key]; ?>%"></div></div>
                        </div>
                    <?php endforeach; ?>
                    <a class="btn btn-outline-primary w-100 mt-4" href="<?php echo htmlspecialchars($dashboard_report_url, ENT_QUOTES, "UTF-8"); ?>">เปิดรายการงานตามตัวกรอง</a>
                </div>
            </article>
        </div>
        <div class="col-lg-7">
            <article class="card monitor-widget h-100">
                <div class="card-header py-3 px-4 d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
                    <div><h2 class="page-heading h5 fw-bold mb-1">แนวโน้มจำนวนงาน</h2><p class="text-muted small mb-0" id="trendChartSubtitle">สัปดาห์ปัจจุบัน</p></div>
                    <select class="form-select form-select-sm w-auto" id="trendRange" aria-label="เลือกระยะเวลาสถิติงาน"><option value="day">รายวัน</option><option value="week">รายสัปดาห์</option><option value="month">รายเดือน</option><option value="year">รายปี</option></select>
                </div>
                <div class="card-body"><div class="dashboard-chart"><canvas id="taskTrendChart"></canvas></div></div>
            </article>
        </div>
    </section>

    <section class="card common-work-card mb-4" aria-labelledby="commonWorkTitle">
        <div class="card-header common-work-header d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 py-3 px-4">
            <div>
                <h2 class="page-heading h5 fw-bold mb-1" id="commonWorkTitle">งานที่พบบ่อย</h2>
                <p class="text-muted small mb-0">จัดอันดับจากชื่องานที่ถูกบันทึกซ้ำ กดเพื่อเปิดรายการงานของทีมนั้น</p>
            </div>
            <?php if (count($dashboard_common_visible_teams) > 1): ?>
                <div class="common-team-switch" role="tablist" aria-label="เลือกทีมสำหรับงานที่พบบ่อย">
                    <?php foreach ($dashboard_common_visible_teams as $team_name): ?>
                        <button class="common-team-button<?php echo $team_name === $dashboard_common_initial_team ? " active" : ""; ?>" type="button" role="tab" data-common-team="<?php echo htmlspecialchars($team_name, ENT_QUOTES, "UTF-8"); ?>" aria-selected="<?php echo $team_name === $dashboard_common_initial_team ? "true" : "false"; ?>"><?php echo htmlspecialchars($team_name, ENT_QUOTES, "UTF-8"); ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php foreach ($dashboard_common_visible_teams as $team_name): ?>
                <div class="common-work-panel<?php echo $team_name === $dashboard_common_initial_team ? "" : " d-none"; ?>" data-common-panel="<?php echo htmlspecialchars($team_name, ENT_QUOTES, "UTF-8"); ?>" role="tabpanel">
                    <?php if ($dashboard_common_tasks[$team_name]): ?>
                        <div class="common-work-list">
                            <?php foreach ($dashboard_common_tasks[$team_name] as $common_index => $common_task): ?>
                                <?php $common_report_url = "../report/?" . http_build_query(["department" => $team_name, "q" => $common_task["title"]]); ?>
                                <a class="common-work-item" href="<?php echo htmlspecialchars($common_report_url, ENT_QUOTES, "UTF-8"); ?>">
                                    <span class="common-work-rank"><?php echo $common_index + 1; ?></span>
                                    <span class="common-work-name"><strong><?php echo htmlspecialchars($common_task["title"], ENT_QUOTES, "UTF-8"); ?></strong><small>ทีม <?php echo htmlspecialchars($team_name, ENT_QUOTES, "UTF-8"); ?></small></span>
                                    <span class="common-work-count"><?php echo $common_task["count"]; ?> ครั้ง <i class="bi bi-arrow-right"></i></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-5"><i class="bi bi-inbox d-block fs-3 mb-2"></i>ยังไม่มีข้อมูลงานของทีม <?php echo htmlspecialchars($team_name, ENT_QUOTES, "UTF-8"); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card table-card">
        <div class="card-header d-flex align-items-center justify-content-between py-3 px-4"><div><h2 class="h5 mb-1 fw-bold page-heading">งานล่าสุด</h2><p class="text-muted small mb-0">5 งานล่าสุดตามทีมและตัวกรองปัจจุบัน</p></div><a class="btn btn-primary btn-sm px-3" href="<?php echo htmlspecialchars($dashboard_report_url, ENT_QUOTES, "UTF-8"); ?>"><i class="bi bi-list-check me-1"></i>รายการงานทั้งหมด</a></div>
        <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th class="ps-4 py-3">ชื่องาน</th><th>ทีม</th><th>สถานะ</th><th class="pe-4">วันที่</th></tr></thead><tbody><tr><td colspan="4" class="text-center text-muted py-4">กำลังโหลดรายการงาน…</td></tr></tbody></table></div>
    </section>
</main></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
<script>
    (() => {
        const trendData = <?php echo json_encode($trend_data, JSON_UNESCAPED_UNICODE); ?>;
        const trendRangeText = <?php echo json_encode($trend_range_text, JSON_UNESCAPED_UNICODE); ?>;
        const rangeSelect = document.getElementById("trendRange");
        const trendSubtitle = document.getElementById("trendChartSubtitle");
        const trendCanvas = document.getElementById("taskTrendChart");
        if (!rangeSelect || !trendSubtitle || !trendCanvas || !window.Chart) return;

        const trendChart = new Chart(trendCanvas, {
            type: "bar",
            data: { labels: [], datasets: [{ label: "จำนวนงาน", data: [], backgroundColor: "rgba(23, 105, 194, .72)", borderColor: "#1769c2", borderWidth: 1, borderRadius: 6, maxBarThickness: 42 }] },
            options: { responsive: true, maintainAspectRatio: false, animation: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: "rgba(148, 163, 184, .18)" } }, x: { grid: { display: false } } }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: (context) => `จำนวนงาน: ${context.raw} งาน` } } } }
        });

        const updateTrendChart = () => {
            const range = rangeSelect.value;
            const points = trendData[range] || [];
            trendChart.data.labels = points.map((point) => point.label);
            trendChart.data.datasets[0].data = points.map((point) => point.value);
            trendSubtitle.textContent = trendRangeText[range] || "";
            trendChart.update();
            const chartContainer = trendCanvas.parentElement;
            chartContainer.querySelector(".chart-empty-state")?.remove();
            if (!points.length || points.every((point) => Number(point.value || 0) === 0)) {
                chartContainer.insertAdjacentHTML("beforeend", '<div class="chart-empty-state"><div><i class="bi bi-bar-chart d-block fs-3 mb-2"></i>ไม่พบข้อมูลตามตัวกรอง</div></div>');
            }
        };
        rangeSelect.addEventListener("change", updateTrendChart);
        updateTrendChart();
    })();
</script><script>
    (() => {
        const teamButtons = document.querySelectorAll("[data-common-team]");
        const teamPanels = document.querySelectorAll("[data-common-panel]");
        teamButtons.forEach((button) => {
            button.addEventListener("click", () => {
                const team = button.dataset.commonTeam;
                teamButtons.forEach((item) => {
                    const isActive = item === button;
                    item.classList.toggle("active", isActive);
                    item.setAttribute("aria-selected", isActive ? "true" : "false");
                });
                teamPanels.forEach((panel) => panel.classList.toggle("d-none", panel.dataset.commonPanel !== team));
            });
        });
    })();
</script><script>
    // Real, permission-scoped latest tasks supplied by the server.
    const dashboardLatestTasks = <?php echo json_encode($recent_task_rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const dashboardFilterQuery = <?php echo json_encode(http_build_query($dashboard_filter_query), JSON_UNESCAPED_UNICODE); ?>;
    const dashboardReportUrl = <?php echo json_encode($dashboard_report_url, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const dashboardRecentPagination = <?php echo json_encode([
        "page" => $recent_task_page,
        "totalPages" => $recent_task_total_pages,
        "perPage" => $recent_tasks_per_page,
        "total" => $recent_task_total
    ]); ?>;

    window.addEventListener('load', () => {
        const card = document.querySelector('.table-card');
        if (!card) return;
        const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[character]));
        const thaiDate = (value) => {
            if (!value) return '-';
            const date = new Date(value.replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) return '-';
            return new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).format(date) + ' น.';
        };
        const statusMeta = (status) => ({ pending: ['รอดำเนินการ', 'status-pending'], in_progress: ['กำลังดำเนินการ', 'status-progress'], completed: ['เสร็จสิ้น', 'status-completed'], cancelled: ['ยกเลิก', 'status-cancelled'] }[status] || [status, 'status-pending']);
        const paginationMarkup = (() => {
            const { page, totalPages } = dashboardRecentPagination;
            if (totalPages <= 1) return '';

            const pageLink = (number, label = number, disabled = false, active = false) =>
                `<li class="page-item${disabled ? ' disabled' : ''}${active ? ' active' : ''}"><a class="page-link" href="?${dashboardFilterQuery ? `${dashboardFilterQuery}&` : ''}recent_page=${number}"${disabled ? ' tabindex="-1" aria-disabled="true"' : ''}>${label}</a></li>`;
            const pages = new Set([1, totalPages, page - 1, page, page + 1]);
            const visiblePages = [...pages].filter((number) => number >= 1 && number <= totalPages).sort((a, b) => a - b);
            let items = pageLink(Math.max(1, page - 1), '<i class="bi bi-chevron-left"></i>', page === 1);
            let previous = 0;
            visiblePages.forEach((number) => {
                if (number - previous > 1) items += '<li class="page-item disabled"><span class="page-link">…</span></li>';
                items += pageLink(number, number, false, number === page);
                previous = number;
            });
            items += pageLink(Math.min(totalPages, page + 1), '<i class="bi bi-chevron-right"></i>', page === totalPages);
            return `<div class="d-flex flex-column flex-sm-row align-items-center justify-content-between gap-2 px-4 py-3 border-top"><span class="small text-muted">แสดง ${dashboardLatestTasks.length} จาก ${dashboardRecentPagination.total} งาน</span><nav aria-label="การแบ่งหน้างานล่าสุด"><ul class="pagination pagination-sm mb-0">${items}</ul></nav></div>`;
        })();

        const rows = dashboardLatestTasks.map((task, taskIndex) => {
            const [label, className] = statusMeta(task.status);
            const displaySequence = ((dashboardRecentPagination.page - 1) * dashboardRecentPagination.perPage) + taskIndex + 1;
            return `<tr class="dashboard-task-row" data-task-id="${task.id}" role="button" tabindex="0" aria-label="ดูรายละเอียดงาน ${escapeHtml(task.title)}"><td class="ps-4 fw-semibold">${displaySequence}</td><td class="optional-table-cell">${thaiDate(task.created_at)}</td><td><button class="btn btn-link p-0 text-start fw-semibold text-decoration-none dashboard-task-detail" data-task-id="${task.id}">${escapeHtml(task.title)}</button></td><td class="optional-table-cell"><span class="badge text-bg-light border">${escapeHtml(task.department)}</span></td><td><span class="badge rounded-pill ${className}">${label}</span></td><td class="pe-4 optional-table-cell">${escapeHtml(task.created_by_name)}</td></tr>`;
        }).join('') || '<tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีข้อมูลงาน</td></tr>';
        card.innerHTML = `<div class="card-header d-flex align-items-center justify-content-between gap-3 py-3 px-4"><div><h2 class="h5 mb-1 fw-bold page-heading">งานล่าสุด</h2><p class="text-muted small mb-0">5 งานล่าสุดตามทีมและตัวกรองปัจจุบัน</p></div><a class="btn btn-primary btn-sm px-3" href="${escapeHtml(dashboardReportUrl)}"><i class="bi bi-list-check me-1"></i>รายการงานทั้งหมด</a></div><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th class="ps-4 py-3">ลำดับ</th><th class="optional-table-cell">วันที่สร้าง</th><th>ชื่องาน</th><th class="optional-table-cell">ทีม</th><th>สถานะ</th><th class="pe-4 optional-table-cell">ผู้รับผิดชอบ</th></tr></thead><tbody>${rows}</tbody></table></div>${paginationMarkup}`;

        const latestTaskButtons = card.querySelectorAll('.dashboard-task-detail');

        card.querySelectorAll('.dashboard-task-row').forEach((row) => {
            const openRowDetail = () => row.querySelector('.dashboard-task-detail')?.click();
            row.addEventListener('click', (event) => {
                if (event.target.closest('button, a')) return;
                openRowDetail();
            });
            row.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openRowDetail();
                }
            });
        });

        latestTaskButtons.forEach((button) => button.addEventListener('click', () => {
            const task = dashboardLatestTasks.find((item) => Number(item.id) === Number(button.dataset.taskId));
            if (!task) return;
            const [label, className] = statusMeta(task.status);
            const existingModal = document.getElementById('dashboardTaskDetailModal');
            if (existingModal) existingModal.remove();
            const modal = document.createElement('div');
            modal.className = 'modal fade'; modal.id = 'dashboardTaskDetailModal'; modal.tabIndex = -1;
            const imageMarkup = Array.isArray(task.images) && task.images.length
                ? `<div class="col-12"><strong>รูปภาพประกอบงาน:</strong><div class="row g-3 mt-0">${task.images.map((image) => `<div class="col-6 col-md-3"><a class="task-image-card d-block text-decoration-none" href="../${escapeHtml(image.file_path)}" target="_blank" rel="noopener"><img src="../${escapeHtml(image.file_path)}" alt="${escapeHtml(image.original_name)}"><span class="d-block small text-truncate p-2">${escapeHtml(image.original_name)}</span></a></div>`).join('')}</div></div>`
                : '';
            const activityIcons = {
                created: 'bi-plus-lg',
                updated: 'bi-pencil',
                status_changed: 'bi-arrow-repeat',
                deleted: 'bi-trash'
            };
            const activityItems = Array.isArray(task.activity_log) && task.activity_log.length
                ? task.activity_log.map((activity) => `<div class="dashboard-activity-item"><span class="dashboard-activity-icon"><i class="bi ${activityIcons[activity.event_type] || 'bi-clock-history'}"></i></span><span><span class="d-block fw-semibold">${escapeHtml(activity.description)}</span><small>${escapeHtml(activity.actor_name || 'ระบบ')} · ${thaiDate(activity.created_at)}</small></span></div>`).join('')
                : '<div class="dashboard-activity-empty">ยังไม่มีประวัติการเปลี่ยนแปลงสำหรับงานนี้</div>';
            const activityMarkup = `<div class="col-12 dashboard-activity-panel"><strong>ประวัติการเปลี่ยนแปลง:</strong><div class="dashboard-activity-list">${activityItems}</div></div>`;
            const editButton = task.can_edit ? `<a class="btn btn-primary" href="../report/?task_id=${task.id}&edit=${task.id}"><i class="bi bi-pencil-square me-1"></i>แก้ไขงาน</a>` : '';
            modal.innerHTML = `<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5">รายละเอียดงาน: ${escapeHtml(task.title)}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-6"><strong>ชื่องาน:</strong> ${escapeHtml(task.title)}</div><div class="col-md-3"><strong>ทีม:</strong> ${escapeHtml(task.department)}</div><div class="col-md-3"><strong>สถานะ:</strong> <span class="badge ${className}">${label}</span></div><div class="col-md-6"><strong>ประเภทปัญหา:</strong> ${escapeHtml(task.category)}</div><div class="col-md-6"><strong>สถานที่:</strong> ${escapeHtml(task.location)}</div><div class="col-12"><strong>ปัญหาที่พบ:</strong><div class="mt-1">${escapeHtml(task.problem).replace(/\n/g, '<br>')}</div></div><div class="col-12"><strong>วิธีแก้ไขปัญหา:</strong><div class="mt-1">${escapeHtml(task.solution).replace(/\n/g, '<br>')}</div></div><div class="col-md-6"><strong>วันเริ่มดำเนินการ:</strong> ${thaiDate(task.start_time)}</div><div class="col-md-6"><strong>วันสิ้นสุด:</strong> ${thaiDate(task.finish_time)}</div><div class="col-12"><strong>หมายเหตุ:</strong> ${escapeHtml(task.remark).replace(/\n/g, '<br>')}</div>${imageMarkup}<div class="col-md-6"><strong>ผู้รับผิดชอบ:</strong> ${escapeHtml(task.created_by_name)}</div><div class="col-md-6"><strong>สร้างเมื่อ:</strong> ${thaiDate(task.created_at)}</div><div class="col-md-6"><strong>อัปเดตเมื่อ:</strong> ${thaiDate(task.updated_at)}</div>${activityMarkup}</div></div><div class="modal-footer">${editButton}<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button></div></div></div>`;
            modal.querySelectorAll('.modal-body .mt-1').forEach((field) => {
                if (!field.textContent.trim()) field.textContent = '-';
            });
            const detailRow = modal.querySelector('.modal-body .row');
            const locationCell = detailRow?.children[4];
            if (locationCell && !String(task.location || '').trim()) {
                const locationLabel = locationCell.querySelector('strong');
                locationCell.replaceChildren(locationLabel);
                locationCell.append(' -');
            }
            const insertBefore = detailRow?.children[5];
            if (detailRow && insertBefore) {
                const createDetailField = (label, value) => {
                    const field = document.createElement('div');
                    field.className = 'col-12';
                    const heading = document.createElement('strong');
                    heading.textContent = `${label}:`;
                    const content = document.createElement('div');
                    content.className = 'mt-1';
                    content.style.whiteSpace = 'pre-wrap';
                    content.textContent = String(value || '').trim() || '-';
                    field.append(heading, content);
                    return field;
                };
                insertBefore.before(
                    createDetailField('รายละเอียดงาน', task.work_description),
                    createDetailField('การดำเนินงาน', task.work_action)
                );
            }
            document.body.appendChild(modal);
            new bootstrap.Modal(modal).show();
        }));
    });
</script>
<script>
    const dashboardKpiIcons = {
        '.metric-total i': 'bi bi-card-checklist',
        '.metric-pending i': 'bi bi-hourglass-split',
        '.metric-progress i': 'bi bi-tools',
        '.metric-completed i': 'bi bi-check-circle-fill'
    };
    Object.entries(dashboardKpiIcons).forEach(([selector, className]) => {
        const icon = document.querySelector(selector);
        if (icon) icon.className = className;
    });

    window.addEventListener('load', () => {
        const mainContent = document.querySelector('.main-content');
        const viewOnly = <?php echo $dashboard_is_view_only ? 'true' : 'false'; ?>;
        const kpiCards = [...document.querySelectorAll('.summary-card')].slice(0, 4);
        const kpiStatuses = ['', 'pending', 'in_progress', 'completed'];
        kpiCards.forEach((card, index) => {
            card.tabIndex = 0;
            card.setAttribute('role', 'link');
            const openReport = () => {
                const query = new URLSearchParams(dashboardFilterQuery);
                if (kpiStatuses[index]) query.set('status', kpiStatuses[index]);
                const team = query.get('team');
                if (team) {
                    query.set('department', team);
                    query.delete('team');
                }
                window.location.href = `../report/${query.toString() ? `?${query}` : ''}`;
            };
            card.addEventListener('click', openReport);
            card.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openReport();
                }
            });
        });
        if (mainContent && viewOnly) {
            const alert = document.createElement('div');
            alert.className = 'alert alert-info d-flex align-items-center gap-2 mb-4';
            alert.innerHTML = '<i class="bi bi-eye"></i><span>บัญชีนี้อยู่ในโหมดดูข้อมูลเท่านั้น จนกว่าผู้ดูแลระบบจะกำหนดทีมและสิทธิ์ให้</span>';
            mainContent.querySelector('.mb-4')?.after(alert);
        }

        const kpiTooltipTitles = [
            'เปิดรายการงานทั้งหมดตามตัวกรองปัจจุบัน',
            'เปิดรายการงานที่รอดำเนินการ',
            'เปิดรายการงานที่กำลังดำเนินการ',
            'เปิดรายการงานที่เสร็จสิ้น'
        ];
        kpiCards.forEach((card, index) => {
            card.setAttribute('data-bs-toggle', 'tooltip');
            card.setAttribute('data-bs-title', kpiTooltipTitles[index]);
        });
        const tooltipTargets = [
            ['#taskTrendChart', 'เลือกวัน สัปดาห์ เดือน หรือปี เพื่อดูแนวโน้มงานย้อนหลัง'],
            ['.table-card a', 'เปิดรายการงานทั้งหมดตามสิทธิ์ของคุณ']
        ];
        tooltipTargets.forEach(([selector, title]) => document.querySelectorAll(selector).forEach((element) => {
            element.setAttribute('data-bs-toggle', 'tooltip');
            element.setAttribute('data-bs-title', title);
        }));
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => bootstrap.Tooltip.getOrCreateInstance(element));

        // Reopen the filter dialog when the server rejects an invalid date range.
        <?php if ($dashboard_filter_errors): ?>
        const dashboardFilterModal = document.getElementById('dashboardFilterModal');
        if (dashboardFilterModal) bootstrap.Modal.getOrCreateInstance(dashboardFilterModal).show();
        <?php endif; ?>
    });
</script>
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>
