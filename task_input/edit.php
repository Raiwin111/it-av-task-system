<?php
// Edit an existing task while preserving the current role permissions.
require_once __DIR__ . "/../auth/auth_check.php";
require_once __DIR__ . "/../auth/authorization.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/constants.php";
require_once __DIR__ . "/../includes/helpers.php";
require_once __DIR__ . "/../includes/task_activity.php";
require_once __DIR__ . "/image_helpers.php";

function edit_post_string(string $key): string
{
    $value = $_POST[$key] ?? "";
    return is_string($value) ? $value : "";
}

function edit_display_value(mixed $value): string
{
    $text = trim((string) $value);
    return $text === "-" ? "" : $text;
}

$task_id_value = $_POST["task_id"] ?? $_GET["id"] ?? 0;
$task_id = is_scalar($task_id_value) ? (int) $task_id_value : 0;
$current_user_id = (int) ($_SESSION["user_id"] ?? 0);
$task_problem_options_csrf = $_SESSION["task_problem_options_csrf"] ??= bin2hex(random_bytes(32));
$task_form_csrf = $_SESSION["task_form_csrf"] ??= bin2hex(random_bytes(32));
$can_manage_all_tasks_access = can_manage_all_tasks();
// Only ADMIN may reassign a task to a different team; SUPER edits within its own team.
$can_select_department = current_role() === "ADMIN";
$can_control_status = $can_manage_all_tasks_access;
$form_error = "";
$task_input_status_options = $task_status_options;

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

// Pending accounts cannot open an edit endpoint directly.
if (!is_account_approved()) {
    header("Location: ../report/?error=approval_required");
    exit;
}

// USER accounts may edit every task within their own team, regardless of creator.
if (!can_edit_task($task)) {
    header("Location: ../report/?error=forbidden");
    exit;
}

$location_options = task_location_options($conn);
if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST" && !hash_equals($task_form_csrf, edit_post_string("csrf_token"))) {
    http_response_code(419);
    $form_error = "คำขอบันทึกหมดอายุ กรุณาลองใหม่อีกครั้ง";
} elseif (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    $title = trim(edit_post_string("title"));
    $category = trim(edit_post_string("category"));
    $category = $category === "" ? "-" : $category;
    $department = $can_select_department ? edit_post_string("department") : $task["department"];
    $responsible_name = trim(edit_post_string("responsible_name"));
    $location_choice = trim(edit_post_string("location"));
    $location = $location_choice === "__other__" ? trim(edit_post_string("other_location")) : $location_choice;
    $work_description = trim(edit_post_string("work_description"));
    $work_action = trim(edit_post_string("work_action"));
    $problem = trim(edit_post_string("problem"));
    $solution = trim(edit_post_string("solution"));
    $status = edit_post_string("status");
    if (!$can_control_status) {
        $existing_status = (string) $task["status"];
        $status = $existing_status === "cancelled" ? "cancelled" : "pending";
    }
    $it_problem_missing = task_problem_is_required($department, $problem);
    $start_time = combine_thai_date_time(edit_post_string("start_date"), edit_post_string("start_work_time"));
    $finish_date_value = trim(edit_post_string("finish_date"));
    $finish_work_time_value = trim(edit_post_string("finish_work_time"));
    $finish_input_started = $finish_date_value !== "" || $finish_work_time_value !== "";
    $finish_time = combine_thai_date_time($finish_date_value, $finish_work_time_value);
    $status = task_workflow_status(
        $department,
        $solution,
        $status,
        false,
        $can_control_status,
        $work_action,
        $finish_time !== null
    );
    [$prepared_images, $image_error] = prepare_task_image_uploads();
    $remark = trim(edit_post_string("remark"));
    $location = $location === "" ? "-" : $location;
    $work_description = $work_description === "" ? "-" : $work_description;
    $work_action = $work_action === "" ? "-" : $work_action;
    $problem = $problem === "" ? "-" : $problem;
    $solution = $solution === "" ? "-" : $solution;
    $remark = $remark === "" ? "-" : $remark;

    if ($image_error !== null) {
        $form_error = $image_error;
    } elseif ($title === "" || $it_problem_missing || ($category !== "-" && !array_key_exists($category, $problem_category_options)) || !array_key_exists($status, $task_input_status_options) || !in_array($department, $departments, true) || !$start_time || ($finish_input_started && !$finish_time) || ($finish_time && $finish_time < $start_time)) {
        $form_error = $it_problem_missing
            ? "งาน IT จำเป็นต้องระบุปัญหาที่พบ"
            : "กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วนและตรวจสอบวันเวลา";
    } else {
        // Update the timestamp whenever a task edit is saved.
        $update_stmt = $conn->prepare("UPDATE tasks SET title = ?, category = ?, department = ?, responsible_name = ?, location = ?, work_description = ?, work_action = ?, problem = ?, solution = ?, status = ?, start_time = ?, finish_time = ?, remark = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $update_stmt->bind_param("sssssssssssssi", $title, $category, $department, $responsible_name, $location, $work_description, $work_action, $problem, $solution, $status, $start_time, $finish_time, $remark, $task_id);

        if ($update_stmt->execute()) {
            $update_stmt->close();
            record_task_update_activities($conn, $task_id, $task, [
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
            if (!store_task_images($conn, $task_id, $current_user_id, $prepared_images)) {
                header("Location: edit.php?id=" . $task_id . "&updated=1&image_error=1");
                exit;
            }
            if ($problem !== "-") {
                $option_stmt = $conn->prepare("INSERT IGNORE INTO team_problem_options (department, problem_text, created_by) VALUES (?, ?, ?)");
                $option_stmt->bind_param("ssi", $department, $problem, $current_user_id);
                $option_stmt->execute();
                $option_stmt->close();
            }
            if ($form_error === "") {
                header("Location: edit.php?id=" . $task_id . "&updated=1");
                exit;
            }
        } else {
            $update_stmt->close();
            $form_error = "ไม่สามารถบันทึกการแก้ไขได้ กรุณาลองอีกครั้ง";
        }
    }

    $task = array_merge($task, ["title" => $title, "category" => $category, "department" => $department, "responsible_name" => $responsible_name, "location" => $location, "work_description" => $work_description, "work_action" => $work_action, "problem" => $problem, "solution" => $solution, "status" => $status, "start_time" => $start_time ?: $task["start_time"], "finish_time" => $finish_time ?: $task["finish_time"], "remark" => $remark]);
}

$selected_location = in_array($task["location"], $location_options, true) ? $task["location"] : (($task["location"] === "" || $task["location"] === "-") ? "" : "__other__");
$other_location = $selected_location === "__other__" ? $task["location"] : "";
$task_images = [];
$image_stmt = $conn->prepare("SELECT id, file_path, original_name, mime_type, file_size, created_at FROM task_images WHERE task_id = ? ORDER BY created_at DESC, id DESC");
$image_stmt->bind_param("i", $task_id);
$image_stmt->execute();
$task_images = $image_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$image_stmt->close();

$edit_start_date = date("d/m/", strtotime($task["start_time"])) . (date("Y", strtotime($task["start_time"])) + 543);
$edit_start_time = date("H:i", strtotime($task["start_time"]));
$edit_finish_date = $task["finish_time"]
    ? date("d/m/", strtotime($task["finish_time"])) . (date("Y", strtotime($task["finish_time"])) + 543)
    : "";
$edit_finish_time = $task["finish_time"] ? date("H:i", strtotime($task["finish_time"])) : "";
$edit_created_at = date("d/m/", strtotime($task["created_at"])) . (date("Y", strtotime($task["created_at"])) + 543) . " " . date("H:i", strtotime($task["created_at"]));
$edit_updated_timestamp = strtotime((string) ($task["updated_at"] ?: $task["created_at"]));
$edit_updated_at = date("d/m/", $edit_updated_timestamp) . (date("Y", $edit_updated_timestamp) + 543) . " " . date("H:i", $edit_updated_timestamp);
$app_page_title = "แก้ไขงาน | IT / AV Task Management System";
$active_nav = "task_input";
$app_stylesheets = ["task_input.css"];
require_once __DIR__ . "/../includes/app_header.php";
?>
<div class="app-shell d-flex">
    <?php require_once __DIR__ . "/../includes/app_sidebar.php"; ?>
    <main class="main-content task-input-page flex-grow-1 p-4 p-lg-5">
        <div class="task-page-heading d-flex flex-column flex-lg-row align-items-lg-end justify-content-between gap-3 mb-4">
            <div>
                <h1 class="page-heading h3 fw-bold mb-1">แก้ไขงาน</h1>
                <p class="page-subtitle mb-0"><?php echo htmlspecialchars($task["title"], ENT_QUOTES, "UTF-8"); ?></p>
            </div>
            <a class="btn btn-outline-secondary align-self-start align-self-lg-auto" href="../report/?task_id=<?php echo $task_id; ?>"><i class="bi bi-arrow-left me-2"></i>กลับไปที่งานนี้</a>
        </div>

        <?php if (isset($_GET["updated"])): ?><div class="alert alert-success d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2" role="status"><div><i class="bi bi-check-circle-fill me-2"></i>บันทึกการแก้ไขเรียบร้อยแล้ว</div><a class="alert-link text-nowrap" href="../report/?task_id=<?php echo $task_id; ?>">เปิดดูงานนี้ <i class="bi bi-arrow-right ms-1"></i></a></div><?php endif; ?>
        <?php if (isset($_GET["image_error"])): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill me-2"></i>บันทึกการแก้ไขแล้ว แต่ไม่สามารถอัปโหลดรูปภาพได้ กรุณาลองแนบรูปใหม่อีกครั้ง</div><?php endif; ?>
        <?php if ($form_error !== ""): ?><div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-circle-fill me-2"></i><?php echo htmlspecialchars($form_error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>

        <form method="post" action="" enctype="multipart/form-data" id="taskEditForm">
            <input type="hidden" name="task_id" value="<?php echo $task_id; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($task_form_csrf, ENT_QUOTES, "UTF-8"); ?>">
            <section class="task-form-guide mb-4" aria-label="ส่วนของการแก้ไขงาน">
                <div class="task-guide-item active"><span>1</span><div><strong>ข้อมูลงาน</strong><small>ชื่อ ทีม และสถานะ</small></div></div>
                <div class="task-guide-item"><span>2</span><div><strong>รายละเอียด</strong><small>สิ่งที่ทำและปัญหา</small></div></div>
                <div class="task-guide-item"><span>3</span><div><strong>เวลาและรูป</strong><small>อัปเดตผลการทำงาน</small></div></div>
            </section>

            <div class="row g-4 align-items-start">
                <div class="col-xl-8">
                    <section class="card form-card task-section-card mb-4">
                        <div class="card-header d-flex align-items-center gap-3">
                            <span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-card-checklist"></i></span>
                            <div><h2 class="section-title mb-0">ข้อมูลงาน</h2><p class="small text-muted mb-0">ตรวจสอบชื่อ ทีม ผู้รับผิดชอบ และสถานะปัจจุบัน</p></div>
                        </div>
                        <div class="card-body p-4">
                            <div class="row g-4">
                                <div class="col-lg-8">
                                    <label for="taskTitle" class="form-label">ชื่องาน <span class="required-mark">*</span></label>
                                    <input type="text" class="form-control form-control-lg" id="taskTitle" name="title" value="<?php echo htmlspecialchars(edit_display_value($task["title"]), ENT_QUOTES, "UTF-8"); ?>" autocomplete="off" maxlength="255" required>
                                    <div class="form-text">ตั้งชื่อสั้น กระชับ และค้นหาเจอได้ง่าย</div>
                                </div>
                                <div class="col-lg-4">
                                    <label for="department" class="form-label">ทีม <span class="required-mark">*</span></label>
                                    <?php if ($can_select_department): ?>
                                        <select class="form-select form-select-lg" id="department" name="department" required><?php foreach ($departments as $department_option): ?><option value="<?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?>"<?php echo $task["department"] === $department_option ? " selected" : ""; ?>><?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select>
                                    <?php else: ?>
                                        <input type="text" class="form-control form-control-lg bg-light" id="department" value="<?php echo htmlspecialchars($task["department"], ENT_QUOTES, "UTF-8"); ?>" readonly>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label for="responsibleName" class="form-label">ผู้รับผิดชอบ</label>
                                    <input type="text" class="form-control" id="responsibleName" name="responsible_name" value="<?php echo htmlspecialchars(edit_display_value($task["responsible_name"] ?? ""), ENT_QUOTES, "UTF-8"); ?>" placeholder="ชื่อผู้รับผิดชอบ" maxlength="150">
                                </div>
                                <div class="col-md-6<?php echo $can_control_status ? "" : " d-none"; ?>" id="editStatusSelectGroup">
                                    <label for="taskStatus" class="form-label">สถานะ <span class="required-mark">*</span></label>
                                    <select class="form-select" id="taskStatus" name="status" required><?php foreach ($task_input_status_options as $value => $label): ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, "UTF-8"); ?>"<?php echo $task["status"] === $value ? " selected" : ""; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select>
                                </div>
                                <div class="col-md-6<?php echo $can_control_status ? " d-none" : ""; ?>" id="editAutoStatusGroup">
                                    <label class="form-label">สถานะ</label>
                                    <div class="task-auto-status"><span class="badge rounded-pill status-pending" id="editAutoStatusBadge">รอดำเนินการ</span><small id="editAutoStatusHint">สถานะถูกกำหนดโดยระบบ</small></div>
                                </div>
                                <div class="col-md-6">
                                    <label for="location" class="form-label">สถานที่</label>
                                    <select class="form-select" id="location" name="location"><option value="">ไม่ระบุ</option><?php foreach ($location_options as $location_option): ?><option value="<?php echo htmlspecialchars($location_option, ENT_QUOTES, "UTF-8"); ?>"<?php echo $selected_location === $location_option ? " selected" : ""; ?>><?php echo htmlspecialchars($location_option, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?><option value="__other__"<?php echo $selected_location === "__other__" ? " selected" : ""; ?>>สถานที่อื่น</option></select>
                                </div>
                                <div class="col-md-6<?php echo $selected_location === "__other__" ? "" : " d-none"; ?>" id="otherLocationGroup">
                                    <label for="otherLocation" class="form-label">ระบุสถานที่อื่น</label>
                                    <input type="text" class="form-control" id="otherLocation" name="other_location" value="<?php echo htmlspecialchars($other_location, ENT_QUOTES, "UTF-8"); ?>" placeholder="กรอกชื่อสถานที่" maxlength="150"<?php echo $selected_location === "__other__" ? " required" : ""; ?>>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="card form-card task-section-card mb-4 mb-xl-0">
                        <div class="card-header d-flex align-items-center gap-3">
                            <span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-file-earmark-text"></i></span>
                            <div><h2 class="section-title mb-0">รายละเอียดเพิ่มเติม</h2><p class="small text-muted mb-0">อัปเดตสิ่งที่ดำเนินการ ปัญหา และผลการแก้ไข</p></div>
                        </div>
                        <div class="card-body p-4">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <label for="workDescription" class="form-label">รายละเอียดงาน</label>
                                    <textarea class="form-control" id="workDescription" name="work_description" rows="4" placeholder="ขอบเขตหรือสิ่งที่ต้องทำ"><?php echo htmlspecialchars(edit_display_value($task["work_description"] ?? ""), ENT_QUOTES, "UTF-8"); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label for="workAction" class="form-label">การดำเนินงาน</label>
                                    <textarea class="form-control" id="workAction" name="work_action" rows="4" placeholder="สิ่งที่ดำเนินการแล้ว"><?php echo htmlspecialchars(edit_display_value($task["work_action"] ?? ""), ENT_QUOTES, "UTF-8"); ?></textarea>
                                    <div class="form-text" id="workActionStatusHint">งาน AV จะเปลี่ยนเป็น “เสร็จสิ้น” อัตโนมัติเมื่อกรอกการดำเนินงานหรือเวลาสิ้นสุด</div>
                                </div>
                                <div class="col-12"><div class="task-section-divider"><span>ปัญหาและการแก้ไข (ถ้ามี)</span></div></div>
                                <div class="col-md-6">
                                    <label for="problemCategory" class="form-label">ประเภทปัญหา</label>
                                    <select class="form-select" id="problemCategory" name="category"><option value="-"<?php echo $task["category"] === "-" ? " selected" : ""; ?>>ไม่ระบุ</option><?php foreach ($problem_category_options as $category_value => $category_label): ?><option value="<?php echo htmlspecialchars($category_value, ENT_QUOTES, "UTF-8"); ?>"<?php echo $task["category"] === $category_value ? " selected" : ""; ?>><?php echo htmlspecialchars($category_label, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select>
                                </div>
                                <div class="col-md-6">
                                    <label for="problemDetail" class="form-label">ปัญหาที่พบ <span class="required-mark<?php echo $task["department"] === "IT" ? "" : " d-none"; ?>" id="editProblemRequired">*</span></label>
                                    <input type="text" class="form-control" id="problemDetail" name="problem" value="<?php echo htmlspecialchars(edit_display_value($task["problem"] ?? ""), ENT_QUOTES, "UTF-8"); ?>" placeholder="พิมพ์หรือเลือกปัญหาที่ทีมเคยบันทึก" autocomplete="off" maxlength="255"<?php echo $task["department"] === "IT" ? " required" : ""; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label for="solution" class="form-label">วิธีแก้ไขปัญหา</label>
                                    <textarea class="form-control" id="solution" name="solution" rows="3" placeholder="ระบุวิธีแก้ไข"><?php echo htmlspecialchars(edit_display_value($task["solution"] ?? ""), ENT_QUOTES, "UTF-8"); ?></textarea>
                                    <div class="form-text">งาน IT จะเปลี่ยนเป็น “เสร็จสิ้น” อัตโนมัติเมื่อกรอกวิธีแก้ไข</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="remark" class="form-label">หมายเหตุ</label>
                                    <textarea class="form-control" id="remark" name="remark" rows="3" placeholder="การติดตามผลหรือข้อมูลเพิ่มเติม"><?php echo htmlspecialchars(edit_display_value($task["remark"] ?? ""), ENT_QUOTES, "UTF-8"); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="col-xl-4">
                    <div class="task-side-column">
                        <section class="card form-card task-section-card mb-4">
                            <div class="card-header d-flex align-items-center gap-3">
                                <span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-clock-history"></i></span>
                                <div><h2 class="section-title mb-0">ระยะเวลาดำเนินงาน</h2><p class="small text-muted mb-0">วันและเวลาเริ่มจำเป็นต่อรายงาน</p></div>
                            </div>
                            <div class="card-body p-4">
                                <div class="row g-3">
                                    <div class="col-12"><label for="startDate" class="form-label">วันเริ่มดำเนินการ <span class="required-mark">*</span></label><input type="text" class="form-control date-picker" id="startDate" name="start_date" value="<?php echo htmlspecialchars($edit_start_date, ENT_QUOTES, "UTF-8"); ?>" required></div>
                                    <div class="col-12"><label for="startWorkTime" class="form-label">เวลาเริ่มงาน <span class="required-mark">*</span></label><input type="text" class="form-control time-picker" id="startWorkTime" name="start_work_time" value="<?php echo htmlspecialchars($edit_start_time, ENT_QUOTES, "UTF-8"); ?>" required></div>
                                    <div class="col-12"><div class="task-section-divider"><span>เมื่อดำเนินงานเสร็จ</span></div></div>
                                    <div class="col-12"><label for="finishDate" class="form-label">วันที่สิ้นสุด</label><input type="text" class="form-control date-picker" id="finishDate" name="finish_date" value="<?php echo htmlspecialchars($edit_finish_date, ENT_QUOTES, "UTF-8"); ?>" placeholder="เว้นว่างได้"></div>
                                    <div class="col-12"><label for="finishWorkTime" class="form-label">เวลาสิ้นสุดงาน</label><input type="text" class="form-control time-picker" id="finishWorkTime" name="finish_work_time" value="<?php echo htmlspecialchars($edit_finish_time, ENT_QUOTES, "UTF-8"); ?>" placeholder="เว้นว่างได้"></div>
                                </div>
                            </div>
                        </section>

                        <section class="card form-card task-section-card">
                            <div class="card-header d-flex align-items-center gap-3">
                                <span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-images"></i></span>
                                <div><h2 class="section-title mb-0">รูปภาพประกอบ</h2><p class="small text-muted mb-0">รูปเดิม <?php echo count($task_images); ?> รูป · เพิ่มได้สูงสุด 5 รูป</p></div>
                            </div>
                            <div class="card-body p-4">
                                <?php if ($task_images): ?>
                                    <div class="row g-2 mb-3 task-existing-images">
                                        <?php foreach ($task_images as $image): ?>
                                            <div class="col-6"><a class="task-existing-image text-decoration-none" href="../<?php echo htmlspecialchars($image["file_path"], ENT_QUOTES, "UTF-8"); ?>" target="_blank" rel="noopener"><img src="../<?php echo htmlspecialchars($image["file_path"], ENT_QUOTES, "UTF-8"); ?>" alt="<?php echo htmlspecialchars($image["original_name"], ENT_QUOTES, "UTF-8"); ?>"><span><?php echo htmlspecialchars($image["original_name"], ENT_QUOTES, "UTF-8"); ?></span></a></div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="small text-muted mb-3">ยังไม่มีรูปภาพประกอบงาน</div>
                                <?php endif; ?>
                                <label class="task-file-drop" for="taskImages"><i class="bi bi-cloud-arrow-up"></i><strong>เพิ่มรูปภาพ</strong><span>JPG, PNG หรือ WebP ไม่เกิน 5 MB/รูป</span></label>
                                <input type="file" class="visually-hidden" id="taskImages" name="task_images[]" accept="image/jpeg,image/png,image/webp" multiple>
                                <div class="small text-muted mt-2" id="taskImageSummary">ยังไม่ได้เลือกรูปใหม่</div>
                                <div class="row g-2 mt-1" id="taskImagePreview" aria-live="polite"></div>
                            </div>
                        </section>
                    </div>
                </div>
            </div>

            <div class="task-submit-bar mt-4">
                <div><strong class="d-block">บันทึกเฉพาะข้อมูลที่เปลี่ยนแปลง</strong><span class="small text-muted">สร้าง <?php echo $edit_created_at; ?> น. · แก้ไขล่าสุด <?php echo $edit_updated_at; ?> น.</span></div>
                <div class="d-flex flex-column-reverse flex-sm-row gap-2">
                    <a class="btn btn-outline-secondary px-4" href="../report/?task_id=<?php echo $task_id; ?>">ยกเลิก</a>
                    <button type="submit" class="btn btn-primary px-4" id="submitTaskEditButton"><i class="bi bi-save me-2"></i>บันทึกการแก้ไข</button>
                </div>
            </div>
        </form>
    </main>
</div>
<script>
    const locationSelect = document.getElementById("location");
    const otherLocationGroup = document.getElementById("otherLocationGroup");
    const otherLocation = document.getElementById("otherLocation");

    const updateOtherLocation = () => {
        if (!locationSelect || !otherLocationGroup || !otherLocation) return;
        const isOther = locationSelect.value === "__other__";
        otherLocationGroup.classList.toggle("d-none", !isOther);
        otherLocation.required = isOther;
    };
    locationSelect?.addEventListener("change", updateOtherLocation);
    updateOtherLocation();

    const statusControl = document.getElementById("taskStatus");
    const departmentControl = document.getElementById("department");
    const problemDetail = document.getElementById("problemDetail");
    const problemRequiredMark = document.getElementById("editProblemRequired");
    const solutionControl = document.getElementById("solution");
    const workActionControl = document.getElementById("workAction");
    const workActionStatusHint = document.getElementById("workActionStatusHint");
    const finishDateControl = document.getElementById("finishDate");
    const finishTimeControl = document.getElementById("finishWorkTime");
    const statusSelectGroup = document.getElementById("editStatusSelectGroup");
    const autoStatusGroup = document.getElementById("editAutoStatusGroup");
    const autoStatusBadge = document.getElementById("editAutoStatusBadge");
    const autoStatusHint = document.getElementById("editAutoStatusHint");
    const canControlStatus = <?php echo json_encode($can_control_status); ?>;

    const updateITEditWorkflow = () => {
        const isIT = departmentControl?.value === "IT";
        const isAV = departmentControl?.value === "AV";
        const hasSolution = Boolean(solutionControl?.value.trim());
        const hasWorkAction = Boolean(workActionControl?.value.trim());
        const hasFinishTime = Boolean(finishDateControl?.value && finishTimeControl?.value);
        if (problemDetail) problemDetail.required = isIT;
        problemRequiredMark?.classList.toggle("d-none", !isIT);
        workActionStatusHint?.classList.toggle("d-none", !isAV);
        if (!statusControl) return;
        statusSelectGroup?.classList.toggle("d-none", !canControlStatus);
        autoStatusGroup?.classList.toggle("d-none", canControlStatus);
        if (isIT && hasSolution && !canControlStatus) {
            statusControl.value = "completed";
        } else if (isIT && !canControlStatus && statusControl.value !== "cancelled") {
            statusControl.value = "pending";
        } else if (isAV && !canControlStatus && statusControl.value !== "cancelled") {
            statusControl.value = hasWorkAction || hasFinishTime ? "completed" : "in_progress";
        }
        if (!canControlStatus && autoStatusBadge) {
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

    departmentControl?.addEventListener("change", updateITEditWorkflow);
    solutionControl?.addEventListener("input", updateITEditWorkflow);
    workActionControl?.addEventListener("input", updateITEditWorkflow);
    finishDateControl?.addEventListener("change", updateITEditWorkflow);
    finishTimeControl?.addEventListener("change", updateITEditWorkflow);
    updateITEditWorkflow();

    const taskImageInput = document.getElementById("taskImages");
    const taskImagePreview = document.getElementById("taskImagePreview");
    const taskImageSummary = document.getElementById("taskImageSummary");
    let taskPreviewUrls = [];

    const clearTaskImagePreview = () => {
        taskPreviewUrls.forEach((url) => URL.revokeObjectURL(url));
        taskPreviewUrls = [];
        taskImagePreview?.replaceChildren();
    };

    taskImageInput?.addEventListener("change", () => {
        clearTaskImagePreview();
        const files = Array.from(taskImageInput.files || []);
        const allowedTypes = new Set(["image/jpeg", "image/png", "image/webp"]);
        const invalidFile = files.find((file) => !allowedTypes.has(file.type) || file.size > 5 * 1024 * 1024);

        if (files.length > 5 || invalidFile) {
            taskImageInput.value = "";
            if (taskImageSummary) {
                taskImageSummary.className = "small text-danger mt-2";
                taskImageSummary.textContent = files.length > 5
                    ? "เลือกได้ไม่เกิน 5 รูปต่อครั้ง"
                    : "มีไฟล์ที่ไม่รองรับหรือมีขนาดเกิน 5 MB";
            }
            return;
        }

        if (taskImageSummary) {
            taskImageSummary.className = "small text-muted mt-2";
            taskImageSummary.textContent = files.length
                ? `เลือกแล้ว ${files.length} รูป`
                : "ยังไม่ได้เลือกรูปใหม่";
        }

        files.forEach((file) => {
            const column = document.createElement("div");
            column.className = "col-6";
            const previewItem = document.createElement("div");
            previewItem.className = "task-image-preview-item";
            const image = document.createElement("img");
            const previewUrl = URL.createObjectURL(file);
            taskPreviewUrls.push(previewUrl);
            image.src = previewUrl;
            image.alt = "";
            const label = document.createElement("span");
            label.textContent = file.name;
            previewItem.append(image, label);
            column.append(previewItem);
            taskImagePreview?.append(column);
        });
    });

    const taskEditForm = document.getElementById("taskEditForm");
    const submitTaskEditButton = document.getElementById("submitTaskEditButton");
    taskEditForm?.addEventListener("submit", () => {
        if (!taskEditForm.checkValidity() || !submitTaskEditButton) return;
        submitTaskEditButton.disabled = true;
        submitTaskEditButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>กำลังบันทึก...';
    });
</script>
<script>
    window.taskProblemOptionsConfig = {
        endpoint: 'problem_options.php',
        csrfToken: <?php echo json_encode($task_problem_options_csrf); ?>,
        defaultDepartment: <?php echo json_encode($task["department"]); ?>
    };
</script>
<script src="problem_options.js?v=2"></script>
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>
