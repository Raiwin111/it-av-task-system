<?php
require_once __DIR__ . "/../auth/auth_check.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/constants.php";

// Always request fresh task data after users return from Task Input.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$app_page_title = "รายงาน / Report | IT / AV Task Management System";
$role = strtoupper($_SESSION["role"] ?? "USER");
$user_id = (int) ($_SESSION["user_id"] ?? 0);
$active_nav = "report";

// Soft delete keeps the row in MySQL while hiding it from active task lists.
if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST" && ($_POST["action"] ?? "") === "delete") {
    $delete_id = (int) ($_POST["task_id"] ?? 0);

    $owner_stmt = $conn->prepare("SELECT created_by, department FROM tasks WHERE id = ? AND is_deleted = 0 LIMIT 1");
    $owner_stmt->bind_param("i", $delete_id);
    $owner_stmt->execute();
    $delete_task = $owner_stmt->get_result()->fetch_assoc();
    $owner_stmt->close();

    $can_delete = $delete_task && ($role !== "USER" || (string) $delete_task["department"] === (string) ($_SESSION["department"] ?? ""));

    if (!$can_delete) {
        header("Location: index.php?error=forbidden");
        exit;
    }

    $delete_stmt = $conn->prepare("UPDATE tasks SET is_deleted = 1 WHERE id = ?");
    $delete_stmt->bind_param("i", $delete_id);
    $delete_stmt->execute();
    $delete_stmt->close();

    header("Location: index.php?deleted=1");
    exit;
}
// All roles can view every task. Ownership is checked only for Edit/Delete actions.
$sql = "SELECT t.*, COALESCE(NULLIF(t.responsible_name, ''), u.department, '-') AS created_by_name FROM tasks t LEFT JOIN users u ON u.id = t.created_by WHERE t.is_deleted = 0 ORDER BY t.created_at DESC, t.id DESC";
$stmt = $conn->prepare($sql);$stmt->execute();
$tasks = $stmt->get_result();
$task_rows = $tasks->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$counts = ["total" => 0, "pending" => 0, "in_progress" => 0, "completed" => 0, "cancelled" => 0];
foreach ($task_rows as $task) { $key = strtolower(str_replace(" ", "_", $task["status"])); $key = ["รอดำเนินการ"=>"pending","กำลังดำเนินการ"=>"in_progress","เสร็จสิ้น"=>"completed","ยกเลิก"=>"cancelled"][$key] ?? $key; $counts["total"]++; if (isset($counts[$key])) $counts[$key]++; }
$edit_id = isset($_GET["edit"]) ? (int) $_GET["edit"] : 0;
$selected_task = null;
foreach ($task_rows as $task) if ((int) $task["id"] === $edit_id) $selected_task = $task;
function can_edit_task(array $task, string $role, int $user_id): bool {
    // USER accounts can edit every task assigned to their own team.
    // SUPER and ADMIN accounts retain cross-team access.
    return $role !== "USER" || (string) $task["department"] === (string) ($_SESSION["department"] ?? "");
}
function can_delete_task(array $task, string $role, int $user_id): bool {
    // USER accounts can delete tasks within their own team.
    return $role !== "USER" || (string) $task["department"] === (string) ($_SESSION["department"] ?? "");
}
function thai_date_time(string $value): string { $time = strtotime($value); return date("d/m/", $time) . (date("Y", $time) + 543) . " " . date("H:i", $time) . " น."; }
function status_meta(string $value): array { $key = strtolower(str_replace(" ", "_", $value)); $key = ["รอดำเนินการ"=>"pending","กำลังดำเนินการ"=>"in_progress","เสร็จสิ้น"=>"completed","ยกเลิก"=>"cancelled"][$key] ?? $key; return [["pending"=>"รอดำเนินการ","in_progress"=>"กำลังดำเนินการ","completed"=>"เสร็จสิ้น","cancelled"=>"ยกเลิก"][$key] ?? $value, ["pending"=>"status-pending","in_progress"=>"status-progress","completed"=>"status-completed","cancelled"=>"status-cancelled"][$key] ?? "status-pending"]; }
require_once __DIR__ . "/../includes/app_header.php";
?>
<div class="app-shell d-flex"><?php require_once __DIR__ . "/../includes/app_sidebar.php"; ?><main class="report-page main-content flex-grow-1 p-4 p-lg-5"><?php if (isset($_GET["deleted"])): ?><div class="alert alert-success">ลบงานเรียบร้อยแล้ว</div><?php endif; ?><?php if (($_GET["error"] ?? "") === "forbidden"): ?><div class="alert alert-danger">คุณไม่มีสิทธิ์ดำเนินการกับงานนี้</div><?php endif; ?><div class="mb-4"><h1 class="page-heading h3 fw-bold mb-1">รายงาน / Report</h1><p class="page-subtitle mb-0">ข้อมูลรายการงานจากฐานข้อมูล</p></div>
<section class="row g-4 mb-4"><div class="col-sm-6 col-xl-3"><article class="card form-card h-100"><div class="card-body d-flex align-items-center"><span class="report-kpi-icon report-kpi-total d-inline-flex align-items-center justify-content-center me-3"><i class="bi bi-card-checklist"></i></span><div><div class="text-muted small fw-semibold">งานทั้งหมด</div><div class="page-heading h3 fw-bold mb-0"><?php echo $counts["total"]; ?></div></div></div></article></div><div class="col-sm-6 col-xl-3"><article class="card form-card h-100"><div class="card-body d-flex align-items-center"><span class="report-kpi-icon report-kpi-pending d-inline-flex align-items-center justify-content-center me-3"><i class="bi bi-hourglass-split"></i></span><div><div class="text-muted small fw-semibold">รอดำเนินการ</div><div class="page-heading h3 fw-bold mb-0"><?php echo $counts["pending"]; ?></div></div></div></article></div><div class="col-sm-6 col-xl-3"><article class="card form-card h-100"><div class="card-body d-flex align-items-center"><span class="report-kpi-icon report-kpi-progress d-inline-flex align-items-center justify-content-center me-3"><i class="bi bi-tools"></i></span><div><div class="text-muted small fw-semibold">กำลังดำเนินการ</div><div class="page-heading h3 fw-bold mb-0"><?php echo $counts["in_progress"]; ?></div></div></div></article></div><div class="col-sm-6 col-xl-3"><article class="card form-card h-100"><div class="card-body d-flex align-items-center"><span class="report-kpi-icon report-kpi-completed d-inline-flex align-items-center justify-content-center me-3"><i class="bi bi-check-circle-fill"></i></span><div><div class="text-muted small fw-semibold">เสร็จสิ้น</div><div class="page-heading h3 fw-bold mb-0"><?php echo $counts["completed"]; ?></div></div></div></article></div></section>
<section class="card form-card report-list-card"><div class="card-header report-list-header d-flex align-items-center justify-content-between gap-3"><h2 class="section-title report-title d-flex align-items-center gap-2 mb-0"><span class="section-icon report-title-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-table"></i></span><span>รายการรายงาน</span></h2><div class="report-header-side d-flex flex-column align-items-end gap-1"><div class="report-header-actions d-flex align-items-center gap-2"><div class="input-group input-group-sm report-search-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input type="search" class="form-control report-search" id="reportSearchInput" placeholder="ค้นหาหัวข้องาน" aria-label="ค้นหาหัวข้องาน"></div><button class="filter-toggle btn btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#reportFilterModal" aria-label="เปิดตัวกรอง" title="ตัวกรอง"><i class="bi bi-sliders2"></i></button></div><div class="d-flex align-items-center gap-2 report-page-size"><label class="small text-muted mb-0" for="reportRowsPerPage">แสดง</label><select class="form-select form-select-sm report-rows-select" id="reportRowsPerPage"><option value="10" selected>10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></div><span class="text-muted small report-record-count" id="reportFilteredCount">แสดง 0-0 จากทั้งหมด <?php echo $counts["total"]; ?> รายการ</span></div></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th class="ps-4 py-3">รหัส</th><th>วันที่สร้าง</th><th>หัวข้องาน</th><th>แผนก</th><th>ประเภทปัญหา</th><th>สถานะ</th><th>ผู้สร้าง</th><th class="pe-4 text-end">การจัดการ</th></tr></thead><tbody id="reportTableBody"><?php if (!$task_rows): ?><tr><td colspan="8" class="text-center text-muted py-4">ยังไม่มีข้อมูลงาน</td></tr><?php endif; ?><?php foreach ($task_rows as $task): ?><?php [$label,$class]=status_meta($task["status"]); ?><?php $can_edit = can_edit_task($task, $role, $user_id); ?><?php $can_delete = can_delete_task($task, $role, $user_id); ?><tr data-search="<?php echo htmlspecialchars(implode(" ", ["T-" . str_pad((string) $task["id"], 5, "0", STR_PAD_LEFT), $task["id"], $task["title"], $task["department"], $task["category"], $problem_category_options[$task["category"]] ?? "", $task["location"], $task["problem"], $task["solution"], $task["created_by_name"]]), ENT_QUOTES, "UTF-8"); ?>" data-department="<?php echo htmlspecialchars($task["department"], ENT_QUOTES, "UTF-8"); ?>" data-status="<?php echo htmlspecialchars($task["status"], ENT_QUOTES, "UTF-8"); ?>" data-category="<?php echo htmlspecialchars($task["category"], ENT_QUOTES, "UTF-8"); ?>" data-created-date="<?php echo htmlspecialchars(substr($task["created_at"], 0, 10), ENT_QUOTES, "UTF-8"); ?>"><td class="ps-4 fw-semibold">#T-<?php echo str_pad((string)$task["id"],5,"0",STR_PAD_LEFT); ?></td><td><?php echo thai_date_time($task["created_at"]); ?></td><td><?php echo htmlspecialchars($task["title"],ENT_QUOTES,"UTF-8"); ?></td><td><?php echo htmlspecialchars($task["department"],ENT_QUOTES,"UTF-8"); ?></td><td><?php echo htmlspecialchars($problem_category_options[$task["category"]] ?? $task["category"],ENT_QUOTES,"UTF-8"); ?></td><td><span class="badge rounded-pill <?php echo $class; ?>"><?php echo $label; ?></span></td><td><?php echo htmlspecialchars($task["created_by_name"],ENT_QUOTES,"UTF-8"); ?></td><td class="pe-4 text-end"><button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#taskModal<?php echo $task["id"]; ?>">ดูรายละเอียด</button> <?php if ($can_edit): ?><a class="btn btn-sm btn-outline-secondary" href="../task_input/edit.php?id=<?php echo $task["id"]; ?>">แก้ไข</a><?php endif; ?><?php if ($can_delete): ?> <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#deleteTaskModal<?php echo $task["id"]; ?>">ลบ</button><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><div class="d-flex justify-content-center p-3 border-top"><nav aria-label="การแบ่งหน้ารายงาน"><ul class="pagination pagination-sm mb-0" id="reportPagination"></ul></nav></div></section>
<div class="modal fade" id="reportFilterModal" tabindex="-1" aria-labelledby="reportFilterModalLabel" aria-hidden="true" data-bs-backdrop="true" data-bs-keyboard="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="reportFilterModalLabel"><i class="bi bi-sliders2 me-2"></i>ตัวกรองรายงาน</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-6"><label class="form-label" for="reportStartDate">วันที่เริ่มต้น</label><input type="text" class="form-control date-picker" id="reportStartDate" placeholder="19/07/2569"></div><div class="col-md-6"><label class="form-label" for="reportEndDate">วันที่สิ้นสุด</label><input type="text" class="form-control date-picker" id="reportEndDate" placeholder="19/07/2569"></div><div class="col-md-6"><label class="form-label" for="reportDepartmentFilter">แผนก</label><select class="form-select" id="reportDepartmentFilter"><option value="">ทั้งหมด</option><?php foreach ($departments as $item): ?><option value="<?php echo htmlspecialchars($item, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($item, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label" for="reportStatusFilter">สถานะ</label><select class="form-select" id="reportStatusFilter"><option value="">ทั้งหมด</option><?php foreach ($task_status_options as $value => $item): ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($item, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div><div class="col-12"><label class="form-label" for="reportCategoryFilter">ประเภทปัญหา</label><select class="form-select" id="reportCategoryFilter"><option value="">ทั้งหมด</option><?php foreach ($problem_category_options as $value => $item): ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($item, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary me-auto" id="resetReportFilters">ล้างตัวกรอง</button><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button></div></div></div></div><?php foreach ($task_rows as $task): ?><?php [$label,$class]=status_meta($task["status"]); ?><?php $can_edit = can_edit_task($task, $role, $user_id); ?><?php $can_delete = can_delete_task($task, $role, $user_id); ?><div class="modal fade" id="taskModal<?php echo $task["id"]; ?>" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5">รายละเอียดงาน #T-<?php echo str_pad((string)$task["id"],5,"0",STR_PAD_LEFT); ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-6"><strong>หัวข้องาน:</strong> <?php echo htmlspecialchars($task["title"],ENT_QUOTES,"UTF-8"); ?></div><div class="col-md-3"><strong>แผนก:</strong> <?php echo htmlspecialchars($task["department"],ENT_QUOTES,"UTF-8"); ?></div><div class="col-md-3"><strong>สถานะ:</strong> <span class="badge <?php echo $class; ?>"><?php echo $label; ?></span></div><div class="col-md-6"><strong>ประเภทปัญหา:</strong> <?php echo htmlspecialchars($problem_category_options[$task["category"]] ?? $task["category"],ENT_QUOTES,"UTF-8"); ?></div><div class="col-md-6"><strong>สถานที่:</strong> <?php echo htmlspecialchars($task["location"],ENT_QUOTES,"UTF-8"); ?></div><div class="col-12"><strong>รายละเอียดปัญหา:</strong><div class="mt-1"><?php echo nl2br(htmlspecialchars($task["problem"],ENT_QUOTES,"UTF-8")); ?></div></div><div class="col-12"><strong>วิธีแก้ไข:</strong><div class="mt-1"><?php echo nl2br(htmlspecialchars($task["solution"],ENT_QUOTES,"UTF-8")); ?></div></div><div class="col-md-6"><strong>เวลาเริ่ม:</strong> <?php echo thai_date_time($task["start_time"]); ?></div><div class="col-md-6"><strong>เวลาสิ้นสุด:</strong> <?php echo thai_date_time($task["finish_time"]); ?></div><div class="col-12"><strong>หมายเหตุ:</strong> <?php echo nl2br(htmlspecialchars($task["remark"],ENT_QUOTES,"UTF-8")); ?></div><div class="col-md-6"><strong>ผู้สร้าง:</strong> <?php echo htmlspecialchars($task["created_by_name"],ENT_QUOTES,"UTF-8"); ?></div><div class="col-md-6"><strong>สร้างเมื่อ:</strong> <?php echo thai_date_time($task["created_at"]); ?></div><div class="col-md-6"><strong>อัปเดตเมื่อ:</strong> <?php echo thai_date_time($task["updated_at"]); ?></div></div></div><div class="modal-footer"><?php if ($can_edit): ?><a class="btn btn-primary" href="../task_input/edit.php?id=<?php echo $task["id"]; ?>">Edit Task</a><?php endif; ?><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button></div></div></div></div><?php endforeach; ?>
<?php foreach ($task_rows as $task): ?><?php if (can_delete_task($task, $role, $user_id)): ?>
<div class="modal fade" id="deleteTaskModal<?php echo $task["id"]; ?>" tabindex="-1" aria-labelledby="deleteTaskModalLabel<?php echo $task["id"]; ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title fs-5" id="deleteTaskModalLabel<?php echo $task["id"]; ?>">ยืนยันการลบงาน</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">คุณต้องการลบงานนี้หรือไม่ ?</div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button><form method="post" action="" class="m-0"><input type="hidden" name="action" value="delete"><input type="hidden" name="task_id" value="<?php echo $task["id"]; ?>"><button type="submit" class="btn btn-danger">ลบงาน</button></form></div>
    </div></div>
</div>
<?php endif; ?><?php endforeach; ?></main></div>
<?php if ($selected_task): ?><script>window.addEventListener('load',()=>new bootstrap.Modal(document.getElementById('taskModal<?php echo $selected_task["id"]; ?>')).show());</script><?php endif; ?>
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
    .report-filter-card .collapsing { transition: height .24s ease; }
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
    #reportPagination .page-link { min-width: 34px; text-align: center; }</style>
<style>
    /* Professional task-detail modal treatment. */
    [id^="taskModal"] .modal-dialog { max-width: 900px; }
    [id^="taskModal"] .modal-content { overflow: hidden; border: 1px solid #d7e3ef; border-radius: 1rem; box-shadow: 0 18px 48px rgba(15, 35, 57, .22); }
    [id^="taskModal"] .modal-header { padding: 1.05rem 1.35rem; color: #153b63; border-bottom: 1px solid #cfe0f0; background: linear-gradient(135deg, #eef6ff, #f8fbff); }
    [id^="taskModal"] .modal-title { font-weight: 700; }
    [id^="taskModal"] .modal-body { padding: 1.35rem; background: #fbfdff; }
    [id^="taskModal"] .modal-body .row > div { min-height: 100%; padding: .85rem 1rem; color: #334e68; border: 1px solid #dce8f3; border-radius: .7rem; background: #fff; box-shadow: 0 2px 8px rgba(22, 65, 104, .045); }
    [id^="taskModal"] .modal-body strong { display: block; margin-bottom: .35rem; color: #1b4f7f; font-size: .84rem; font-weight: 700; }
    [id^="taskModal"] .modal-body .mt-1 { margin-top: 0 !important; line-height: 1.6; color: #405970; }
    [id^="taskModal"] .modal-footer { padding: .9rem 1.35rem; border-top: 1px solid #dce8f3; background: #f7faff; }
    [id^="taskModal"] .badge { padding: .42rem .68rem; box-shadow: none; }
    @media (max-width: 575.98px) { [id^="taskModal"] .modal-body { padding: 1rem; } [id^="taskModal"] .modal-body .row > div { padding: .75rem .85rem; } }
</style>
<script>
    // Optional task-detail fields are shown as a dash instead of an empty area.
    document.querySelectorAll('[id^="taskModal"] .modal-body .mt-1').forEach((field) => {
        if (!field.textContent.trim()) field.textContent = "-";
    });
    document.querySelectorAll('[id^="taskModal"] .modal-body strong').forEach((label) => {
        if (label.textContent.trim() === "ประเภทปัญหา:") label.textContent = "ประเภทปัญหา:";
        if (label.textContent.trim() === "รายละเอียดปัญหา:") label.textContent = "ปัญหาที่พบ:";
        if (label.textContent.trim() === "วิธีแก้ไข:") label.textContent = "วิธีแก้ไขปัญหา:";
    });

    document.querySelectorAll('[id^="taskModal"] .modal-body strong').forEach((label) => {
        if (label.textContent.trim() === "ผู้สร้าง:") label.textContent = "ผู้รับผิดชอบ:";
    });

    const taskWorkDetails = <?php echo json_encode($task_rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    taskWorkDetails.forEach((task) => {
        const modal = document.getElementById(`taskModal${task.id}`);
        const modalTitle = modal?.querySelector(".modal-title");
        if (modalTitle) modalTitle.textContent = `รายละเอียดงาน: ${task.title}`;

        const detailRow = modal?.querySelector(".modal-body .row");
        const insertBefore = detailRow?.children[5];
        if (!detailRow || !insertBefore) return;

        const locationCell = detailRow.children[4];
        if (locationCell && !String(task.location || "").trim()) {
            const locationLabel = locationCell.querySelector("strong");
            locationCell.replaceChildren(locationLabel);
            locationCell.append(" -");
        }

        detailRow.querySelectorAll("strong").forEach((label) => {
            if (label.textContent.trim() === "หัวข้องาน:") label.textContent = "ชื่องาน:";
        });

        const createDetailField = (label, value) => {
            const field = document.createElement("div");
            field.className = "col-12";
            const heading = document.createElement("strong");
            heading.textContent = `${label}:`;
            const content = document.createElement("div");
            content.className = "mt-1";
            content.style.whiteSpace = "pre-wrap";
            content.textContent = String(value || "").trim() || "-";
            field.append(heading, content);
            return field;
        };
        insertBefore.before(
            createDetailField("รายละเอียดงาน", task.work_description),
            createDetailField("การดำเนินงาน", task.work_action)
        );
    });

    // Client-side filtering and pagination share the same active rows for future server-side migration.
    (() => {
        const searchInput = document.getElementById("reportSearchInput");
        const tableBody = document.getElementById("reportTableBody");
        const resultCount = document.getElementById("reportFilteredCount");
        const departmentFilter = document.getElementById("reportDepartmentFilter");
        const statusFilter = document.getElementById("reportStatusFilter");
        const categoryFilter = document.getElementById("reportCategoryFilter");
        const startDateFilter = document.getElementById("reportStartDate");
        const endDateFilter = document.getElementById("reportEndDate");
        const resetButton = document.getElementById("resetReportFilters");
        const rowsPerPageSelect = document.getElementById("reportRowsPerPage");
        const pagination = document.getElementById("reportPagination");

        if (!searchInput || !tableBody || !resultCount || !rowsPerPageSelect || !pagination) return;

        const taskRows = Array.from(tableBody.querySelectorAll("tr[data-search]"));
        const emptyRow = tableBody.querySelector("tr:not([data-search])");
        let currentPage = 1;

        // Keep the report table focused on work information: category is available in filters/details only.
        const reportHeaders = tableBody.closest("table")?.querySelectorAll("thead th");
        if (reportHeaders) {
            reportHeaders[0].textContent = "ลำดับ";
            reportHeaders[2].textContent = "ชื่องาน";
            reportHeaders[3].textContent = "ทีม";
            reportHeaders[6].textContent = "ผู้รับผิดชอบ";
            reportHeaders[4]?.remove();
        }
        taskRows.forEach((row) => row.cells[4]?.remove());
        if (emptyRow?.cells[0]) emptyRow.cells[0].colSpan = 7;

        const toIsoDate = (value) => {
            const text = value.trim();
            if (!text) return "";

            const thaiDate = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/);
            if (thaiDate) {
                const day = thaiDate[1].padStart(2, "0");
                const month = thaiDate[2].padStart(2, "0");
                const year = Number(thaiDate[3]) > 2400 ? Number(thaiDate[3]) - 543 : Number(thaiDate[3]);
                return String(year).padStart(4, "0") + "-" + month + "-" + day;
            }

            return /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : "";
        };

        const getFilteredRows = () => {
            const keyword = searchInput.value.trim().toLocaleLowerCase();
            const department = departmentFilter ? departmentFilter.value : "";
            const status = statusFilter ? statusFilter.value : "";
            const category = categoryFilter ? categoryFilter.value : "";
            const startDate = startDateFilter ? toIsoDate(startDateFilter.value) : "";
            const endDate = endDateFilter ? toIsoDate(endDateFilter.value) : "";

            return taskRows.filter((row) => {
                const searchableText = (row.dataset.search || "").toLocaleLowerCase();
                const createdDate = row.dataset.createdDate || "";
                return searchableText.includes(keyword)
                    && (!department || row.dataset.department === department)
                    && (!status || row.dataset.status === status)
                    && (!category || row.dataset.category === category)
                    && (!startDate || createdDate >= startDate)
                    && (!endDate || createdDate <= endDate);
            });
        };

        const addPageButton = (label, page, disabled, active) => {
            const item = document.createElement("li");
            item.className = "page-item" + (disabled ? " disabled" : "") + (active ? " active" : "");
            const button = document.createElement("button");
            button.type = "button";
            button.className = "page-link";
            button.textContent = label;
            button.disabled = disabled;
            button.addEventListener("click", () => {
                currentPage = page;
                render();
            });
            item.appendChild(button);
            pagination.appendChild(item);
        };

        const addEllipsis = () => {
            const item = document.createElement("li");
            item.className = "page-item disabled";
            item.innerHTML = '<span class="page-link">…</span>';
            pagination.appendChild(item);
        };

        const renderPagination = (totalPages) => {
            pagination.innerHTML = "";
            addPageButton("<< Previous", currentPage - 1, currentPage === 1 || totalPages === 0, false);

            const pageNumbers = [];
            if (totalPages <= 5) {
                for (let page = 1; page <= totalPages; page++) pageNumbers.push(page);
            } else {
                pageNumbers.push(1);
                const start = Math.max(2, currentPage - 1);
                const end = Math.min(totalPages - 1, currentPage + 1);
                if (start > 2) pageNumbers.push(0);
                for (let page = start; page <= end; page++) pageNumbers.push(page);
                if (end < totalPages - 1) pageNumbers.push(0);
                pageNumbers.push(totalPages);
            }

            pageNumbers.forEach((page) => page === 0 ? addEllipsis() : addPageButton(String(page), page, false, page === currentPage));
            addPageButton("Next >>", currentPage + 1, currentPage === totalPages || totalPages === 0, false);
        };

        const render = () => {
            const filteredRows = getFilteredRows();
            const rowsPerPage = Number(rowsPerPageSelect.value);
            const total = filteredRows.length;
            const totalPages = Math.ceil(total / rowsPerPage);

            if (currentPage > totalPages) currentPage = totalPages || 1;

            const startIndex = (currentPage - 1) * rowsPerPage;
            const endIndex = Math.min(startIndex + rowsPerPage, total);
            const visibleRows = new Set(filteredRows.slice(startIndex, endIndex));

            filteredRows.forEach((row, index) => {
                row.cells[0].textContent = String(index + 1);
            });
            taskRows.forEach((row) => row.classList.toggle("d-none", !visibleRows.has(row)));
            if (emptyRow) emptyRow.classList.toggle("d-none", taskRows.length > 0);

            resultCount.textContent = total === 0
                ? "แสดง 0-0 จากทั้งหมด 0 รายการ"
                : "แสดง " + (startIndex + 1) + "-" + endIndex + " จากทั้งหมด " + total + " รายการ";

            let noResultRow = document.getElementById("reportSearchNoResult");
            if (total === 0 && taskRows.length > 0) {
                if (!noResultRow) {
                    noResultRow = document.createElement("tr");
                    noResultRow.id = "reportSearchNoResult";
                    noResultRow.innerHTML = '<td colspan="8" class="text-center text-muted py-4">ไม่พบข้อมูลที่ค้นหา</td>';
                    tableBody.appendChild(noResultRow);
                    noResultRow.cells[0].colSpan = 7;
                }
            } else if (noResultRow) {
                noResultRow.remove();
            }

            renderPagination(totalPages);
        };

        [searchInput, departmentFilter, statusFilter, categoryFilter, startDateFilter, endDateFilter].filter(Boolean).forEach((control) => {
            control.addEventListener(control.tagName === "SELECT" ? "change" : "input", () => {
                currentPage = 1;
                render();
            });
            control.addEventListener("change", () => {
                currentPage = 1;
                render();
            });
        });

        rowsPerPageSelect.addEventListener("change", () => {
            currentPage = 1;
            render();
        });

        if (resetButton) {
            resetButton.addEventListener("click", () => {
                searchInput.value = "";
                if (departmentFilter) departmentFilter.value = "";
                if (statusFilter) statusFilter.value = "";
                if (categoryFilter) categoryFilter.value = "";
                if (startDateFilter) startDateFilter.value = "";
                if (endDateFilter) endDateFilter.value = "";
                currentPage = 1;
                render();
            });
        }

        render();
    })();
</script>
<script>
    // Keep the Report search and filter labels aligned with the work-log terminology.
    const reportSearchInputLabel = document.getElementById("reportSearchInput");
    if (reportSearchInputLabel) {
        reportSearchInputLabel.placeholder = "ค้นหาชื่องาน";
        reportSearchInputLabel.setAttribute("aria-label", "ค้นหาชื่องาน");
    }
    const reportTeamFilterLabel = document.querySelector('label[for="reportDepartmentFilter"]');
    if (reportTeamFilterLabel) reportTeamFilterLabel.textContent = "ทีม";
    const reportCancelledStatusOption = document.querySelector('#reportStatusFilter option[value="cancelled"]');
    if (reportCancelledStatusOption) reportCancelledStatusOption.remove();
</script>
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>
