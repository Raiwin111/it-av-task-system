<?php
// Edit an existing task while preserving the current role permissions.
require_once __DIR__ . "/../auth/auth_check.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/constants.php";

function parse_edit_thai_datetime(string $value): ?string
{
    $value = trim($value);
    if ($value === "") return null;
    if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2})(?:\s*น\.)?$/u', $value, $matches)) return null;

    [, $day, $month, $year, $hour, $minute] = $matches;
    $gregorian_year = (int) $year - 543;
    if (!checkdate((int) $month, (int) $day, $gregorian_year) || (int) $hour > 23 || (int) $minute > 59) return null;
    return sprintf("%04d-%02d-%02d %02d:%02d:00", $gregorian_year, $month, $day, $hour, $minute);
}

function format_edit_thai_datetime(string $value): string
{
    $time = strtotime($value);
    return date("d/m/", $time) . (date("Y", $time) + 543) . " " . date("H:i", $time) . " น.";
}

$task_id = (int) ($_POST["task_id"] ?? $_GET["id"] ?? 0);
$current_user_id = (int) ($_SESSION["user_id"] ?? 0);
$current_role = strtoupper($_SESSION["role"] ?? "USER");
$can_manage_all_tasks = in_array($current_role, ["SUPER", "ADMIN"], true);
$can_select_department = $can_manage_all_tasks;
$form_error = "";

if ($task_id <= 0) {
    header("Location: ../report/");
    exit;
}

$task_stmt = $conn->prepare("SELECT * FROM tasks WHERE id = ? AND is_deleted = 0 LIMIT 1");
$task_stmt->bind_param("i", $task_id);
$task_stmt->execute();
$task = $task_stmt->get_result()->fetch_assoc();
$task_stmt->close();

if (!$task) {
    http_response_code(404);
    exit("ไม่พบข้อมูลงาน");
}

if (!$can_manage_all_tasks && (int) $task["created_by"] !== $current_user_id) {
    header("Location: ../report/?error=forbidden");
    exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    $title = trim($_POST["title"] ?? "");
    $category = $_POST["category"] ?? "";
    $department = $can_select_department ? ($_POST["department"] ?? "") : $task["department"];
    $location = trim($_POST["location"] ?? "");
    $problem = trim($_POST["problem"] ?? "");
    $solution = trim($_POST["solution"] ?? "");
    $status = $_POST["status"] ?? "";
    $start_time = parse_edit_thai_datetime($_POST["start_time"] ?? "");
    $finish_time = parse_edit_thai_datetime($_POST["finish_time"] ?? "");
    $remark = trim($_POST["remark"] ?? "");

    if ($title === "" || $problem === "" || !array_key_exists($category, $problem_category_options) || !array_key_exists($status, $task_status_options) || !in_array($department, $departments, true) || !$start_time || !$finish_time) {
        $form_error = "กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วนและตรวจสอบวันเวลา";
    } else {
        // Update the timestamp whenever a task edit is saved.
        $update_stmt = $conn->prepare("UPDATE tasks SET title = ?, category = ?, department = ?, location = ?, problem = ?, solution = ?, status = ?, start_time = ?, finish_time = ?, remark = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $update_stmt->bind_param("ssssssssssi", $title, $category, $department, $location, $problem, $solution, $status, $start_time, $finish_time, $remark, $task_id);

        if ($update_stmt->execute()) {
            $update_stmt->close();
            header("Location: edit.php?id=" . $task_id . "&updated=1");
            exit;
        }

        $update_stmt->close();
        $form_error = "ไม่สามารถบันทึกการแก้ไขได้ กรุณาลองอีกครั้ง";
    }

    $task = array_merge($task, ["title" => $title, "category" => $category, "department" => $department, "location" => $location, "problem" => $problem, "solution" => $solution, "status" => $status, "start_time" => $start_time ?: $task["start_time"], "finish_time" => $finish_time ?: $task["finish_time"], "remark" => $remark]);
}

$app_page_title = "แก้ไขงาน | IT / AV Task Management System";
$active_nav = "task_input";
require_once __DIR__ . "/../includes/app_header.php";
?>
<div class="app-shell d-flex">
    <?php require_once __DIR__ . "/../includes/app_sidebar.php"; ?>
    <main class="main-content flex-grow-1 p-4 p-lg-5">
        <div class="d-flex align-items-start justify-content-between gap-3 mb-4"><div><h1 class="page-heading h3 fw-bold mb-1">แก้ไขงาน</h1><p class="page-subtitle mb-0">แก้ไขข้อมูลของงาน #T-<?php echo str_pad((string) $task_id, 5, "0", STR_PAD_LEFT); ?></p></div><a class="btn btn-outline-secondary" href="../report/"><i class="bi bi-arrow-left me-1"></i>กลับไปรายงาน</a></div>
        <?php if (isset($_GET["updated"])): ?><div class="alert alert-success">บันทึกการแก้ไขเรียบร้อยแล้ว</div><?php endif; ?>
        <?php if ($form_error !== ""): ?><div class="alert alert-danger"><?php echo htmlspecialchars($form_error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>

        <form method="post" action=""><input type="hidden" name="task_id" value="<?php echo $task_id; ?>">
            <section class="card form-card mb-4"><div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-pencil-square"></i></span><h2 class="section-title mb-0">ข้อมูลการแจ้งงาน</h2></div><div class="card-body p-4"><div class="row g-4"><div class="col-12"><label for="title" class="form-label">หัวข้องาน <span class="required-mark">*</span></label><input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($task["title"], ENT_QUOTES, "UTF-8"); ?>" required></div><div class="col-md-6"><label for="category" class="form-label">ประเภทปัญหา <span class="required-mark">*</span></label><select class="form-select" id="category" name="category" required><?php foreach ($problem_category_options as $value => $label): ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, "UTF-8"); ?>"<?php echo $task["category"] === $value ? " selected" : ""; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label for="department" class="form-label">แผนก</label><?php if ($can_select_department): ?><select class="form-select" id="department" name="department"><?php foreach ($departments as $department_option): ?><option value="<?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?>"<?php echo $task["department"] === $department_option ? " selected" : ""; ?>><?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select><?php else: ?><input type="text" class="form-control bg-light" id="department" value="<?php echo htmlspecialchars($task["department"], ENT_QUOTES, "UTF-8"); ?>" readonly><?php endif; ?></div><div class="col-12"><label for="location" class="form-label">สถานที่</label><input type="text" class="form-control" id="location" name="location" value="<?php echo htmlspecialchars($task["location"], ENT_QUOTES, "UTF-8"); ?>"></div></div></div></section>

            <section class="card form-card mb-4"><div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-file-earmark-text"></i></span><h2 class="section-title mb-0">รายละเอียดและการแก้ไข</h2></div><div class="card-body p-4"><div class="row g-4"><div class="col-12"><label for="problem" class="form-label">รายละเอียดปัญหา <span class="required-mark">*</span></label><textarea class="form-control" id="problem" name="problem" rows="5" required><?php echo htmlspecialchars($task["problem"], ENT_QUOTES, "UTF-8"); ?></textarea></div><div class="col-12"><label for="solution" class="form-label">วิธีแก้ไข / การดำเนินการ</label><textarea class="form-control" id="solution" name="solution" rows="5"><?php echo htmlspecialchars($task["solution"], ENT_QUOTES, "UTF-8"); ?></textarea></div><div class="col-12"><label for="remark" class="form-label">หมายเหตุ</label><textarea class="form-control" id="remark" name="remark" rows="4"><?php echo htmlspecialchars($task["remark"], ENT_QUOTES, "UTF-8"); ?></textarea></div></div></div></section>

            <section class="card form-card mb-4"><div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-clock-history"></i></span><h2 class="section-title mb-0">เวลาและสถานะงาน</h2></div><div class="card-body p-4"><div class="row g-4"><div class="col-md-4"><label for="status" class="form-label">สถานะงาน</label><select class="form-select" id="status" name="status"><?php foreach ($task_status_options as $value => $label): ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, "UTF-8"); ?>"<?php echo $task["status"] === $value ? " selected" : ""; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label for="start_time" class="form-label">เวลาที่เกิดเหตุ</label><input type="text" class="form-control datetime-picker" id="start_time" name="start_time" value="<?php echo htmlspecialchars(format_edit_thai_datetime($task["start_time"]), ENT_QUOTES, "UTF-8"); ?>" required></div><div class="col-md-4"><label for="finish_time" class="form-label">เวลาที่เสร็จ</label><input type="text" class="form-control datetime-picker" id="finish_time" name="finish_time" value="<?php echo htmlspecialchars(format_edit_thai_datetime($task["finish_time"]), ENT_QUOTES, "UTF-8"); ?>" required></div></div></div></section>

            <div class="form-actions d-flex flex-column flex-sm-row justify-content-end gap-2 pt-4"><a class="btn btn-outline-secondary px-4" href="../report/">ยกเลิก</a><button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i>บันทึกการแก้ไข</button></div>
        </form>
    </main>
</div>
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>