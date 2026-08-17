<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/helpers.php";
require_once __DIR__ . "/../includes/task_activity.php";
require_once __DIR__ . "/../auth/authorization.php";

$failures = [];
$checks = 0;

function expect_true(bool $condition, string $message): void
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        echo "PASS: {$message}", PHP_EOL;
        return;
    }
    $failures[] = $message;
    echo "FAIL: {$message}", PHP_EOL;
}

function http_status(string $url): array
{
    $headers = get_headers($url, true);
    $status_line = is_array($headers) ? (string) ($headers[0] ?? "") : "";
    preg_match('/\s(\d{3})\s/', $status_line, $matches);
    $location = is_array($headers) ? ($headers["Location"] ?? "") : "";
    if (is_array($location)) $location = end($location);
    return [(int) ($matches[1] ?? 0), (string) $location];
}

$users = $conn->query(
    "SELECT id, username, role, is_enabled, is_approved FROM users ORDER BY id"
)->fetch_all(MYSQLI_ASSOC);
expect_true(count($users) >= 1, "database contains at least one user");
expect_true(
    isset($users[0])
    && $users[0]["username"] === "อาร์ม"
    && $users[0]["role"] === "ADMIN"
    && (int) $users[0]["is_enabled"] === 1
    && (int) $users[0]["is_approved"] === 1,
    "Arm is the enabled and approved ADMIN"
);

$original_authorization_session = $_SESSION ?? [];
$_SESSION = ["role" => "USER", "department" => "IT", "is_approved" => 0];
$same_team_task = ["department" => "IT", "created_by" => 999999];
$other_team_task = ["department" => "AV", "created_by" => 999999];
expect_true(
    !can_manage_all_tasks()
    && can_view_task($same_team_task)
    && can_edit_task($same_team_task)
    && can_delete_task($same_team_task)
    && !can_view_task($other_team_task)
    && !can_edit_task($other_team_task)
    && !can_delete_task($other_team_task),
    "USER can manage every same-team task but no cross-team task without approval workflow"
);
$_SESSION = ["role" => "SUPER", "department" => "IT", "is_approved" => 1];
expect_true(
    can_manage_all_tasks()
    && can_edit_task($other_team_task)
    && can_delete_task($other_team_task)
    && !can_manage_users(),
    "SUPER manages tasks across teams without user administration"
);
$_SESSION = $original_authorization_session;

$task_activity_table = $conn->query(
    "SELECT COUNT(*) AS total
     FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'task_activity_logs'"
)->fetch_assoc();
$task_activity_foreign_keys = $conn->query(
    "SELECT COUNT(*) AS total
     FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'task_activity_logs'
       AND constraint_type = 'FOREIGN KEY'"
)->fetch_assoc();
expect_true(
    (int) ($task_activity_table["total"] ?? 0) === 1
    && (int) ($task_activity_foreign_keys["total"] ?? 0) === 2,
    "task activity audit table and foreign keys exist"
);
expect_true(
    task_activity_changed_labels(
        ["title" => "เดิม", "location" => "เมฆา1"],
        ["title" => "ใหม่", "location" => "เมฆา1"]
    ) === ["ชื่องาน"],
    "task activity helper detects changed fields without database writes"
);

$orphan_tasks = (int) $conn->query(
    "SELECT COUNT(*) AS total
     FROM tasks AS t
     LEFT JOIN users AS u ON u.id = t.created_by
     WHERE u.id IS NULL"
)->fetch_assoc()["total"];
expect_true($orphan_tasks === 0, "all tasks have a valid creator");
$task_ownership = $conn->query(
    "SELECT COUNT(*) AS total,
            SUM(created_by = (SELECT id FROM users WHERE username = 'อาร์ม' LIMIT 1)) AS arm_total
     FROM tasks"
)->fetch_assoc();
expect_true(
    (int) $task_ownership["total"] > 0
    && (int) $task_ownership["arm_total"] === (int) $task_ownership["total"],
    "all historical tasks belong to Arm"
);

$required_indexes = [
    ["users", "uq_users_username"],
    ["users", "idx_users_last_activity"],
    ["tasks", "idx_tasks_active_created"],
    ["tasks", "idx_tasks_active_department_created"],
    ["tasks", "idx_tasks_active_status"],
    ["tasks", "idx_tasks_active_category"],
    ["tasks", "idx_tasks_active_start"],
    ["tasks", "idx_tasks_created_by"],
];
foreach ($required_indexes as [$table, $index]) {
    $stmt = $conn->prepare(
        "SELECT 1 FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1"
    );
    $stmt->bind_param("ss", $table, $index);
    $stmt->execute();
    expect_true((bool) $stmt->get_result()->fetch_row(), "{$table}.{$index} exists");
    $stmt->close();
}

$remember_table = $conn->query("SHOW TABLES LIKE 'auth_remember_tokens'")->fetch_row();
expect_true((bool) $remember_table, "revocable Remember Me token table exists");

$sidebar_source = file_get_contents(__DIR__ . "/../includes/app_sidebar.php");
$header_source = file_get_contents(__DIR__ . "/../includes/app_header.php");
$footer_source = file_get_contents(__DIR__ . "/../includes/app_footer.php");
$dashboard_source = file_get_contents(__DIR__ . "/../dashboard/index.php");
$dashboard_css_source = file_get_contents(__DIR__ . "/../dashboard/dashboard.css");
$report_source = file_get_contents(__DIR__ . "/../report/index.php");
$login_source = file_get_contents(__DIR__ . "/../auth/login.php");
$task_input_source = file_get_contents(__DIR__ . "/../task_input/index.php");
$task_edit_source = file_get_contents(__DIR__ . "/../task_input/edit.php");
$task_input_css_source = file_get_contents(__DIR__ . "/../task_input/task_input.css");
$config_source = file_get_contents(__DIR__ . "/../config/index.php");
$profile_source = file_get_contents(__DIR__ . "/../profile/index.php");
$activity_source = file_get_contents(__DIR__ . "/../includes/task_activity.php");
$register_source = file_get_contents(__DIR__ . "/../auth/register.php");
$help_source = file_get_contents(__DIR__ . "/../help/index.php");
$system_overview_source = file_get_contents(__DIR__ . "/../SYSTEM_OVERVIEW.md");
$security_permissions_source = file_get_contents(__DIR__ . "/../SECURITY_AND_PERMISSIONS.md");
$database_reference_source = file_get_contents(__DIR__ . "/../DATABASE_REFERENCE.md");
$ux_ui_guide_source = file_get_contents(__DIR__ . "/../UX_UI_GUIDE.md");
expect_true(
    str_contains($sidebar_source, "รายการงาน")
    && str_contains($sidebar_source, "ตั้งค่าระบบ")
    && str_contains($sidebar_source, 'href="../help/"'),
    "shared navigation uses the agreed terminology and real routes"
);
expect_true(!str_contains($sidebar_source, 'href="#"'), "shared navigation has no placeholder routes");
expect_true(
    str_contains($sidebar_source, '$sidebar_can_manage_users')
    && str_contains($sidebar_source, 'href="../config/"')
    && str_contains($sidebar_source, "ตั้งค่าบัญชี")
    && str_contains($config_source, 'action" value="change_own_password"')
    && str_contains($config_source, 'action" value="create_user"')
    && !str_contains($config_source, "รออนุมัติ")
    && str_contains($profile_source, '$active_nav = "profile"'),
    "every role can open Config while only ADMIN receives account management"
);
expect_true(
    str_contains($register_source, "http_response_code(404)")
    && !str_contains($login_source, 'href="register.php"')
    && !str_contains($login_source, "สมัครสมาชิกเรียบร้อยแล้ว"),
    "public registration is disabled and accounts are provisioned through Config"
);
expect_true(
    str_contains($help_source, "ทุก Role เข้า Config เพื่อเปลี่ยนรหัสผ่านของตนเองได้")
    && str_contains($system_overview_source, "ไม่มีหน้า Create Account หรือ Self-registration")
    && str_contains($security_permissions_source, "ไม่มี Public Registration")
    && str_contains($database_reference_source, "Legacy compatibility")
    && str_contains($ux_ui_guide_source, "ไม่มีหน้า Register หรือ Create Account")
    && !str_contains($help_source, "ผู้ใช้ใหม่สมัครจากหน้า Login")
    && !str_contains($system_overview_source, "สมัครสมาชิกได้จากหน้า Login")
    && !str_contains($security_permissions_source, "## 3. Registration Flow")
    && !str_contains($database_reference_source, "Account ที่ยังไม่ Approved ถูก Query ทุกทีม")
    && !str_contains($ux_ui_guide_source, "## 8. Register Page"),
    "Help and project documents describe the current internal account workflow"
);
expect_true(
    str_contains($header_source, "ออกจากระบบ")
    && str_contains($header_source, "logout_csrf_token"),
    "shared header renders a protected Thai logout action"
);
expect_true(
    !str_contains($footer_source, "sidebar-help-link")
    && !str_contains($footer_source, "querySelectorAll('.sidebar"),
    "shared footer does not rewrite navigation with JavaScript"
);
expect_true(
    str_contains($dashboard_source, "includes/app_header.php")
    && str_contains($dashboard_source, "includes/app_sidebar.php")
    && str_contains($dashboard_source, "../dashboard/dashboard.css")
    && !str_contains($dashboard_source, "<!DOCTYPE html>")
    && !str_contains($dashboard_source, '<header class="topbar'),
    "Dashboard reuses the shared header and navigation without duplicate layout markup"
);
expect_true(
    str_contains($header_source, '$app_stylesheets')
    && str_contains($dashboard_css_source, ".dashboard-toolbar")
    && str_contains($dashboard_css_source, "#dashboardTaskDetailModal"),
    "shared header loads page-specific Dashboard styles"
);
expect_true(
    str_contains($dashboard_source, "ภาพรวมงาน IT / AV")
    && str_contains($dashboard_source, "team-switch")
    && str_contains($dashboard_source, "งานที่ต้องติดตาม"),
    "Dashboard emphasizes IT/AV scope and actionable work status"
);
expect_true(
    substr_count($dashboard_source, "<canvas") === 1
    && str_contains($dashboard_source, 'id="taskTrendChart"')
    && !str_contains($dashboard_source, "summaryReportChart"),
    "Dashboard keeps one necessary trend chart without duplicate status charts"
);
expect_true(
    !str_contains($dashboard_source, "dashboardInsightModal")
    && str_contains($dashboard_source, '$dashboard_common_tasks')
    && str_contains($dashboard_source, 'data-common-team')
    && str_contains($dashboard_source, 'data-common-panel')
    && str_contains($dashboard_source, "งานที่พบบ่อย"),
    "Dashboard provides a focused, team-switchable frequent-work list"
);
expect_true(
    str_contains($dashboard_source, 'href="../report/?task_id=${task.id}&edit=${task.id}"')
    && !str_contains($dashboard_source, 'href="../task_input/edit.php?id=${task.id}"')
    && str_contains($report_source, 'report_get_string("task_id")')
    && str_contains($report_source, 'isset($_GET["edit"])'),
    "Dashboard edit action opens the selected task in the Report edit modal"
);
expect_true(
    str_contains($report_source, 'class="report-toolbar')
    && str_contains($report_source, 'class="report-team-switch')
    && str_contains($report_source, 'id="reportSearchButton"'),
    "Task List exposes team switching, active filters and an explicit search action"
);
expect_true(
    str_contains($report_source, '$report_page_url')
    && str_contains($report_source, 'class="report-mobile-hidden"')
    && str_contains($report_source, 'id="applyReportFilters"'),
    "Task List uses server pagination and responsive table controls"
);
expect_true(
    !str_contains($report_source, "Client-side filtering and pagination")
    && !str_contains($report_source, "const addPage")
    && !str_contains($report_source, 'link.href = "#"'),
    "Task List has no duplicate client pagination or placeholder page links"
);
expect_true(
    str_contains($report_source, ">ลำดับ</th>")
    && str_contains($report_source, '$report_offset + $task_index + 1')
    && str_contains($dashboard_source, "displaySequence")
    && !str_contains($report_source, "#T-")
    && !str_contains($dashboard_source, "#T-"),
    "Dashboard and Task List show sequential row numbers instead of database task codes"
);
expect_true(
    !str_contains($dashboard_source, 'href="../task_input/"')
    && !str_contains($task_input_source, "ไปที่รายการงาน"),
    "Dashboard and Task Input omit the requested create/list shortcut buttons"
);
expect_true(
    !str_contains($task_input_source, "<datalist")
    && !str_contains($task_edit_source, "<datalist")
    && !str_contains($report_source, "<datalist")
    && !str_contains($task_input_source, "taskTitleSuggestions")
    && !str_contains($task_edit_source, "taskTitleSuggestions")
    && !str_contains($report_source, "reportTitleSuggestions"),
    "Task pages no longer render native autocomplete suggestion panels"
);
expect_true(
    str_contains($task_input_source, 'id="taskCreateForm"')
    && str_contains($task_input_source, 'class="task-form-guide')
    && str_contains($task_input_source, 'id="submitTaskButton"')
    && str_contains($task_input_source, 'task_input.css'),
    "Task Input presents a guided form with a clear submit action"
);
expect_true(
    str_contains($task_input_source, "task-form-guide-three")
    && str_contains($task_input_source, 'name="work_description"')
    && str_contains($task_input_source, 'name="work_action"')
    && str_contains($task_input_source, 'id="itResolutionSection"')
    && str_contains($task_input_source, 'id="itCategoryGroup"')
    && str_contains($task_input_source, 'id="avEquipmentGuide"')
    && str_contains($task_input_source, 'id="avWorkActionGroup"')
    && str_contains($task_input_source, 'name="problem"')
    && str_contains($task_input_source, 'name="solution"')
    && str_contains($task_input_source, 'name="remark"')
    && !str_contains($task_input_source, "problem_options.js"),
    "Task Input separates IT problem-solving and AV event-operation fields"
);
expect_true(
    task_problem_is_required("IT", "")
    && !task_problem_is_required("IT", "เปิดเครื่องไม่ติด")
    && !task_problem_is_required("AV", "")
    && task_workflow_status("IT", "", "pending", true) === "in_progress"
    && task_workflow_status("IT", "เปลี่ยนสายไฟ", "in_progress") === "completed"
    && task_workflow_status("IT", "", "cancelled") === "cancelled"
    && task_workflow_status("IT", "มีวิธีแก้", "cancelled", false, true) === "cancelled"
    && task_workflow_status("AV", "", "pending", true) === "in_progress"
    && task_workflow_status("AV", "", "pending", false, false, "ติดตั้งอุปกรณ์แล้ว") === "completed"
    && task_workflow_status("AV", "", "pending", false, false, "", true) === "completed"
    && task_workflow_status("AV", "", "cancelled") === "cancelled",
    "IT and AV workflows derive in-progress/completed status automatically"
);
expect_true(
    str_contains($report_source, 'id="reportEditSolution"')
    && str_contains($report_source, 'id="reportEditWorkAction"')
    && str_contains($report_source, "updateITEditWorkflow")
    && str_contains($report_source, 'workActionControl?.addEventListener("input", updateITEditWorkflow)')
    && str_contains($report_source, '$can_control_task_status')
    && str_contains($report_source, 'id="reportEditAutoStatusGroup"')
    && str_contains($report_source, "overflow-y: auto")
    && str_contains($report_source, "overscroll-behavior: contain"),
    "Report Edit shows read-only USER status, preserves admin controls and scrolls internally"
);
expect_true(
    str_contains($task_input_source, 'record_task_activity(')
    && str_contains($task_edit_source, 'record_task_update_activities(')
    && str_contains($activity_source, '"status_changed"')
    && str_contains($report_source, "task-activity-list")
    && str_contains($dashboard_source, "dashboard-activity-list")
    && str_contains($activity_source, "load_task_activities"),
    "task create, edit, status and detail views integrate activity history"
);
expect_true(
    str_contains($task_input_source, 'id="taskImagePreview"')
    && str_contains($task_input_source, "URL.createObjectURL")
    && str_contains($task_input_source, "files.length > 5"),
    "Task Input previews and validates image attachments before upload"
);
expect_true(
    str_contains($task_input_source, "&task_id=")
    && str_contains($task_input_source, '$new_task_id')
    && str_contains($task_input_source, "เปิดดูงานนี้")
    && str_contains($task_input_css_source, ".task-submit-bar"),
    "Task Input links to the newly created task and keeps responsive actions visible"
);
expect_true(
    str_contains($task_edit_source, 'id="taskEditForm"')
    && str_contains($task_edit_source, 'id="taskTitle"')
    && str_contains($task_edit_source, 'id="taskStatus"')
    && str_contains($task_edit_source, 'task_input.css'),
    "Task Edit reuses the same guided form structure as Task Input"
);
expect_true(
    str_contains($task_edit_source, 'class="task-existing-image')
    && str_contains($task_edit_source, 'id="taskImagePreview"')
    && str_contains($task_edit_source, "files.length > 5"),
    "Task Edit renders existing images and validates new attachments"
);
expect_true(
    !str_contains($task_edit_source, "oldStartTimeInput")
    && !str_contains($task_edit_source, "replaceWith(")
    && !str_contains($task_edit_source, "existingImagesMarkup")
    && !str_contains($task_edit_source, 'timeSection.querySelector(".card-body").innerHTML'),
    "Task Edit no longer rebuilds its PHP form with JavaScript"
);

$base_url = rtrim((string) (getenv("SMOKE_BASE_URL") ?: "http://127.0.0.1/it-av-task-system"), "/");
[$status, $location] = http_status($base_url . "/");
expect_true($status === 302 && str_contains($location, "auth/login.php"), "root redirects to Login");
[$status, $location] = http_status($base_url . "/dashboard/");
expect_true($status === 302 && str_contains($location, "auth/login.php"), "Dashboard requires authentication");
[$status] = http_status($base_url . "/config/db.php");
expect_true($status === 403, "database wrapper is blocked over HTTP");
[$status] = http_status($base_url . "/create_admin.php");
expect_true($status === 404, "legacy admin creator is absent");
[$status] = http_status($base_url . "/uploads/");
expect_true($status === 403, "upload root directory listing is disabled");
[$status] = http_status($base_url . "/auth/logout.php");
expect_true($status === 405, "Logout rejects GET requests");
[$status] = http_status($base_url . "/auth/register.php");
expect_true($status === 404, "public registration endpoint is disabled");
[$status] = http_status($base_url . "/tools/apply_database_migrations.php");
expect_true($status === 403, "maintenance tools are blocked over HTTP");
[$status] = http_status($base_url . "/tests/smoke.php");
expect_true($status === 403, "test utilities are blocked over HTTP");
[$status] = http_status($base_url . "/backups/");
expect_true($status === 403, "database backups are blocked over HTTP");

echo PHP_EOL, "Checks: {$checks}; Failures: ", count($failures), PHP_EOL;
exit($failures === [] ? 0 : 1);
