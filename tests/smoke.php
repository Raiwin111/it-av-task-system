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
    && (int) $task_ownership["arm_total"] > 0
    && (int) $task_ownership["arm_total"] <= (int) $task_ownership["total"],
    "Arm-owned task history remains while other valid users may create tasks"
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
$config_css_source = file_get_contents(__DIR__ . "/../config/config.css");
$account_settings_source = file_get_contents(__DIR__ . "/../account_settings/index.php");
$profile_source = file_get_contents(__DIR__ . "/../profile/index.php");
$activity_source = file_get_contents(__DIR__ . "/../includes/task_activity.php");
$register_source = file_get_contents(__DIR__ . "/../auth/register.php");
$help_source = file_get_contents(__DIR__ . "/../help/index.php");
$system_overview_source = file_get_contents(__DIR__ . "/../SYSTEM_OVERVIEW.md");
$security_permissions_source = file_get_contents(__DIR__ . "/../SECURITY_AND_PERMISSIONS.md");
$database_reference_source = file_get_contents(__DIR__ . "/../DATABASE_REFERENCE.md");
$ux_ui_guide_source = file_get_contents(__DIR__ . "/../UX_UI_GUIDE.md");
expect_true(
    str_contains($report_source, '.task-detail-section { overflow: hidden;')
    && str_contains($report_source, 'background: #eaf3fb;')
    && str_contains($report_source, '.task-detail-section--problem .task-detail-section-heading')
    && str_contains($report_source, 'background: #fff3e9;')
    && str_contains($report_source, '.task-detail-section--solution .task-detail-section-heading')
    && str_contains($report_source, 'background: #edf8f0;'),
    "Task Details uses bordered cards and semantic section-header backgrounds"
);
expect_true(
    str_contains($sidebar_source, "Report")
    && str_contains($sidebar_source, "Account Settings")
    && str_contains($sidebar_source, "System Config")
    && str_contains($sidebar_source, "bi-person-gear")
    && str_contains($sidebar_source, 'href="../help/"')
    && str_contains($sidebar_source, 'nav-link mt-auto align-self-start')
    && str_contains($sidebar_source, '<span class="visually-hidden">คู่มือ</span>'),
    "shared navigation uses the agreed terminology and real routes"
);
expect_true(!str_contains($sidebar_source, 'href="#"'), "shared navigation has no placeholder routes");
expect_true(
    str_contains($sidebar_source, 'href="../account_settings/"')
    && str_contains($sidebar_source, 'href="../config/"')
    && str_contains($sidebar_source, "Account Settings")
    && str_contains($sidebar_source, "System Config")
    && str_contains($account_settings_source, 'action" value="change_own_password"')
    && str_contains($account_settings_source, 'action" value="update_own_profile"')
    && str_contains($account_settings_source, 'id="profileSettings"')
    && str_contains($account_settings_source, 'id="accountUsername" name="username"')
    && str_contains($account_settings_source, 'id="accountDepartment"')
    && str_contains($account_settings_source, 'id="accountRole"')
    && str_contains($account_settings_source, 'col-lg-3 d-flex flex-column align-items-center')
    && str_contains($account_settings_source, 'col-lg-5"><div class="d-grid gap-3"')
    && str_contains($account_settings_source, 'col-lg-4"><div class="h-100 rounded-3 border bg-light')
    && str_contains($account_settings_source, 'id="accountProfileFileName"')
    && !str_contains($account_settings_source, 'id="accountEmail"')
    && str_contains($account_settings_source, 'UPDATE users SET username = ?, full_name = NULLIF(?, \'\')')
    && str_contains($account_settings_source, 'id="changePasswordModal"')
    && substr_count($account_settings_source, 'data-account-password-toggle=') === 3
    && str_contains($account_settings_source, 'btn btn-danger')
    && str_contains($account_settings_source, 'account_password_meets_policy')
    && str_contains($account_settings_source, "preg_match('/[a-z]/'")
    && str_contains($account_settings_source, "preg_match('/[A-Z]/'")
    && str_contains($account_settings_source, 'data-password-rule="special"')
    && str_contains($config_source, 'action" value="create_user"')
    && str_contains($config_source, 'header("Location: ../account_settings/?error=config_forbidden")')
    && !str_contains($config_source, 'id="profileSettings"')
    && !str_contains($config_source, 'id="changePasswordModal"')
    && !str_contains($config_source, "รออนุมัติ")
    && str_contains($profile_source, 'header("Location: ../account_settings/#profileSettings")')
    && str_contains($header_source, 'href="../account_settings/#profileSettings"'),
    "Account Settings is separate from ADMIN-only System Config"
);
expect_true(
    str_contains($config_source, 'config-edit-user-modal')
    && str_contains($config_source, 'config-edit-user-header')
    && str_contains($config_source, 'btn-close btn-close-white')
    && str_contains($config_source, 'htmlspecialchars(ucfirst(strtolower($role))')
    && str_contains($config_css_source, 'background: linear-gradient(135deg, #2080dc, #1769c2)')
    && str_contains($config_css_source, 'color: #fff'),
    "System Config edit-user modal uses English role labels and a readable blue header"
);
expect_true(
    str_contains($register_source, "http_response_code(404)")
    && !str_contains($login_source, 'href="register.php"')
    && !str_contains($login_source, "สมัครสมาชิกเรียบร้อยแล้ว"),
    "public registration is disabled and accounts are provisioned through Config"
);
expect_true(
    str_contains($login_source, '$_SESSION["login_feedback"] = [')
    && str_contains($login_source, 'unset($_SESSION["login_csrf_token"]);')
    && str_contains($login_source, 'header("Location: login.php");')
    && str_contains($login_source, '$_SESSION["login_device_failed_attempts"]')
    && str_contains($login_source, '$_SESSION["login_device_lock_until"]')
    && !str_contains($login_source, '$recent_ip_failures')
    && !str_contains($login_source, '$new_attempt_count = (int) $user["failed_login_attempts"] + 1')
    && str_contains($login_source, "switching users cannot submit stale credentials"),
    "failed login uses Post/Redirect/Get and one browser-scoped counter across usernames"
);
expect_true(
    substr_count($config_source, "failed_login_attempts = 0, lock_until = NULL") >= 2
    && substr_count($config_source, 'autocomplete="new-password"') >= 5,
    "password updates clear account locks and password fields resist browser autofill"
);
expect_true(
    str_contains($help_source, "ทุก Role ใช้ Account Settings")
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
    str_contains($header_source, '.topbar { position: fixed; top: 0; right: 0; left: 0; z-index: 1040; height: 82px;')
    && str_contains($header_source, '.brand-mark { width: 46px; height: 46px;')
    && str_contains($header_source, '.brand-title { color: #f8fafc; font-size: 1.22rem;')
    && str_contains($header_source, '.profile-avatar { width: 50px; height: 50px;')
    && str_contains($header_source, '.profile-username { color: #f8fafc; font-size: 1.05rem;'),
    "shared header enlarges branding and profile identity while retaining mobile sizing"
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
    str_contains($dashboard_source, '>ภาพรวมงาน</h1>')
    && str_contains($dashboard_source, '>ดูสถานะงานที่ต้องติดตามและงานล่าสุด</p>')
    && !str_contains($dashboard_source, "ภาพรวมงาน IT / AV")
    && str_contains($dashboard_source, '$dashboard_can_filter_team = current_role() === "ADMIN";')
    && str_contains($dashboard_source, "team-switch")
    && str_contains($dashboard_source, "งานที่ต้องติดตาม"),
    "Dashboard uses team-scoped copy and reserves team switching for ADMIN"
);
expect_true(
    substr_count($dashboard_source, "<canvas") === 1
    && str_contains($dashboard_source, 'id="taskTrendChart"')
    && str_contains($dashboard_source, 'dashboard-trend-widget')
    && strpos($dashboard_source, '<div><h2 class="page-heading h5 fw-bold mb-1">แนวโน้มจำนวนงาน</h2>') < strpos($dashboard_source, '<h2 class="page-heading h5 fw-bold mb-1">งานที่ต้องติดตาม</h2>')
    && str_contains($dashboard_css_source, ".dashboard-trend-widget .dashboard-chart")
    && !str_contains($dashboard_source, "summaryReportChart"),
    "Dashboard keeps one full-width trend chart above the follow-up work section"
);
expect_true(
    str_contains($report_source, 'report-filter-title-icon')
    && str_contains($report_source, '.report-filter-heading { display: flex;')
    && str_contains($report_source, 'background: linear-gradient(120deg, #f8fbfe, #eaf3fb);')
    && str_contains($dashboard_source, 'dashboard-filter-title-icon')
    && str_contains($dashboard_source, 'dashboard-filter-fields')
    && str_contains($dashboard_css_source, '.dashboard-filter-section-title')
    && str_contains($dashboard_css_source, '.dashboard-filter-summary'),
    "Report and Dashboard filters share polished modal hierarchy and responsive styling"
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
    && str_contains($report_source, '<?php if ($report_can_filter_team): ?>')
    && !str_contains($report_source, '<span class="report-team-link active"><i class="bi bi-people"></i>ทีม')
    && str_contains($report_source, '<h1 class="page-heading h3 fw-bold mb-1">Report</h1>')
    && str_contains($report_source, 'id="reportSearchButton"'),
    "Report exposes team switching only to SUPER/ADMIN and keeps explicit search"
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
    && !str_contains($task_input_source, 'class="task-form-guide')
    && str_contains($task_input_source, 'task-section-card task-image-section')
    && str_contains($task_input_source, 'id="submitTaskButton"')
    && str_contains($task_input_source, 'task_input.css'),
    "Task Input presents a focused form without a redundant step guide"
);
expect_true(
    str_contains($task_input_source, 'task-page-heading-icon')
    && str_contains($task_input_source, 'bi-clipboard2-plus-fill')
    && str_contains($task_input_css_source, '.task-page-heading::before')
    && str_contains($task_input_css_source, 'background: linear-gradient(125deg, #ffffff 0%, #f2f7fc 68%, #e7f2fc 100%);')
    && str_contains($task_input_css_source, '.task-section-card .section-icon')
    && str_contains($task_input_css_source, 'border-top: 3px solid #2780d4;'),
    "Task Input uses a polished page hero, section cards and action hierarchy"
);
expect_true(
    str_contains($task_input_source, 'id="avDetailsSection"')
    && str_contains($task_input_source, 'id="avEquipmentGuide"')
    && !str_contains($task_input_source, 'name="category"')
    && !str_contains($task_input_source, 'name="work_action"')
    && !str_contains($task_input_source, 'name="problem"')
    && !str_contains($task_input_source, 'name="solution"')
    && !str_contains($task_input_source, 'name="remark"')
    && !str_contains($task_input_source, "task_problem_is_required")
    && !str_contains($task_input_source, 'id="taskStatusSelectGroup"')
    && str_contains($task_input_source, 'type="hidden" id="taskStatus"')
    && !str_contains($task_input_source, "problem_options.js"),
    "Task Input keeps IT creation basic and AV creation focused on equipment only"
);
expect_true(
    task_problem_is_required("IT", "")
    && !task_problem_is_required("IT", "เปิดเครื่องไม่ติด")
    && !task_problem_is_required("AV", "")
    && task_workflow_status("IT", "", "pending", true) === "pending"
    && task_workflow_status("IT", "", "in_progress", false) === "pending"
    && task_workflow_status("IT", "", "pending", false, false, "", true) === "pending"
    && task_workflow_status("IT", "แก้ไขแล้ว", "pending", false, false, "", false) === "completed"
    && task_workflow_status("IT", "เปลี่ยนสายไฟ", "in_progress") === "completed"
    && task_workflow_status("IT", "", "cancelled") === "cancelled"
    && task_workflow_status("IT", "มีวิธีแก้", "cancelled", false, true) === "cancelled"
    && task_workflow_status("AV", "", "pending", true) === "in_progress"
    && task_workflow_status("AV", "", "pending", false, false, "ติดตั้งอุปกรณ์แล้ว") === "completed"
    && task_workflow_status("AV", "", "pending", false, false, "", true) === "completed"
    && task_workflow_status("AV", "", "cancelled") === "cancelled",
    "IT uses pending/completed from Solution while AV keeps its existing workflow"
);
expect_true(
    str_contains($task_input_source, 'name="equipment_id[]"')
    && str_contains($task_input_source, 'name="equipment_quantity[')
    && str_contains($report_source, "task_equipments")
    && str_contains($report_source, 'id="reportEditEquipmentGroup"')
    && !str_contains($task_input_source, "taskTitleHistoryItems")
    && !str_contains($task_input_source, "taskTitleHistoryMenu")
    && !str_contains($task_input_source, "fillCurrentFinishTime")
    && !str_contains($task_edit_source, "fillCurrentFinishTime")
    && !str_contains($report_source, "fillCurrentFinishTime"),
    "AV equipment is structured, title is plain text and finish time is never auto-filled"
);
expect_true(
    str_contains($task_input_source, '"display_name" => "จอโปรเจ็คเตอร์"')
    && str_contains($task_input_source, '"display_name" => "จอ LED"')
    && str_contains($task_input_source, '"display_name" => "ไมค์ลอย"')
    && str_contains($task_input_source, '"display_name" => "เครื่องเสียงของทางโรงแรม"')
    && str_contains($task_input_source, 'id="equipmentPicker"')
    && str_contains($task_input_source, 'data-equipment-row')
    && str_contains($task_input_source, 'data-equipment-id')
    && str_contains($task_input_source, 'data-quantity-action="decrease"')
    && str_contains($task_input_source, 'data-quantity-action="increase"')
    && str_contains($task_input_source, 'const existingRow = equipmentRows.querySelector')
    && str_contains($task_input_source, 'quantity.value = Math.max(1, Number(quantity.value) || 1) + 1')
    && !str_contains($task_input_source, 'quick_add_equipment')
    && !str_contains($task_input_source, 'addEquipmentMasterModal')
    && !str_contains($task_input_source, 'id="addEquipmentRow"')
    && !str_contains($task_input_source, 'type="checkbox" name="equipment_id[]"')
    && !str_contains($task_input_source, 'otherEquipment')
    && !str_contains($task_input_source, 'other_equipment')
    && !str_contains($task_input_source, 'อื่นๆ ระบุ')
    && !str_contains($task_input_source, 'name="work_description"')
    && !str_contains($task_input_source, 'รายละเอียด Event / งาน')
    && !str_contains($config_source, "equipment")
    && !str_contains($config_source, "Equipment"),
    "AV Task Input uses a fixed dropdown, merges repeated choices and adjusts selected quantities"
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
    str_contains($report_source, 'report-edit-title-icon')
    && str_contains($report_source, 'report-edit-section--primary')
    && str_contains($report_source, 'report-edit-section--details')
    && str_contains($report_source, 'report-edit-section--time')
    && str_contains($report_source, '.report-edit-section-heading { display: flex;')
    && str_contains($report_source, '.report-edit-modal #reportEditEquipmentGroup'),
    "Report Edit modal uses polished headers, semantic section cards and focused controls"
);
expect_true(
    str_contains($report_source, 'data-bs-target="#reportEditTaskModal" data-edit-task-id=')
    && str_contains($report_source, 'if (!window.bootstrap?.Modal)')
    && str_contains($report_source, 'window.addEventListener("load", () => openEditModal(task, currentModal), { once: true });')
    && str_contains($report_source, 'window.bootstrap.Modal.getOrCreateInstance(modalElement)'),
    "Report Edit buttons retain a Bootstrap fallback and wait until the modal runtime is ready"
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
    && str_contains($task_input_source, "files.length > 5")
    && !str_contains($task_input_source, 'class="task-form-guide')
    && str_contains($task_input_source, 'task-section-card task-image-section')
    && strpos($task_input_source, 'task-section-card task-image-section') < strpos($task_input_source, 'id="avDetailsSection"')
    && strpos($task_input_source, 'task-section-card task-image-section') < strpos($task_input_source, 'class="col-xl-4"')
    && str_contains($task_input_source, 'col-6 col-md-4 col-lg-3'),
    "Task Input removes the step guide and places responsive image attachments below primary task data"
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
