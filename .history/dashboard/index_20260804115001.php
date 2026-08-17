<?php
require_once __DIR__ . "/../auth/auth_check.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/constants.php";

$username = htmlspecialchars($_SESSION["username"] ?? "Administrator", ENT_QUOTES, "UTF-8");
$dashboard_counts = ["total" => 0, "pending" => 0, "in_progress" => 0, "completed" => 0, "cancelled" => 0];
$dashboard_role = strtoupper($_SESSION["role"] ?? "USER");
$dashboard_user_id = (int) ($_SESSION["user_id"] ?? 0);
// All users can view task statistics for every task.
$count_result = $conn->query("SELECT status, COUNT(*) AS total FROM tasks WHERE is_deleted = 0 GROUP BY status");
while ($row = $count_result->fetch_assoc()) {
    $key = strtolower(str_replace(" ", "_", trim($row["status"])));
    $key = ["รอดำเนินการ" => "pending", "กำลังดำเนินการ" => "in_progress", "เสร็จสิ้น" => "completed", "ยกเลิก" => "cancelled"][$key] ?? $key;
    $dashboard_counts["total"] += (int) $row["total"];
    if (isset($dashboard_counts[$key])) $dashboard_counts[$key] += (int) $row["total"];
}
// All users can view recent tasks. Pagination keeps the dashboard table compact.
$recent_tasks_per_page = 5;
$recent_task_total_result = $conn->query("SELECT COUNT(*) AS total FROM tasks WHERE is_deleted = 0");
$recent_task_total = (int) ($recent_task_total_result->fetch_assoc()["total"] ?? 0);
$recent_task_total_pages = max(1, (int) ceil($recent_task_total / $recent_tasks_per_page));
$recent_task_page = max(1, (int) ($_GET["recent_page"] ?? 1));
$recent_task_page = min($recent_task_page, $recent_task_total_pages);
$recent_task_offset = ($recent_task_page - 1) * $recent_tasks_per_page;
$recent_tasks = $conn->query("SELECT t.*, COALESCE(NULLIF(t.responsible_name, ''), u.department, '-') AS created_by_name FROM tasks t LEFT JOIN users u ON u.id = t.created_by WHERE t.is_deleted = 0 ORDER BY t.created_at DESC, t.id DESC LIMIT {$recent_tasks_per_page} OFFSET {$recent_task_offset}");
$recent_task_rows = $recent_tasks->fetch_all(MYSQLI_ASSOC);
$recent_tasks->data_seek(0);
// Donut chart data uses the same active-task counts as the dashboard KPI cards.
$summary_chart_labels = ["รอดำเนินการ", "กำลังดำเนินการ", "เสร็จสิ้น", "ยกเลิก"];
$summary_chart_values = [
    $dashboard_counts["pending"],
    $dashboard_counts["in_progress"],
    $dashboard_counts["completed"],
    $dashboard_counts["cancelled"]
];
function dashboard_status_meta(string $status): array { $key = strtolower(str_replace(" ", "_", trim($status))); $key = ["รอดำเนินการ"=>"pending","กำลังดำเนินการ"=>"in_progress","เสร็จสิ้น"=>"completed","ยกเลิก"=>"cancelled"][$key] ?? $key; $labels = ["pending"=>"รอดำเนินการ","in_progress"=>"กำลังดำเนินการ","completed"=>"เสร็จสิ้น","cancelled"=>"ยกเลิก"]; $classes = ["pending"=>"status-pending","in_progress"=>"status-progress","completed"=>"status-completed","cancelled"=>"status-cancelled"]; return [$labels[$key] ?? htmlspecialchars($status, ENT_QUOTES, "UTF-8"), $classes[$key] ?? "status-pending"]; }
// Historical task counts are real database data; the selected chart range is changed in the browser.
$trend_data = ["day" => [], "week" => [], "month" => [], "year" => []];
$thai_weekdays = [1 => "จันทร์", 2 => "อังคาร", 3 => "พุธ", 4 => "พฤหัสบดี", 5 => "ศุกร์", 6 => "เสาร์", 7 => "อาทิตย์"];
$thai_date = static function (int $time): string { return date("d/m/", $time) . (date("Y", $time) + 543); };
$trend_range_text = [];
$oldest_task_result = $conn->query("SELECT MIN(created_at) AS oldest_created_at FROM tasks WHERE is_deleted = 0");
$oldest_task_row = $oldest_task_result->fetch_assoc();
$history_start_day = !empty($oldest_task_row["oldest_created_at"])
    ? strtotime(date("Y-m-d", strtotime($oldest_task_row["oldest_created_at"])))
    : strtotime(date("Y-m-d"));
$today_start = strtotime(date("Y-m-d"));
$daily_keys = [];
for ($day_time = $history_start_day; $day_time <= $today_start; $day_time = strtotime("+1 day", $day_time)) {
    $date_key = date("Y-m-d", $day_time);
    $daily_keys[$date_key] = count($trend_data["day"]);
    $trend_data["day"][] = ["label" => $thai_weekdays[(int) date("N", $day_time)], "value" => 0];
}
$trend_range_text["day"] = "ช่วงวันที่ " . $thai_date(strtotime("-6 days")) . " - " . $thai_date(time());
$weekly_keys = [];
$history_week_start = strtotime("monday this week", $history_start_day);
$current_week_start = strtotime("monday this week", $today_start);
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
$trend_result = $conn->query("SELECT created_at FROM tasks WHERE is_deleted = 0 ORDER BY created_at ASC");
while ($trend_row = $trend_result->fetch_assoc()) {
    $created_time = strtotime($trend_row["created_at"]);
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
$trend_range_text["day"] = "ช่วงวันที่ " . $thai_date($history_start_day) . " - " . $thai_date($today_start);
$trend_range_text["week"] = "ช่วงวันที่ " . $thai_date($history_start_day) . " - " . $thai_date($today_start);
$trend_range_text["month"] = "ช่วงวันที่ " . $thai_date($history_start_day) . " - " . $thai_date($today_start);
$trend_range_text["year"] = "ช่วงปี " . $trend_data["year"][0]["label"] . " - " . $trend_data["year"][count($trend_data["year"]) - 1]["label"];
$trend_range_text["year"] = "ช่วงปี " . $trend_data["year"][0]["label"] . " - " . $trend_data["year"][count($trend_data["year"]) - 1]["label"];
$trend_range_text["day"] = "ช่วงวันที่ " . $thai_date($history_start_day) . " - " . $thai_date($today_start);
$trend_range_text["week"] = "ช่วงวันที่ " . $thai_date($history_start_day) . " - " . $thai_date($today_start);
$trend_range_text["month"] = "ช่วงวันที่ " . $thai_date($history_start_day) . " - " . $thai_date($today_start);
$trend_range_text["year"] = "ช่วงปี " . $trend_data["year"][0]["label"] . " - " . $trend_data["year"][count($trend_data["year"]) - 1]["label"];
?>
<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Dashboard | IT / AV Task Management System</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet"><link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet"><style>
:root{--navy:#0f172a;--blue:#1769c2;--page-bg:#f4f7fb;--sidebar-width:260px}body{color:#26394d;background:var(--page-bg);font-family:"Poppins","Inter","Segoe UI",sans-serif}.topbar{position:fixed;inset:0 0 auto;z-index:1040;height:72px;color:#e5edf7;background:#111827;box-shadow:0 3px 16px rgba(2,6,23,.35)}.brand-mark{width:38px;height:38px;color:#fff;background:linear-gradient(135deg,#237bd1,#103d6d)}.brand-title{color:#f8fafc;font-size:1rem}.sidebar{width:var(--sidebar-width);background:#0f172a}.desktop-sidebar{position:fixed;top:0;left:0;z-index:1030;height:100vh;padding-top:5.5rem!important;overflow-y:auto;box-shadow:4px 0 16px rgba(2,6,23,.18)}.sidebar .nav-link{color:#cbd5e1;border-radius:.55rem;font-weight:500;padding:.78rem 1rem;margin-bottom:.25rem;white-space:nowrap}.sidebar .nav-link:hover,.sidebar .nav-link.active{color:#fff;background:#1e293b}.sidebar .nav-link i{width:1.5rem}.sidebar-label{color:#94a3b8;font-size:.68rem;letter-spacing:.1em}.main-content{min-width:0}.page-heading{color:var(--navy)}.profile-avatar{width:40px;height:40px;color:#fff;background:#1e3a5f}.profile-username{color:#f8fafc}.role-badge{display:inline-block;margin-top:.2rem;padding:.18rem .5rem;color:#fff;border-radius:999px;background:#134e4a;font-size:.69rem;font-weight:700;text-transform:uppercase}.user-detail{line-height:1.2}.summary-card,.monitor-widget,.table-card{border:0;border-radius:.9rem;box-shadow:0 5px 18px rgba(26,57,89,.07)}.summary-card{transition:transform .2s ease}.summary-card:hover{transform:translateY(-3px)}.metric-icon,.section-icon{width:52px;height:52px;border-radius:.8rem;font-size:1.4rem}.metric-total,.section-icon{color:#1769c2;background:#e8f2fd}.metric-pending{color:#b7791f;background:#fff5dd}.metric-progress{color:#5b4db1;background:#eeeafe}.metric-completed{color:#21805c;background:#e3f6ed}.metric-label,.widget-kpi-label{color:#718096;font-size:.76rem;font-weight:700;letter-spacing:.045em;text-transform:uppercase}.metric-value,.widget-kpi-value{color:var(--navy);font-size:1.6rem}.monitor-widget .card-header,.table-card .card-header{background:#fff;border-bottom:1px solid #e8edf3}.form-label{color:#405367;font-size:.9rem;font-weight:600}.form-control,.form-select{min-height:44px;border-color:#dce5ef;border-radius:.55rem}.chart-placeholder{display:grid;min-height:100%;place-items:center;padding:clamp(2.5rem,8vw,5.5rem) 1.5rem;border:1px dashed #cbd8e6;border-radius:.75rem;background:#f8fafc;color:#718096;text-align:center}.additional-information summary{cursor:pointer;list-style:none}.additional-information summary::-webkit-details-marker{display:none}.additional-information summary::after{float:right;color:#1769c2;content:"＋";font-size:1.1rem}.additional-information[open] summary::after{content:"−"}.table thead th{color:#718096;background:#f8fafc;border:0;font-size:.73rem;text-transform:uppercase}.table td{border-color:#edf1f5;vertical-align:middle}.status-pending{color:#9a640d;background:#fff1cc}.status-progress{color:#5142a5;background:#e9e4ff}.status-completed{color:#17734f;background:#dff5e9}.status-cancelled{color:#b42318;background:#feecee}.topbar+.app-shell{padding-top:72px}@media(min-width:992px){.desktop-sidebar{display:block!important}.offcanvas-sidebar{display:none}.main-content{margin-left:var(--sidebar-width)}}@media(max-width:575.98px){.topbar{height:64px}.topbar+.app-shell{padding-top:64px}.brand-title{font-size:.85rem}.hide-mobile{display:none}.main-content{padding:1.5rem!important}}
/* UI readability and enterprise contrast refinements. */body{background:#eef2f7;font-size:1.05rem}.topbar{background:linear-gradient(90deg,#0b1220,#111827);box-shadow:0 4px 18px rgba(2,6,23,.42)}.brand-title{color:#fff;font-size:1.1rem}.sidebar{background:linear-gradient(180deg,#111827,#0b1220);box-shadow:4px 0 18px rgba(2,6,23,.24)}.sidebar .nav-link{color:#d7e1ee;font-size:1.05rem;transition:background .2s ease,color .2s ease,transform .2s ease}.sidebar .nav-link i{color:#a9c8e8}.sidebar .nav-link:hover{color:#fff;background:#1d3652;transform:translateX(2px)}.sidebar .nav-link.active{color:#fff;background:#23476d;box-shadow:inset 3px 0 0 #6cb5ff}.sidebar-label{color:#9db5cf;font-size:.78rem}.profile-username{font-size:.95rem}.role-badge{font-size:.78rem;box-shadow:0 1px 0 rgba(255,255,255,.12) inset}.summary-card,.monitor-widget,.table-card{background:#fbfcfe;border:1px solid #d9e3ee;box-shadow:0 8px 24px rgba(26,57,89,.10)}.monitor-widget .card-header,.table-card .card-header{background:#f7f9fc;border-bottom-color:#d9e3ee}.metric-label,.widget-kpi-label{font-size:.9rem}.metric-value,.widget-kpi-value{font-size:1.78rem}.form-label{font-size:1.02rem}.form-control,.form-select{font-size:1rem;border-color:#cbd8e6;background:#fff}.table{font-size:1rem}.table thead th{font-size:.9rem;color:#52677f;background:#eef3f8}.badge{font-size:.86rem;box-shadow:0 1px 2px rgba(15,23,42,.10)}
.summary-card { background: #fff; }/* Match Dashboard navbar sizing with the shared Report and Task Input header. */
.topbar .brand-title { font-size: 1.1rem; }
.topbar .profile-username { font-size: .95rem; }
.topbar .role-badge { font-size: .78rem; }
.topbar .btn { font-size: 1rem; font-weight: 600; }
.topbar .btn.btn-sm { font-size: 1rem; }.dashboard-chart { position: relative; height: 300px; }
.dashboard-task-row { cursor: pointer; }
.dashboard-task-row:focus { outline: 2px solid rgba(23, 105, 194, .45); outline-offset: -2px; }
/* Match the Report page task-detail modal design. */
#dashboardTaskDetailModal .modal-dialog { max-width: 900px; }
#dashboardTaskDetailModal .modal-content { overflow: hidden; border: 1px solid #d7e3ef; border-radius: 1rem; box-shadow: 0 18px 48px rgba(15, 35, 57, .22); }
#dashboardTaskDetailModal .modal-header { padding: 1.05rem 1.35rem; color: #153b63; border-bottom: 1px solid #cfe0f0; background: linear-gradient(135deg, #eef6ff, #f8fbff); }
#dashboardTaskDetailModal .modal-title { font-weight: 700; }
#dashboardTaskDetailModal .modal-body { padding: 1.35rem; background: #fbfdff; }
#dashboardTaskDetailModal .modal-body .row > div { min-height: 100%; padding: .85rem 1rem; color: #334e68; border: 1px solid #dce8f3; border-radius: .7rem; background: #fff; box-shadow: 0 2px 8px rgba(22, 65, 104, .045); }
#dashboardTaskDetailModal .modal-body strong { display: block; margin-bottom: .35rem; color: #1b4f7f; font-size: .84rem; font-weight: 700; }
#dashboardTaskDetailModal .modal-body .mt-1 { margin-top: 0 !important; line-height: 1.6; color: #405970; }
#dashboardTaskDetailModal .modal-footer { padding: .9rem 1.35rem; border-top: 1px solid #dce8f3; background: #f7faff; }
#dashboardTaskDetailModal .badge { padding: .42rem .68rem; box-shadow: none; }
@media (max-width: 575.98px) { #dashboardTaskDetailModal .modal-body { padding: 1rem; } #dashboardTaskDetailModal .modal-body .row > div { padding: .75rem .85rem; } }
.dashboard-chart-wide { height: 280px; }</style></head><body>
<header class="topbar d-flex align-items-center px-3 px-lg-4"><button class="btn btn-light d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu"><i class="bi bi-list fs-5"></i></button><a class="d-flex align-items-center text-decoration-none" href="index.php"><span class="brand-mark rounded-3 d-inline-flex align-items-center justify-content-center me-2"><i class="bi bi-grid-1x2-fill"></i></span><span class="brand-title fw-bold">IT / AV Task Management System</span></a><div class="ms-auto d-flex align-items-center gap-3"><div class="d-flex align-items-center gap-2"><span class="profile-avatar rounded-circle d-inline-flex align-items-center justify-content-center"><i class="bi bi-person-fill"></i></span><div class="user-detail hide-mobile"><div class="profile-username fw-semibold small"><?php echo $username; ?></div><span class="role-badge"><?php echo htmlspecialchars($_SESSION["role"] ?? "USER", ENT_QUOTES, "UTF-8"); ?></span></div></div><form method="post" action="../auth/logout.php" class="m-0"><button class="btn btn-outline-danger btn-sm px-3" type="submit"><i class="bi bi-box-arrow-right me-1"></i><span class="hide-mobile">Logout</span></button></form></div></header>
<div class="app-shell d-flex"><aside class="sidebar desktop-sidebar d-none p-3"><nav class="nav flex-column"><div class="sidebar-label fw-semibold px-3 mb-2 mt-1">MAIN MENU</div><a class="nav-link active" href="#"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a><a class="nav-link" href="../task_input/"><i class="bi bi-plus-square me-2"></i>บันทึกงาน</a><a class="nav-link" href="../report/"><i class="bi bi-bar-chart-line me-2"></i>รายงาน</a><div class="sidebar-label fw-semibold px-3 mb-2 mt-4">SYSTEM</div><a class="nav-link" href="#"><i class="bi bi-gear me-2"></i>Config</a><a class="nav-link" href="#"><i class="bi bi-question-circle me-2"></i>คู่มือ</a></nav></aside><div class="offcanvas offcanvas-start offcanvas-sidebar sidebar text-white" tabindex="-1" id="sidebarMenu"><div class="offcanvas-header border-bottom border-light border-opacity-25"><h5 class="offcanvas-title">Navigation</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button></div><div class="offcanvas-body p-3"><nav class="nav flex-column"><a class="nav-link active" href="#"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a><a class="nav-link" href="../task_input/"><i class="bi bi-plus-square me-2"></i>บันทึกงาน</a><a class="nav-link" href="../report/"><i class="bi bi-bar-chart-line me-2"></i>รายงาน</a><a class="nav-link mt-3" href="#"><i class="bi bi-gear me-2"></i>Config</a><a class="nav-link" href="#"><i class="bi bi-question-circle me-2"></i>คู่มือ</a></nav></div></div>
<main class="main-content flex-grow-1 p-4 p-lg-5"><div class="mb-4"><h1 class="page-heading h3 fw-bold mb-1">ภาพรวมแดชบอร์ด</h1><p class="text-muted mb-0">ติดตามกิจกรรมและความคืบหน้าของงานในทีม</p></div><section class="row g-4 mb-5"><div class="col-sm-6 col-xl-3"><article class="card summary-card h-100"><div class="card-body d-flex align-items-center"><div class="metric-icon metric-total d-inline-flex align-items-center justify-content-center me-3"><i class="bi bi-clipboard-data"></i></div><div><div class="metric-label">งานทั้งหมด</div><div class="metric-value fw-bold"><?php echo $dashboard_counts["total"]; ?> <span class="fs-6">งาน</span></div></div></div></article></div><div class="col-sm-6 col-xl-3"><article class="card summary-card h-100"><div class="card-body d-flex align-items-center"><div class="metric-icon metric-pending d-inline-flex align-items-center justify-content-center me-3"><i class="bi bi-hourglass-split"></i></div><div><div class="metric-label">รอดำเนินการ</div><div class="metric-value fw-bold"><?php echo $dashboard_counts["pending"]; ?> <span class="fs-6">งาน</span></div></div></div></article></div><div class="col-sm-6 col-xl-3"><article class="card summary-card h-100"><div class="card-body d-flex align-items-center"><div class="metric-icon metric-progress d-inline-flex align-items-center justify-content-center me-3"><i class="bi bi-arrow-repeat"></i></div><div><div class="metric-label">กำลังดำเนินการ</div><div class="metric-value fw-bold"><?php echo $dashboard_counts["in_progress"]; ?> <span class="fs-6">งาน</span></div></div></div></article></div><div class="col-sm-6 col-xl-3"><article class="card summary-card h-100"><div class="card-body d-flex align-items-center"><div class="metric-icon metric-completed d-inline-flex align-items-center justify-content-center me-3"><i class="bi bi-check2-circle"></i></div><div><div class="metric-label">เสร็จสิ้น</div><div class="metric-value fw-bold"><?php echo $dashboard_counts["completed"]; ?> <span class="fs-6">งาน</span></div></div></div></article></div></section>
<section class="mb-5" aria-labelledby="summary-report-heading">
    <div class="mb-3"><h2 id="summary-report-heading" class="page-heading h4 fw-bold mb-1">Summary Report</h2><p class="text-muted mb-0">สรุปจำนวนงานจากข้อมูลทั้งหมดในระบบ</p></div>
    <article class="card monitor-widget"><div class="card-header py-3 px-4"><h3 class="page-heading h5 fw-bold mb-1">Summary Report</h3><p class="text-muted small mb-0">สัดส่วนสถานะงานจากข้อมูลทั้งหมดในระบบ</p></div><div class="card-body"><div class="dashboard-chart dashboard-chart-wide"><canvas id="summaryReportChart"></canvas></div></div></article>
</section><section class="card table-card"><div class="card-header d-flex align-items-center justify-content-between py-3 px-4"><div><h2 class="h5 mb-1 fw-bold page-heading">งานล่าสุด</h2><p class="text-muted small mb-0">ข้อมูลล่าสุดจากฐานข้อมูล</p></div><a class="btn btn-primary btn-sm px-3" href="../report/"><i class="bi bi-list-check me-1"></i>ดูทั้งหมด</a></div><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th class="ps-4 py-3">หัวข้องาน</th><th>แผนก</th><th>สถานะ</th><th class="pe-4">วันที่</th></tr></thead><tbody><?php if ($recent_tasks->num_rows === 0): ?><tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีข้อมูลงาน</td></tr><?php else: ?><?php while ($task = $recent_tasks->fetch_assoc()): ?><?php [$label, $class] = dashboard_status_meta($task["status"]); ?><tr><td class="ps-4 fw-semibold"><?php echo htmlspecialchars($task["title"], ENT_QUOTES, "UTF-8"); ?></td><td><?php echo htmlspecialchars($task["department"], ENT_QUOTES, "UTF-8"); ?></td><td><span class="badge rounded-pill <?php echo $class; ?>"><?php echo $label; ?></span></td><td class="pe-4"><?php echo date("d/m/", strtotime($task["created_at"])) . (date("Y", strtotime($task["created_at"])) + 543); ?></td></tr><?php endwhile; ?><?php endif; ?></tbody></table></div></section></main></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
<script>
    // Server-supplied chart data contains active tasks only.
    const summaryReportLabels = <?php echo json_encode($summary_chart_labels, JSON_UNESCAPED_UNICODE); ?>;
    const summaryReportValues = <?php echo json_encode($summary_chart_values); ?>;

    if (window.Chart) {
        const commonOptions = { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { usePointStyle: true } } } };

        new Chart(document.getElementById("summaryReportChart"), {
            type: "doughnut",
            data: { labels: summaryReportLabels.slice(0, 3), datasets: [{ data: summaryReportValues.slice(0, 3), backgroundColor: ["#d8a328", "#7968c9", "#2f8b62"], borderColor: "#fff", borderWidth: 3, hoverOffset: 8 }] },
            options: { ...commonOptions, cutout: "62%", plugins: { ...commonOptions.plugins, tooltip: { callbacks: { label: (context) => `${context.label}: ${context.raw} งาน` } } } }
        });
    }
</script><script>
    // Render the two responsive dashboard charts from real task data.
    (() => {
        const summarySection = document.querySelector('[aria-labelledby="summary-report-heading"]');
        const trendData = <?php echo json_encode($trend_data, JSON_UNESCAPED_UNICODE); ?>;
        const trendRangeText = <?php echo json_encode($trend_range_text, JSON_UNESCAPED_UNICODE); ?>;
        const statusLabels = summaryReportLabels.slice(0, 3);
        const statusValues = summaryReportValues.slice(0, 3);
        if (!summarySection || !window.Chart) return;

        Chart.getChart("summaryReportChart")?.destroy();
        summarySection.innerHTML = `
            <div class="mb-3">
                <h2 id="summary-report-heading" class="page-heading h4 fw-bold mb-1">สรุปรายงาน</h2>
                <p class="text-muted mb-0">ภาพรวมสถานะและจำนวนงานจากข้อมูลทั้งหมดในระบบ</p>
            </div>
            <div class="row g-4">
                <div class="col-lg-6">
                    <article class="card monitor-widget h-100">
                        <div class="card-header py-3 px-4"><h3 class="page-heading h5 fw-bold mb-1">สัดส่วนสถานะงาน</h3><p class="text-muted small mb-0">ข้อมูลสถานะงานทั้งหมดในระบบ</p></div>
                        <div class="card-body"><div class="dashboard-chart"><canvas id="statusDonutChart"></canvas></div></div>
                    </article>
                </div>
                <div class="col-lg-6">
                    <article class="card monitor-widget h-100">
                        <div class="card-header py-3 px-4 d-flex align-items-center justify-content-between gap-3">
                            <div><h3 class="page-heading h5 fw-bold mb-1">สถิติจำนวนงานย้อนหลัง</h3><p class="text-muted small mb-0" id="trendChartSubtitle">7 วันล่าสุด</p></div>
                            <select class="form-select form-select-sm w-auto" id="trendRange" aria-label="เลือกระยะเวลาสถิติงาน"><option value="day">วัน</option><option value="week">สัปดาห์</option><option value="month">เดือน</option><option value="year">ปี</option></select>
                        </div>
                        <div class="card-body"><div class="dashboard-chart"><canvas id="taskTrendChart"></canvas></div></div>
                    </article>
                </div>
            </div>`;

        const statusChart = new Chart(document.getElementById("statusDonutChart"), {
            type: "doughnut",
            data: { labels: statusLabels, datasets: [{ data: statusValues, backgroundColor: ["#d8a328", "#7968c9", "#2f8b62"], borderColor: "#fff", borderWidth: 3, hoverOffset: 8 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: "62%", plugins: { legend: { position: "bottom", labels: { usePointStyle: true, padding: 16 } }, tooltip: { callbacks: { label: (context) => `${context.label}: ${context.raw} งาน` } } } }
        });

        const rangeSelect = document.getElementById("trendRange");
        const trendSubtitle = document.getElementById("trendChartSubtitle");
        const trendChart = new Chart(document.getElementById("taskTrendChart"), {
            type: "bar",
            data: { labels: [], datasets: [{ label: "จำนวนงาน", data: [], backgroundColor: "rgba(23, 105, 194, .72)", borderColor: "#1769c2", borderWidth: 1, borderRadius: 6, maxBarThickness: 42 }] },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: "rgba(148, 163, 184, .18)" } }, x: { grid: { display: false } } }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: (context) => `จำนวนงาน: ${context.raw} งาน` } } } }
        });

        const updateTrendChart = () => {
            const range = rangeSelect.value;
            const points = trendData[range] || [];
            trendChart.data.labels = points.map((point) => point.label);
            trendChart.data.datasets[0].data = points.map((point) => point.value);
            trendSubtitle.textContent = trendRangeText[range] || "";
            trendChart.update();
        };
        rangeSelect.addEventListener("change", updateTrendChart);
        updateTrendChart();
    })();
</script><script>
    // Real, permission-scoped latest tasks supplied by the server.
    const dashboardLatestTasks = <?php echo json_encode($recent_task_rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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
            const date = new Date(value.replace(' ', 'T'));
            return new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).format(date) + ' น.';
        };
        const statusMeta = (status) => ({ pending: ['รอดำเนินการ', 'status-pending'], in_progress: ['กำลังดำเนินการ', 'status-progress'], completed: ['เสร็จสิ้น', 'status-completed'], cancelled: ['ยกเลิก', 'status-cancelled'] }[status] || [status, 'status-pending']);
        const paginationMarkup = (() => {
            const { page, totalPages } = dashboardRecentPagination;
            if (totalPages <= 1) return '';

            const pageLink = (number, label = number, disabled = false, active = false) =>
                `<li class="page-item${disabled ? ' disabled' : ''}${active ? ' active' : ''}"><a class="page-link" href="?recent_page=${number}"${disabled ? ' tabindex="-1" aria-disabled="true"' : ''}>${label}</a></li>`;
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

        const rows = dashboardLatestTasks.map((task) => {
            const [label, className] = statusMeta(task.status);
            return `<tr class="dashboard-task-row" data-task-id="${task.id}" role="button" tabindex="0" aria-label="ดูรายละเอียดงาน ${escapeHtml(task.title)}"><td class="ps-4 fw-semibold">#T-${String(task.id).padStart(5, '0')}</td><td>${thaiDate(task.created_at)}</td><td><button class="btn btn-link p-0 text-start fw-semibold text-decoration-none dashboard-task-detail" data-task-id="${task.id}">${escapeHtml(task.title)}</button></td><td>${escapeHtml(task.department)}</td><td><span class="badge rounded-pill ${className}">${label}</span></td><td class="pe-4">${escapeHtml(task.created_by_name)}</td></tr>`;
        }).join('') || '<tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีข้อมูลงาน</td></tr>';
        card.innerHTML = `<div class="card-header d-flex align-items-center justify-content-between py-3 px-4"><div><h2 class="h5 mb-1 fw-bold page-heading">งานล่าสุด</h2><p class="text-muted small mb-0">ข้อมูลล่าสุดจากฐานข้อมูล</p></div><a class="btn btn-primary btn-sm px-3" href="../report/"><i class="bi bi-list-check me-1"></i>ดูทั้งหมด</a></div><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th class="ps-4 py-3">รหัสงาน</th><th>วันที่สร้าง</th><th>หัวข้องาน</th><th>แผนก</th><th>สถานะ</th><th class="pe-4">ผู้สร้าง</th></tr></thead><tbody>${rows}</tbody></table></div>${paginationMarkup}`;

        const latestTaskButtons = card.querySelectorAll('.dashboard-task-detail');
        const latestHeaders = card.querySelectorAll('thead th');
        const latestHeader = latestHeaders[0];
        const createdByHeader = latestHeaders[5];
        if (latestHeader) latestHeader.textContent = 'ลำดับ';
        if (latestHeaders[2]) latestHeaders[2].textContent = 'ชื่องาน';
        if (latestHeaders[3]) latestHeaders[3].textContent = 'ทีม';
        if (createdByHeader) createdByHeader.textContent = 'ผู้รับผิดชอบ';
        latestTaskButtons.forEach((button, index) => {
            const row = button.closest('tr');
            if (row?.cells[0]) row.cells[0].textContent = String(((dashboardRecentPagination.page - 1) * dashboardRecentPagination.perPage) + index + 1);
        });

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
            modal.innerHTML = `<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5">รายละเอียดงาน: ${escapeHtml(task.title)}</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-6"><strong>ชื่องาน:</strong> ${escapeHtml(task.title)}</div><div class="col-md-3"><strong>แผนก:</strong> ${escapeHtml(task.department)}</div><div class="col-md-3"><strong>สถานะ:</strong> <span class="badge ${className}">${label}</span></div><div class="col-md-6"><strong>ประเภทปัญหา:</strong> ${escapeHtml(task.category)}</div><div class="col-md-6"><strong>สถานที่:</strong> ${escapeHtml(task.location)}</div><div class="col-12"><strong>รายละเอียดปัญหา:</strong><div class="mt-1">${escapeHtml(task.problem).replace(/\n/g, '<br>')}</div></div><div class="col-12"><strong>วิธีแก้ไข:</strong><div class="mt-1">${escapeHtml(task.solution).replace(/\n/g, '<br>')}</div></div><div class="col-md-6"><strong>เวลาเริ่ม:</strong> ${thaiDate(task.start_time)}</div><div class="col-md-6"><strong>เวลาสิ้นสุด:</strong> ${thaiDate(task.finish_time)}</div><div class="col-12"><strong>หมายเหตุ:</strong> ${escapeHtml(task.remark).replace(/\n/g, '<br>')}</div><div class="col-md-6"><strong>ผู้สร้าง:</strong> ${escapeHtml(task.created_by_name)}</div><div class="col-md-6"><strong>สร้างเมื่อ:</strong> ${thaiDate(task.created_at)}</div><div class="col-md-6"><strong>อัปเดตเมื่อ:</strong> ${thaiDate(task.updated_at)}</div></div></div><div class="modal-footer"><a class="btn btn-primary" href="../task_input/edit.php?id=${task.id}">Edit Task</a><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button></div></div></div>`;
            modal.querySelectorAll('.modal-body strong').forEach((label) => {
                if (label.textContent.trim() === 'แผนก:') label.textContent = 'ทีม:';
                if (label.textContent.trim() === 'ผู้สร้าง:') label.textContent = 'ผู้รับผิดชอบ:';
            });
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
    // Dashboard keeps its legacy sidebar markup, so point its Config items to the shared Config page.
    document.querySelectorAll('.sidebar .nav-link').forEach((link) => {
        if (link.textContent.trim() === 'Config') link.href = '../config/';
    });

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
</script>
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>
