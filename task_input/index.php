<?php
require_once __DIR__ . "/../auth/auth_check.php";
require_once __DIR__ . "/../auth/authorization.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/constants.php";
require_once __DIR__ . "/../includes/helpers.php";
require_once __DIR__ . "/../includes/task_activity.php";
require_once __DIR__ . "/image_helpers.php";

function task_post_string(string $key): string
{
    $value = $_POST[$key] ?? "";
    return is_string($value) ? $value : "";
}

$task_role = strtoupper($_SESSION["role"] ?? "USER");
$task_department = $_SESSION["department"] ?? "";
$task_form_csrf = $_SESSION["task_form_csrf"] ??= bin2hex(random_bytes(32));
$task_problem_options_csrf = $_SESSION["task_problem_options_csrf"] ??= bin2hex(random_bytes(32));
$task_problem_options_asset = implode(".", ["problem_options", "js"]);
$can_select_department = can_manage_all_tasks();
$can_control_status = can_manage_all_tasks();
$task_can_modify = is_account_approved();
$form_error = "";
$task_input_status_options = $task_status_options;

$profile_user_id = (int) $_SESSION["user_id"];
$profile_stmt = $conn->prepare("SELECT username, full_name FROM users WHERE id = ? LIMIT 1");
$profile_stmt->bind_param("i", $profile_user_id);
$profile_stmt->execute();
$task_profile = $profile_stmt->get_result()->fetch_assoc();
$profile_stmt->close();
$default_responsible_name = trim((string) ($task_profile["full_name"] ?? ""));
if ($default_responsible_name === "") $default_responsible_name = (string) ($task_profile["username"] ?? $_SESSION["username"] ?? "");

// Reuse recent task data for lightweight, team-scoped suggestions. These are
// display helpers only; submitted values still pass through the existing form.
$suggestion_scope = "is_deleted = 0";
if (!$can_select_department) $suggestion_scope .= " AND department = ?";
$title_suggestion_stmt = $conn->prepare(
    "SELECT department, title AS suggestion, MAX(updated_at) AS last_used
     FROM tasks
     WHERE {$suggestion_scope} AND TRIM(title) <> ''
     GROUP BY department, title
     ORDER BY last_used DESC
     LIMIT 120"
);
if (!$can_select_department) $title_suggestion_stmt->bind_param("s", $task_department);
$title_suggestion_stmt->execute();
$task_title_suggestions = $title_suggestion_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$title_suggestion_stmt->close();

$responsible_suggestion_stmt = $conn->prepare(
    "SELECT department, responsible_name AS suggestion, MAX(updated_at) AS last_used
     FROM tasks
     WHERE {$suggestion_scope} AND TRIM(responsible_name) NOT IN ('', '-')
     GROUP BY department, responsible_name
     ORDER BY last_used DESC
     LIMIT 120"
);
if (!$can_select_department) $responsible_suggestion_stmt->bind_param("s", $task_department);
$responsible_suggestion_stmt->execute();
$task_responsible_suggestions = $responsible_suggestion_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$responsible_suggestion_stmt->close();
if ($default_responsible_name !== "") {
    array_unshift($task_responsible_suggestions, [
        "department" => $task_department,
        "suggestion" => $default_responsible_name,
    ]);
}

$location_options = ["เมฆา1", "เมฆา2", "เมฆา3", "วารินทร์", "พิมาน"];
$selected_task_department = task_post_string("department") ?: $task_department;
if ($_SERVER["REQUEST_METHOD"] === "POST" && $task_can_modify && !hash_equals($task_form_csrf, task_post_string("csrf_token"))) {
    http_response_code(419);
    $form_error = "คำขอบันทึกหมดอายุ กรุณาลองใหม่อีกครั้ง";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && $task_can_modify) {
    $title = trim(task_post_string("title"));
    $category = trim(task_post_string("category"));
    $category = $category === "" ? "-" : $category;
    $department = $can_select_department ? task_post_string("department") : $task_department;
    $responsible_name = trim(task_post_string("responsible_name"));
    if ($responsible_name === "") $responsible_name = $default_responsible_name;
    $location_choice = trim(task_post_string("location"));
    $location = $location_choice === "__other__" ? trim(task_post_string("other_location")) : $location_choice;
    $work_description = trim(task_post_string("work_description"));
    $work_action = trim(task_post_string("work_action"));
    $problem = trim(task_post_string("problem"));
    $solution = trim(task_post_string("solution"));
    $status = task_post_string("status") ?: "pending";
    if (!$can_control_status) $status = "pending";
    $it_problem_missing = task_problem_is_required($department, $problem);
    $remark = trim(task_post_string("remark"));
    $location = $location === "" ? "-" : $location;
    $work_description = $work_description === "" ? "-" : $work_description;
    $work_action = $work_action === "" ? "-" : $work_action;
    $problem = $problem === "" ? "-" : $problem;
    $solution = $solution === "" ? "-" : $solution;
    $remark = $remark === "" ? "-" : $remark;
    $start_time = combine_thai_date_time(task_post_string("start_date"), task_post_string("start_work_time"));
    $finish_date_value = trim(task_post_string("finish_date"));
    $finish_work_time_value = trim(task_post_string("finish_work_time"));
    $finish_input_started = $finish_date_value !== "" || $finish_work_time_value !== "";
    $finish_time = combine_thai_date_time($finish_date_value, $finish_work_time_value);
    $status = task_workflow_status(
        $department,
        $solution,
        $status,
        true,
        $can_control_status,
        $work_action,
        $finish_time !== null
    );
    if ($department === "IT" && $status === "in_progress") {
        $finish_input_started = false;
        $finish_time = null;
    }
    if ($status === "completed" && !$finish_time && !$finish_input_started) $finish_time = date("Y-m-d H:i:s");
    [$prepared_images, $image_error] = prepare_task_image_uploads();

    if ($image_error !== null) {
        $form_error = $image_error;
    } elseif ($title === "" || $it_problem_missing || ($category !== "-" && !array_key_exists($category, $problem_category_options)) || !array_key_exists($status, $task_input_status_options) || !in_array($department, $departments, true) || !$start_time || ($finish_input_started && !$finish_time) || ($finish_time && $finish_time < $start_time)) {
        $form_error = $it_problem_missing
            ? "งาน IT จำเป็นต้องระบุปัญหาที่พบ"
            : "กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน";
    } else {
        $created_by = (int) $_SESSION["user_id"];
        $stmt = $conn->prepare("INSERT INTO tasks (title, category, department, responsible_name, location, work_description, work_action, problem, solution, status, start_time, finish_time, remark, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssssssssi", $title, $category, $department, $responsible_name, $location, $work_description, $work_action, $problem, $solution, $status, $start_time, $finish_time, $remark, $created_by);
        if ($stmt->execute()) {
            $new_task_id = (int) $stmt->insert_id;
            $stmt->close();
            record_task_activity(
                $conn,
                $new_task_id,
                "created",
                "สร้างงานในทีม {$department}",
                null,
                $status
            );
            if (!store_task_images($conn, $new_task_id, $created_by, $prepared_images)) {
                header("Location: index.php?saved=1&image_error=1&task_id=" . $new_task_id);
                exit;
            }
            if ($problem !== "-") {
                $option_stmt = $conn->prepare("INSERT IGNORE INTO team_problem_options (department, problem_text, created_by) VALUES (?, ?, ?)");
                $option_stmt->bind_param("ssi", $department, $problem, $created_by);
                $option_stmt->execute();
                $option_stmt->close();
            }
            if ($form_error === "") {
                header("Location: index.php?saved=1&task_id=" . $new_task_id);
                exit;
            }
        } else {
            $stmt->close();
            $form_error = "ไม่สามารถบันทึกข้อมูลได้ กรุณาลองอีกครั้ง";
        }
    }
}

$active_nav = "task_input";
$app_page_title = "บันทึกงาน | IT / AV Task Management System";
$app_stylesheets = ["task_input.css"];
$saved_task_id = isset($_GET["task_id"]) ? max(0, (int) $_GET["task_id"]) : 0;
require_once __DIR__ . "/../includes/app_header.php";
?>
<div class="app-shell d-flex">
    <?php require_once __DIR__ . "/../includes/app_sidebar.php"; ?>
    <main class="main-content task-input-page flex-grow-1 p-4 p-lg-5">
        <div class="task-page-heading mb-4">
            <div>
                <h1 class="page-heading h3 fw-bold mb-1">บันทึกงานใหม่</h1>
                <p class="page-subtitle mb-0">สร้าง Task สำหรับทีม IT / AV — กรอกเฉพาะข้อมูลที่มีและกลับมาแก้ไขเพิ่มเติมได้</p>
            </div>
        </div>

        <?php if (isset($_GET["saved"])): ?>
            <div class="alert alert-success d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2" role="status">
                <div><i class="bi bi-check-circle-fill me-2"></i>บันทึกงานเรียบร้อยแล้ว</div>
                <?php if ($saved_task_id > 0): ?><a class="alert-link text-nowrap" href="../report/?task_id=<?php echo $saved_task_id; ?>">เปิดดูงานนี้ <i class="bi bi-arrow-right ms-1"></i></a><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET["image_error"])): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill me-2"></i>บันทึกงานแล้ว แต่ไม่สามารถบันทึกรูปภาพบางส่วนได้ กรุณาเปิดหน้าแก้ไขงานเพื่อลองแนบใหม่</div><?php endif; ?>
        <?php if ($form_error !== ""): ?><div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-circle-fill me-2"></i><?php echo htmlspecialchars($form_error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>
        <?php if (!$task_can_modify): ?><div class="alert alert-info"><i class="bi bi-eye me-1"></i>บัญชีนี้อยู่ระหว่างรอผู้ดูแลกำหนดทีมและสิทธิ์ จึงดูข้อมูลได้เท่านั้น</div><?php endif; ?>

        <form method="post" action="" enctype="multipart/form-data" id="taskCreateForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($task_form_csrf, ENT_QUOTES, "UTF-8"); ?>">
            <fieldset<?php echo !$task_can_modify ? " disabled" : ""; ?>>
                <section class="task-form-guide task-form-guide-three mb-4" aria-label="ขั้นตอนการบันทึกงาน">
                    <div class="task-guide-item active"><span>1</span><div><strong>ข้อมูลหลัก</strong><small>ชื่อ ทีม และผู้รับผิดชอบ</small></div></div>
                    <div class="task-guide-item"><span>2</span><div><strong>ข้อมูลตามทีม</strong><small>IT Problem / Solution หรือ AV Operation</small></div></div>
                    <div class="task-guide-item"><span>3</span><div><strong>เวลาและไฟล์</strong><small>ช่วงเวลาและรูปประกอบ</small></div></div>
                </section>

                <div class="row g-4 align-items-start">
                    <div class="col-xl-8">
                        <section class="card form-card task-section-card mb-4">
                            <div class="card-header d-flex align-items-center gap-3">
                                <span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-card-checklist"></i></span>
                                <div><h2 class="section-title mb-0">ข้อมูลงาน</h2><p class="small text-muted mb-0">ช่องที่มีเครื่องหมาย * จำเป็นต่อการสร้างงาน</p></div>
                            </div>
                            <div class="card-body p-4">
                                <div class="row g-4">
                                    <div class="col-lg-8">
                                        <label for="taskTitle" class="form-label">ชื่องาน <span class="required-mark">*</span></label>
                                        <div class="task-autocomplete">
                                            <input type="text" class="form-control form-control-lg" id="taskTitle" name="title" value="<?php echo htmlspecialchars(task_post_string("title"), ENT_QUOTES, "UTF-8"); ?>" placeholder="เช่น เตรียมห้องประชุมสำหรับสัมมนา" autocomplete="off" maxlength="255" required aria-autocomplete="list" aria-haspopup="listbox" aria-controls="taskTitleHistoryMenu" aria-expanded="false">
                                            <div class="task-suggestion-menu d-none" id="taskTitleHistoryMenu" role="listbox" aria-label="ชื่องานที่เคยบันทึก"></div>
                                        </div>
                                        <div class="form-text">พิมพ์ชื่อใหม่หรือเลือกงานที่เคยบันทึกในทีมนี้</div>
                                    </div>
                                    <div class="col-lg-4">
                                        <label for="department" class="form-label">ทีม <span class="required-mark">*</span></label>
                                        <?php if ($can_select_department): ?>
                                            <select class="form-select form-select-lg" id="department" name="department" required>
                                                <?php foreach ($departments as $department_option): ?>
                                                    <option value="<?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?>"<?php echo (task_post_string("department") ?: $task_department) === $department_option ? " selected" : ""; ?>><?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <input type="text" class="form-control form-control-lg bg-light" id="department" name="department" value="<?php echo htmlspecialchars($task_department, ENT_QUOTES, "UTF-8"); ?>" readonly>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="responsibleName" class="form-label">ผู้รับผิดชอบ <span class="task-optional-label">ไม่บังคับ</span></label>
                                        <div class="task-autocomplete">
                                            <input type="text" class="form-control" id="responsibleName" name="responsible_name" value="<?php echo htmlspecialchars(task_post_string("responsible_name") ?: $default_responsible_name, ENT_QUOTES, "UTF-8"); ?>" placeholder="พิมพ์หรือเลือกชื่อผู้รับผิดชอบ" autocomplete="off" maxlength="150" aria-autocomplete="list" aria-haspopup="listbox" aria-controls="responsibleSuggestionMenu" aria-expanded="false">
                                            <div class="task-suggestion-menu d-none" id="responsibleSuggestionMenu" role="listbox" aria-label="รายชื่อผู้รับผิดชอบ"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6<?php echo $can_control_status ? "" : " d-none"; ?>" id="taskStatusSelectGroup">
                                        <label for="taskStatus" class="form-label">สถานะ <span class="required-mark">*</span></label>
                                        <select class="form-select" id="taskStatus" name="status" required>
                                            <?php foreach ($task_input_status_options as $status_value => $status_label): ?>
                                                <option value="<?php echo htmlspecialchars($status_value, ENT_QUOTES, "UTF-8"); ?>"<?php echo (task_post_string("status") ?: "pending") === $status_value ? " selected" : ""; ?>><?php echo htmlspecialchars($status_label, ENT_QUOTES, "UTF-8"); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6<?php echo $can_control_status ? " d-none" : ""; ?>" id="taskAutoStatusGroup">
                                        <label class="form-label">สถานะ</label>
                                        <div class="task-auto-status">
                                            <span class="badge rounded-pill status-pending" id="taskAutoStatusBadge">รอดำเนินการ</span>
                                            <small id="taskAutoStatusHint">เมื่อบันทึก ระบบจะเปลี่ยนเป็น “กำลังดำเนินการ”</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="location" class="form-label">สถานที่ <span class="task-optional-label">ไม่บังคับ</span></label>
                                        <select class="form-select" id="location" name="location">
                                            <option value="">ไม่ระบุ</option>
                                            <?php foreach ($location_options as $location_option): ?>
                                                <option value="<?php echo htmlspecialchars($location_option, ENT_QUOTES, "UTF-8"); ?>"<?php echo task_post_string("location") === $location_option ? " selected" : ""; ?>><?php echo htmlspecialchars($location_option, ENT_QUOTES, "UTF-8"); ?></option>
                                            <?php endforeach; ?>
                                            <option value="__other__"<?php echo task_post_string("location") === "__other__" ? " selected" : ""; ?>>สถานที่อื่น</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6<?php echo task_post_string("location") === "__other__" ? "" : " d-none"; ?>" id="otherLocationGroup">
                                        <label for="otherLocation" class="form-label">ระบุสถานที่อื่น</label>
                                        <input type="text" class="form-control" id="otherLocation" name="other_location" value="<?php echo htmlspecialchars(task_post_string("other_location"), ENT_QUOTES, "UTF-8"); ?>" placeholder="กรอกชื่อสถานที่" maxlength="150">
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="card form-card task-section-card mb-4 mb-xl-0" id="itResolutionSection">
                            <div class="card-header d-flex align-items-center gap-3">
                                <span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi <?php echo $selected_task_department === "IT" ? "bi-tools" : "bi-camera-video"; ?>" id="taskContextIcon"></i></span>
                                <div><h2 class="section-title mb-0" id="taskContextTitle"><?php echo $selected_task_department === "IT" ? "รายละเอียดงาน IT" : "รายละเอียดงาน AV"; ?></h2><p class="small text-muted mb-0" id="taskContextSubtitle"><?php echo $selected_task_department === "IT" ? "บันทึกตามลำดับ Problem → Solution" : "บันทึกกิจกรรม อุปกรณ์ และผลการดำเนินงาน"; ?></p></div>
                            </div>
                            <div class="card-body p-4">
                                <div class="row g-4">
                                    <div class="col-lg-4<?php echo $selected_task_department === "IT" ? "" : " d-none"; ?>" id="itCategoryGroup">
                                        <label for="taskCategory" class="form-label">หมวดหมู่</label>
                                        <select class="form-select" id="taskCategory" name="category"<?php echo $selected_task_department === "IT" ? "" : " disabled"; ?>>
                                            <option value="-">ไม่ระบุ</option>
                                            <?php foreach ($problem_category_options as $category_value => $category_label): ?>
                                                <option value="<?php echo htmlspecialchars($category_value, ENT_QUOTES, "UTF-8"); ?>"<?php echo task_post_string("category") === $category_value ? " selected" : ""; ?>><?php echo htmlspecialchars($category_label, ENT_QUOTES, "UTF-8"); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="<?php echo $selected_task_department === "IT" ? "col-lg-8" : "col-12"; ?>" id="workDescriptionGroup">
                                        <label for="workDescription" class="form-label" id="workDescriptionLabel"><?php echo $selected_task_department === "IT" ? "รายละเอียดงาน" : "รายละเอียด Event และอุปกรณ์ที่ใช้งาน"; ?> <span class="task-optional-label">ไม่บังคับ</span></label>
                                        <textarea class="form-control" id="workDescription" name="work_description" rows="5" placeholder="<?php echo $selected_task_department === "IT" ? "อธิบายขอบเขตงานหรือสิ่งที่ตรวจสอบ" : "อธิบายกิจกรรมและอุปกรณ์ เช่น กล้อง 2 ตัว, ไมโครโฟน 4 ตัว, Projector 1 เครื่อง"; ?>"><?php echo htmlspecialchars(task_post_string("work_description"), ENT_QUOTES, "UTF-8"); ?></textarea>
                                        <div class="form-text" id="workDescriptionHint"><?php echo $selected_task_department === "IT" ? "ใช้บันทึกขอบเขตหรือรายละเอียดเพิ่มเติมของงาน" : "ระบุจำนวนกล้อง ไมโครโฟน Projector และอุปกรณ์อื่นที่ใช้ในกิจกรรม"; ?></div>
                                    </div>
                                    <div class="col-12<?php echo $selected_task_department === "AV" ? "" : " d-none"; ?>" id="avEquipmentGuide">
                                        <div class="task-equipment-guide">
                                            <div><i class="bi bi-camera-reels me-2"></i><strong>แนวทางระบุอุปกรณ์</strong></div>
                                            <div class="task-equipment-examples"><span>กล้อง · จำนวนตัว</span><span>ไมโครโฟน · จำนวนตัว</span><span>Projector · จำนวนเครื่อง</span><span>อุปกรณ์อื่น ๆ</span></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="problemDetail" class="form-label">ปัญหาที่พบ <span class="required-mark<?php echo $selected_task_department === "IT" ? "" : " d-none"; ?>" id="problemRequiredMark">*</span><span class="task-optional-label<?php echo $selected_task_department === "AV" ? "" : " d-none"; ?>" id="problemOptionalLabel">ไม่บังคับ</span></label>
                                        <textarea class="form-control" id="problemDetail" name="problem" rows="4" placeholder="อธิบายปัญหาที่ตรวจพบ"<?php echo $selected_task_department === "IT" ? " required" : ""; ?>><?php echo htmlspecialchars(task_post_string("problem"), ENT_QUOTES, "UTF-8"); ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="solution" class="form-label">วิธีแก้ไขปัญหา <span class="task-optional-label" id="solutionOptionalLabel"><?php echo $selected_task_department === "IT" ? "กรอกเมื่อแก้เสร็จ" : "ไม่บังคับ"; ?></span></label>
                                        <textarea class="form-control" id="solution" name="solution" rows="4" placeholder="เว้นว่างไว้หากยังแก้ไขไม่เสร็จ"><?php echo htmlspecialchars(task_post_string("solution"), ENT_QUOTES, "UTF-8"); ?></textarea>
                                        <div class="form-text" id="solutionStatusHint"><?php echo $selected_task_department === "IT" ? "เมื่อกรอกวิธีแก้ไข ระบบจะเปลี่ยนสถานะเป็น “เสร็จสิ้น” อัตโนมัติ" : "ใช้เฉพาะเมื่อกิจกรรม AV มีปัญหาและมีการแก้ไข"; ?></div>
                                    </div>
                                    <div class="col-12<?php echo $selected_task_department === "AV" ? "" : " d-none"; ?>" id="avWorkActionGroup">
                                        <label for="avWorkAction" class="form-label">สรุปการดำเนินงาน <span class="task-optional-label">ไม่บังคับ</span></label>
                                        <textarea class="form-control" id="avWorkAction" name="work_action" rows="4" placeholder="สรุปการติดตั้งอุปกรณ์ การควบคุมงาน หรือผลการจัดกิจกรรม"<?php echo $selected_task_department === "AV" ? "" : " disabled"; ?>><?php echo htmlspecialchars(task_post_string("work_action"), ENT_QUOTES, "UTF-8"); ?></textarea>
                                        <div class="form-text">ตาม Workflow เดิม เมื่อกรอกการดำเนินงานหรือเวลาสิ้นสุด ระบบจะเปลี่ยนเป็น “เสร็จสิ้น” โดยไม่ต้องมี Problem/Solution</div>
                                    </div>
                                    <div class="col-12">
                                        <label for="taskRemark" class="form-label">หมายเหตุ <span class="task-optional-label">ไม่บังคับ</span></label>
                                        <textarea class="form-control" id="taskRemark" name="remark" rows="3" placeholder="ข้อมูลเพิ่มเติมที่ควรทราบ"><?php echo htmlspecialchars(task_post_string("remark"), ENT_QUOTES, "UTF-8"); ?></textarea>
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
                                    <div><h2 class="section-title mb-0" id="taskTimeSectionTitle"><?php echo $selected_task_department === "IT" ? "ระยะเวลาดำเนินงาน" : "วันที่และเวลากิจกรรม"; ?></h2><p class="small text-muted mb-0" id="taskTimeSectionSubtitle"><?php echo $selected_task_department === "IT" ? "ระบบใส่วันและเวลาเริ่มให้แล้ว" : "ระบุช่วงเวลาของ Event / Seminar / Operation"; ?></p></div>
                                </div>
                                <div class="card-body p-4">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label for="startDate" class="form-label" id="startDateLabel"><?php echo $selected_task_department === "IT" ? "วันเริ่มดำเนินการ" : "วันที่เริ่มกิจกรรม"; ?> <span class="required-mark">*</span></label>
                                            <input type="text" class="form-control date-picker" id="startDate" name="start_date" value="<?php echo htmlspecialchars(task_post_string("start_date") ?: (date("d/m/") . (date("Y") + 543)), ENT_QUOTES, "UTF-8"); ?>" placeholder="วว/ดด/พ.ศ." required>
                                        </div>
                                        <div class="col-12">
                                            <label for="startWorkTime" class="form-label" id="startTimeLabel"><?php echo $selected_task_department === "IT" ? "เวลาเริ่มงาน" : "เวลาเริ่มกิจกรรม"; ?> <span class="required-mark">*</span></label>
                                            <input type="text" class="form-control time-picker" id="startWorkTime" name="start_work_time" value="<?php echo htmlspecialchars(task_post_string("start_work_time") ?: date("H:i"), ENT_QUOTES, "UTF-8"); ?>" placeholder="ชม:นาที" required>
                                        </div>
                                        <div class="col-12"><div class="task-section-divider"><span>เมื่อดำเนินงานเสร็จ</span></div></div>
                                        <div class="col-12">
                                            <label for="finishDate" class="form-label" id="finishDateLabel"><?php echo $selected_task_department === "IT" ? "วันที่สิ้นสุด" : "วันที่สิ้นสุดกิจกรรม"; ?> <span class="task-optional-label">ไม่บังคับ</span></label>
                                            <input type="text" class="form-control date-picker" id="finishDate" name="finish_date" value="<?php echo htmlspecialchars(task_post_string("finish_date"), ENT_QUOTES, "UTF-8"); ?>" placeholder="เว้นว่างได้">
                                        </div>
                                        <div class="col-12">
                                            <label for="finishWorkTime" class="form-label" id="finishTimeLabel"><?php echo $selected_task_department === "IT" ? "เวลาสิ้นสุดงาน" : "เวลาสิ้นสุดกิจกรรม"; ?> <span class="task-optional-label">ไม่บังคับ</span></label>
                                            <input type="text" class="form-control time-picker" id="finishWorkTime" name="finish_work_time" value="<?php echo htmlspecialchars(task_post_string("finish_work_time"), ENT_QUOTES, "UTF-8"); ?>" placeholder="เว้นว่างได้">
                                        </div>
                                        <div class="col-12"><p class="small text-muted mb-0"><i class="bi bi-info-circle me-1"></i>หากเลือกสถานะ “เสร็จสิ้น” ระบบจะเติมเวลาสิ้นสุดปัจจุบันให้อัตโนมัติ</p></div>
                                    </div>
                                </div>
                            </section>

                            <section class="card form-card task-section-card">
                                <div class="card-header d-flex align-items-center gap-3">
                                    <span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-images"></i></span>
                                    <div><h2 class="section-title mb-0">รูปภาพประกอบ</h2><p class="small text-muted mb-0">ไม่บังคับ สูงสุด 5 รูป</p></div>
                                </div>
                                <div class="card-body p-4">
                                    <label class="task-file-drop" for="taskImages">
                                        <i class="bi bi-cloud-arrow-up"></i>
                                        <strong>เลือกรูปภาพ</strong>
                                        <span>JPG, PNG หรือ WebP ไม่เกิน 5 MB/รูป</span>
                                    </label>
                                    <input type="file" class="visually-hidden" id="taskImages" name="task_images[]" accept="image/jpeg,image/png,image/webp" multiple>
                                    <div class="small text-muted mt-2" id="taskImageSummary">ยังไม่ได้เลือกรูปภาพ</div>
                                    <div class="row g-2 mt-1" id="taskImagePreview" aria-live="polite"></div>
                                </div>
                            </section>
                        </div>
                    </div>
                </div>

                <div class="task-submit-bar mt-4">
                    <div>
                        <strong class="d-block">พร้อมบันทึกเมื่อมีชื่องาน ทีม และเวลาเริ่ม</strong>
                        <span class="small text-muted">สร้างเมื่อ <?php echo date("d/m/") . (date("Y") + 543) . " " . date("H:i"); ?> น.</span>
                    </div>
                    <div class="d-flex flex-column-reverse flex-sm-row gap-2">
                        <a class="btn btn-outline-secondary px-4" href="../report/">ยกเลิก</a>
                        <button type="submit" class="btn btn-primary px-4" id="submitTaskButton"><i class="bi bi-save me-2"></i>บันทึกงาน</button>
                    </div>
                </div>
            </fieldset>
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
    const itResolutionSection = document.getElementById("itResolutionSection");
    const problemDetail = document.getElementById("problemDetail");
    const solutionControl = document.getElementById("solution");
    const itCategoryGroup = document.getElementById("itCategoryGroup");
    const categoryControl = document.getElementById("taskCategory");
    const workDescriptionGroup = document.getElementById("workDescriptionGroup");
    const workDescriptionControl = document.getElementById("workDescription");
    const workDescriptionLabel = document.getElementById("workDescriptionLabel");
    const workDescriptionHint = document.getElementById("workDescriptionHint");
    const avEquipmentGuide = document.getElementById("avEquipmentGuide");
    const avWorkActionGroup = document.getElementById("avWorkActionGroup");
    const avWorkAction = document.getElementById("avWorkAction");
    const problemRequiredMark = document.getElementById("problemRequiredMark");
    const problemOptionalLabel = document.getElementById("problemOptionalLabel");
    const solutionOptionalLabel = document.getElementById("solutionOptionalLabel");
    const solutionStatusHint = document.getElementById("solutionStatusHint");
    const taskContextIcon = document.getElementById("taskContextIcon");
    const taskContextTitle = document.getElementById("taskContextTitle");
    const taskContextSubtitle = document.getElementById("taskContextSubtitle");
    const taskTimeSectionTitle = document.getElementById("taskTimeSectionTitle");
    const taskTimeSectionSubtitle = document.getElementById("taskTimeSectionSubtitle");
    const startDateLabel = document.getElementById("startDateLabel");
    const startTimeLabel = document.getElementById("startTimeLabel");
    const finishDateLabel = document.getElementById("finishDateLabel");
    const finishTimeLabel = document.getElementById("finishTimeLabel");
    const finishDateControl = document.getElementById("finishDate");
    const finishTimeControl = document.getElementById("finishWorkTime");
    const statusSelectGroup = document.getElementById("taskStatusSelectGroup");
    const autoStatusGroup = document.getElementById("taskAutoStatusGroup");
    const autoStatusBadge = document.getElementById("taskAutoStatusBadge");
    const autoStatusHint = document.getElementById("taskAutoStatusHint");
    const canControlStatus = <?php echo json_encode($can_control_status); ?>;
    const taskTitleHistoryItems = <?php echo json_encode($task_title_suggestions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const taskResponsibleSuggestions = <?php echo json_encode($task_responsible_suggestions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    const setupTaskAutocomplete = (input, menu, suggestions) => {
        if (!input || !menu || !Array.isArray(suggestions)) return;
        let visibleButtons = [];
        let activeIndex = -1;
        let closeTimer = null;

        const closeMenu = () => {
            menu.classList.add("d-none");
            input.setAttribute("aria-expanded", "false");
            input.removeAttribute("aria-activedescendant");
            activeIndex = -1;
        };

        const selectSuggestion = (value) => {
            input.value = value;
            closeMenu();
            input.focus();
        };

        const setActiveSuggestion = (index) => {
            visibleButtons.forEach((button, buttonIndex) => button.classList.toggle("active", buttonIndex === index));
            activeIndex = index;
            const activeButton = visibleButtons[index];
            if (activeButton) {
                input.setAttribute("aria-activedescendant", activeButton.id);
                activeButton.scrollIntoView({ block: "nearest" });
            }
        };

        const renderSuggestions = () => {
            const keyword = input.value.trim().toLocaleLowerCase();
            const department = departmentControl?.value || "";
            const uniqueValues = new Set();
            const values = suggestions
                .filter((item) => !department || !item.department || item.department === department)
                .map((item) => String(item.suggestion || "").trim())
                .filter((value) => value && !uniqueValues.has(value) && uniqueValues.add(value))
                .filter((value) => !keyword || value.toLocaleLowerCase().includes(keyword))
                .slice(0, 8);

            menu.replaceChildren();
            visibleButtons = values.map((value, index) => {
                const button = document.createElement("button");
                button.type = "button";
                button.id = `${menu.id}Option${index}`;
                button.className = "task-suggestion-option";
                button.setAttribute("role", "option");
                button.textContent = value;
                button.addEventListener("click", () => selectSuggestion(value));
                menu.append(button);
                return button;
            });

            if (!visibleButtons.length) {
                closeMenu();
                return;
            }
            menu.classList.remove("d-none");
            input.setAttribute("aria-expanded", "true");
            activeIndex = -1;
        };

        input.addEventListener("focus", () => {
            window.clearTimeout(closeTimer);
            renderSuggestions();
        });
        input.addEventListener("input", renderSuggestions);
        input.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
                closeMenu();
                return;
            }
            if (!visibleButtons.length || !["ArrowDown", "ArrowUp", "Enter"].includes(event.key)) return;
            if (event.key === "Enter" && activeIndex < 0) return;
            event.preventDefault();
            if (event.key === "ArrowDown") setActiveSuggestion((activeIndex + 1) % visibleButtons.length);
            if (event.key === "ArrowUp") setActiveSuggestion((activeIndex - 1 + visibleButtons.length) % visibleButtons.length);
            if (event.key === "Enter") visibleButtons[activeIndex]?.click();
        });
        input.addEventListener("blur", () => {
            closeTimer = window.setTimeout(closeMenu, 150);
        });
        menu.addEventListener("mousedown", (event) => event.preventDefault());
        departmentControl?.addEventListener("change", closeMenu);
    };

    setupTaskAutocomplete(document.getElementById("taskTitle"), document.getElementById("taskTitleHistoryMenu"), taskTitleHistoryItems);
    setupTaskAutocomplete(document.getElementById("responsibleName"), document.getElementById("responsibleSuggestionMenu"), taskResponsibleSuggestions);

    const fillCurrentFinishTime = () => {
        const now = new Date();
        const finishDate = document.getElementById("finishDate");
        const finishTime = document.getElementById("finishWorkTime");
        const dateValue = `${String(now.getDate()).padStart(2, "0")}/${String(now.getMonth() + 1).padStart(2, "0")}/${now.getFullYear() + 543}`;
        const timeValue = `${String(now.getHours()).padStart(2, "0")}:${String(now.getMinutes()).padStart(2, "0")}`;
        if (finishDate && !finishDate.value) {
            if (finishDate._flatpickr) finishDate._flatpickr.setDate(dateValue, false, "d/m/Y");
            else finishDate.value = dateValue;
        }
        if (finishTime && !finishTime.value) {
            if (finishTime._flatpickr) finishTime._flatpickr.setDate(timeValue, false, "H:i");
            else finishTime.value = timeValue;
        }
    };

    const clearFinishTime = () => {
        const finishDate = document.getElementById("finishDate");
        const finishTime = document.getElementById("finishWorkTime");
        if (finishDate?._flatpickr) finishDate._flatpickr.clear(false);
        else if (finishDate) finishDate.value = "";
        if (finishTime?._flatpickr) finishTime._flatpickr.clear(false);
        else if (finishTime) finishTime.value = "";
    };

    const updateITWorkflow = () => {
        if (!departmentControl || !statusControl) return;
        const isIT = departmentControl.value === "IT";
        const isAV = departmentControl.value === "AV";
        const hasSolution = Boolean(solutionControl?.value.trim());
        const hasWorkAction = Boolean(avWorkAction?.value.trim());
        const hasFinishTime = Boolean(finishDateControl?.value.trim() && finishTimeControl?.value.trim());

        itResolutionSection?.classList.remove("d-none");
        itCategoryGroup?.classList.toggle("d-none", !isIT);
        if (categoryControl) categoryControl.disabled = !isIT;
        avEquipmentGuide?.classList.toggle("d-none", !isAV);
        avWorkActionGroup?.classList.toggle("d-none", !isAV);
        if (avWorkAction) avWorkAction.disabled = !isAV;
        workDescriptionGroup?.classList.toggle("col-lg-8", isIT);
        workDescriptionGroup?.classList.toggle("col-12", !isIT);
        problemRequiredMark?.classList.toggle("d-none", !isIT);
        problemOptionalLabel?.classList.toggle("d-none", isIT);
        solutionOptionalLabel?.classList.remove("d-none");
        if (solutionOptionalLabel) solutionOptionalLabel.textContent = isIT ? "กรอกเมื่อแก้เสร็จ" : "ไม่บังคับ";
        if (taskContextIcon) taskContextIcon.className = `bi ${isIT ? "bi-tools" : "bi-camera-video"}`;
        if (taskContextTitle) taskContextTitle.textContent = isIT ? "รายละเอียดงาน IT" : "รายละเอียดงาน AV";
        if (taskContextSubtitle) taskContextSubtitle.textContent = isIT ? "บันทึกตามลำดับ Problem → Solution" : "บันทึกกิจกรรม อุปกรณ์ และผลการดำเนินงาน";
        if (workDescriptionLabel?.firstChild) workDescriptionLabel.firstChild.nodeValue = `${isIT ? "รายละเอียดงาน" : "รายละเอียด Event และอุปกรณ์ที่ใช้งาน"} `;
        if (workDescriptionControl) workDescriptionControl.placeholder = isIT
            ? "อธิบายขอบเขตงานหรือสิ่งที่ตรวจสอบ"
            : "อธิบายกิจกรรมและอุปกรณ์ เช่น กล้อง 2 ตัว, ไมโครโฟน 4 ตัว, Projector 1 เครื่อง";
        if (workDescriptionHint) workDescriptionHint.textContent = isIT
            ? "ใช้บันทึกขอบเขตหรือรายละเอียดเพิ่มเติมของงาน"
            : "ระบุจำนวนกล้อง ไมโครโฟน Projector และอุปกรณ์อื่นที่ใช้ในกิจกรรม";
        if (problemDetail) problemDetail.placeholder = isIT ? "อธิบายปัญหาที่ตรวจพบ" : "กรอกเฉพาะเมื่อพบปัญหาระหว่างกิจกรรม";
        if (solutionControl) solutionControl.placeholder = isIT ? "เว้นว่างไว้หากยังแก้ไขไม่เสร็จ" : "กรอกเฉพาะเมื่อมีปัญหาและมีการแก้ไข";
        if (solutionStatusHint) solutionStatusHint.textContent = isIT
            ? "เมื่อกรอกวิธีแก้ไข ระบบจะเปลี่ยนสถานะเป็น “เสร็จสิ้น” อัตโนมัติ"
            : "ใช้เฉพาะเมื่อกิจกรรม AV มีปัญหาและมีการแก้ไข";
        if (taskTimeSectionTitle) taskTimeSectionTitle.textContent = isIT ? "ระยะเวลาดำเนินงาน" : "วันที่และเวลากิจกรรม";
        if (taskTimeSectionSubtitle) taskTimeSectionSubtitle.textContent = isIT ? "ระบบใส่วันและเวลาเริ่มให้แล้ว" : "ระบุช่วงเวลาของ Event / Seminar / Operation";
        const setLabelText = (element, text) => { if (element?.firstChild) element.firstChild.nodeValue = `${text} `; };
        setLabelText(startDateLabel, isIT ? "วันเริ่มดำเนินการ" : "วันที่เริ่มกิจกรรม");
        setLabelText(startTimeLabel, isIT ? "เวลาเริ่มงาน" : "เวลาเริ่มกิจกรรม");
        setLabelText(finishDateLabel, isIT ? "วันที่สิ้นสุด" : "วันที่สิ้นสุดกิจกรรม");
        setLabelText(finishTimeLabel, isIT ? "เวลาสิ้นสุดงาน" : "เวลาสิ้นสุดกิจกรรม");

        statusSelectGroup?.classList.toggle("d-none", !canControlStatus);
        autoStatusGroup?.classList.toggle("d-none", canControlStatus);
        if (problemDetail) problemDetail.required = isIT;
        if (isIT) {
            if (hasSolution) statusControl.value = "completed";
            else if (!canControlStatus) statusControl.value = "pending";
            if (autoStatusBadge) {
                const isCompleted = statusControl.value === "completed";
                autoStatusBadge.className = `badge rounded-pill ${isCompleted ? "status-completed" : "status-pending"}`;
                autoStatusBadge.textContent = isCompleted ? "เสร็จสิ้น" : "รอดำเนินการ";
            }
            if (autoStatusHint) {
                autoStatusHint.textContent = hasSolution
                    ? "ระบบตรวจพบวิธีแก้ไขและจะบันทึกเป็น “เสร็จสิ้น”"
                    : "เมื่อบันทึก ระบบจะเปลี่ยนเป็น “กำลังดำเนินการ”";
            }
            if (hasSolution) fillCurrentFinishTime();
            else clearFinishTime();
        } else if (isAV && !canControlStatus && autoStatusBadge) {
            const isCompleted = hasWorkAction || hasFinishTime;
            statusControl.value = isCompleted ? "completed" : "pending";
            autoStatusBadge.className = `badge rounded-pill ${isCompleted ? "status-completed" : "status-pending"}`;
            autoStatusBadge.textContent = isCompleted ? "เสร็จสิ้น" : "รอดำเนินการ";
            if (autoStatusHint) {
                autoStatusHint.textContent = isCompleted
                    ? "ระบบตรวจพบการดำเนินงานหรือเวลาสิ้นสุดและจะบันทึกเป็น “เสร็จสิ้น”"
                    : "เมื่อบันทึก ระบบจะเปลี่ยนเป็น “กำลังดำเนินการ”";
            }
            if (isCompleted && hasWorkAction && !hasFinishTime) fillCurrentFinishTime();
        }
    };

    departmentControl?.addEventListener("change", updateITWorkflow);
    solutionControl?.addEventListener("input", updateITWorkflow);
    avWorkAction?.addEventListener("input", updateITWorkflow);
    finishDateControl?.addEventListener("change", updateITWorkflow);
    finishTimeControl?.addEventListener("change", updateITWorkflow);
    updateITWorkflow();

    statusControl?.addEventListener("change", () => {
        if (statusControl.value !== "completed") return;
        fillCurrentFinishTime();
    });

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
                : "ยังไม่ได้เลือกรูปภาพ";
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

    const taskCreateForm = document.getElementById("taskCreateForm");
    const submitTaskButton = document.getElementById("submitTaskButton");
    taskCreateForm?.addEventListener("submit", () => {
        if (!taskCreateForm.checkValidity() || !submitTaskButton) return;
        submitTaskButton.disabled = true;
        submitTaskButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>กำลังบันทึก...';
    });
</script>
<script>
    window.taskProblemOptionsConfig = {
        endpoint: "problem_options.php",
        csrfToken: <?php echo json_encode($task_problem_options_csrf); ?>,
        defaultDepartment: <?php echo json_encode($selected_task_department); ?>
    };
</script>
<script src="<?php echo htmlspecialchars($task_problem_options_asset, ENT_QUOTES, "UTF-8"); ?>?v=3"></script>
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>
