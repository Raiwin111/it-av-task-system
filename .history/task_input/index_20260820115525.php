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
$can_select_department = can_manage_all_tasks();
$task_can_modify = is_account_approved();
$form_error = "";

$profile_user_id = (int) $_SESSION["user_id"];
$profile_stmt = $conn->prepare("SELECT username, full_name FROM users WHERE id = ? LIMIT 1");
$profile_stmt->bind_param("i", $profile_user_id);
$profile_stmt->execute();
$task_profile = $profile_stmt->get_result()->fetch_assoc();
$profile_stmt->close();
$default_responsible_name = trim((string) ($task_profile["full_name"] ?? ""));
if ($default_responsible_name === "") $default_responsible_name = (string) ($task_profile["username"] ?? $_SESSION["username"] ?? "");

// Reuse recent task data for the responsible-person suggestion only. Task
// titles intentionally remain unrestricted plain text.
$suggestion_scope = "is_deleted = 0";
if (!$can_select_department) $suggestion_scope .= " AND department = ?";
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

$equipment_items = [];
$equipment_result = $conn->query("SELECT id, name FROM equipment WHERE is_enabled = 1 ORDER BY sort_order ASC, name ASC, id ASC");
while ($equipment_item = $equipment_result->fetch_assoc()) $equipment_items[] = $equipment_item;
$enabled_equipment_ids = array_fill_keys(array_map(static fn(array $item): int => (int) $item["id"], $equipment_items), true);

$posted_equipment_rows = [];
$equipment_post_invalid = false;
$posted_equipment_ids = is_array($_POST["equipment_id"] ?? null) ? $_POST["equipment_id"] : [];
$posted_equipment_quantities = is_array($_POST["equipment_quantity"] ?? null) ? $_POST["equipment_quantity"] : [];
foreach ($posted_equipment_ids as $index => $posted_equipment_id) {
    $equipment_id = filter_var($posted_equipment_id, FILTER_VALIDATE_INT);
    $quantity = filter_var($posted_equipment_quantities[$index] ?? null, FILTER_VALIDATE_INT);
    if (!$equipment_id && trim((string) $posted_equipment_id) === "") continue;
    if (!$equipment_id || !$quantity || $quantity < 1) {
        $equipment_post_invalid = true;
        continue;
    }
    if ($equipment_id && $quantity && $quantity > 0) {
        $posted_equipment_rows[$equipment_id] = ($posted_equipment_rows[$equipment_id] ?? 0) + $quantity;
    }
}
$posted_equipment_values = [];
foreach ($posted_equipment_rows as $equipment_id => $quantity) {
    $posted_equipment_values[] = ["equipment_id" => (int) $equipment_id, "quantity" => (int) $quantity];
}

$location_options = ["เมฆา1", "เมฆา2", "เมฆา3", "วารินทร์", "พิมาน"];
$selected_task_department = task_post_string("department") ?: $task_department;
if ($_SERVER["REQUEST_METHOD"] === "POST" && $task_can_modify && !hash_equals($task_form_csrf, task_post_string("csrf_token"))) {
    http_response_code(419);
    $form_error = "คำขอบันทึกหมดอายุ กรุณาลองใหม่อีกครั้ง";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && $task_can_modify) {
    $title = trim(task_post_string("title"));
    $category = "-";
    $department = $can_select_department ? task_post_string("department") : $task_department;
    $responsible_name = trim(task_post_string("responsible_name"));
    if ($responsible_name === "") $responsible_name = $default_responsible_name;
    if ($department === "AV") $responsible_name = $default_responsible_name;
    $location_choice = trim(task_post_string("location"));
    $location = $location_choice === "__other__" ? trim(task_post_string("other_location")) : $location_choice;
    $work_description = $department === "AV" ? trim(task_post_string("work_description")) : "";
    $work_action = "";
    $problem = "";
    $solution = "";
    $status = "pending";
    $remark = "";
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
        false,
        $work_action,
        $finish_time !== null
    );
    $equipment_selection_invalid = $department === "AV"
        && ($equipment_post_invalid || (bool) array_diff_key($posted_equipment_rows, $enabled_equipment_ids));
    [$prepared_images, $image_error] = prepare_task_image_uploads();

    if ($image_error !== null) {
        $form_error = $image_error;
    } elseif ($title === "" || $equipment_selection_invalid || !array_key_exists($status, $task_status_options) || !in_array($department, $departments, true) || !$start_time || ($finish_input_started && !$finish_time) || ($finish_time && $finish_time < $start_time)) {
        $form_error = $equipment_selection_invalid
            ? "รายการอุปกรณ์ AV ไม่ถูกต้องหรือถูกปิดใช้งานแล้ว"
            : "กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน";
    } else {
        $created_by = (int) $_SESSION["user_id"];
        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO tasks (title, category, department, responsible_name, location, work_description, work_action, problem, solution, status, start_time, finish_time, remark, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssssssssi", $title, $category, $department, $responsible_name, $location, $work_description, $work_action, $problem, $solution, $status, $start_time, $finish_time, $remark, $created_by);
        if ($stmt->execute()) {
            $new_task_id = (int) $stmt->insert_id;
            $stmt->close();
            $equipment_saved = true;
            if ($department === "AV" && $posted_equipment_rows) {
                $equipment_stmt = $conn->prepare("INSERT INTO task_equipments (task_id, equipment_id, quantity) VALUES (?, ?, ?)");
                foreach ($posted_equipment_rows as $equipment_id => $quantity) {
                    $equipment_stmt->bind_param("iii", $new_task_id, $equipment_id, $quantity);
                    if (!$equipment_stmt->execute()) {
                        $equipment_saved = false;
                        break;
                    }
                }
                $equipment_stmt->close();
            }
            $activity_saved = $equipment_saved && record_task_activity(
                $conn,
                $new_task_id,
                "created",
                "สร้างงานในทีม {$department}",
                null,
                $status
            );
            if (!$equipment_saved || !$activity_saved) {
                $conn->rollback();
                $form_error = "ไม่สามารถบันทึกข้อมูลงานและอุปกรณ์ได้ครบถ้วน กรุณาลองอีกครั้ง";
            } else {
                $conn->commit();
            }
            if ($form_error === "") {
                if (!store_task_images($conn, $new_task_id, $created_by, $prepared_images)) {
                    header("Location: index.php?saved=1&image_error=1&task_id=" . $new_task_id);
                    exit;
                }
                header("Location: index.php?saved=1&task_id=" . $new_task_id);
                exit;
            }
        } else {
            $stmt->close();
            $conn->rollback();
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
                    <div class="task-guide-item"><span>2</span><div><strong>ข้อมูลตามทีม</strong><small>รายละเอียด Event และอุปกรณ์สำหรับ AV</small></div></div>
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
                                        <input type="text" class="form-control form-control-lg" id="taskTitle" name="title" value="<?php echo htmlspecialchars(task_post_string("title"), ENT_QUOTES, "UTF-8"); ?>" placeholder="เช่น เตรียมห้องประชุมสำหรับสัมมนา" autocomplete="off" maxlength="255" required>
                                        <div class="form-text">พิมพ์ชื่องานใหม่ได้อย่างอิสระ</div>
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
                                            <input type="text" class="form-control" id="responsibleName" name="responsible_name" value="<?php echo htmlspecialchars($selected_task_department === "AV" ? $default_responsible_name : (task_post_string("responsible_name") ?: $default_responsible_name), ENT_QUOTES, "UTF-8"); ?>" placeholder="พิมพ์หรือเลือกชื่อผู้รับผิดชอบ" autocomplete="off" maxlength="150"<?php echo $selected_task_department === "AV" ? " readonly" : ""; ?> aria-autocomplete="list" aria-haspopup="listbox" aria-controls="responsibleSuggestionMenu" aria-expanded="false">
                                            <div class="task-suggestion-menu d-none" id="responsibleSuggestionMenu" role="listbox" aria-label="รายชื่อผู้รับผิดชอบ"></div>
                                        </div>
                                    </div>
                                    <input type="hidden" id="taskStatus" name="status" value="pending">
                                    <div class="col-md-6" id="taskAutoStatusGroup">
                                        <label class="form-label">สถานะ</label>
                                        <div class="task-auto-status">
                                            <span class="badge rounded-pill status-pending" id="taskAutoStatusBadge">รอดำเนินการ</span>
                                            <small id="taskAutoStatusHint">ระบบกำหนดสถานะให้อัตโนมัติ</small>
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

                        <section class="card form-card task-section-card mb-4 mb-xl-0<?php echo $selected_task_department === "AV" ? "" : " d-none"; ?>" id="avDetailsSection">
                            <div class="card-header d-flex align-items-center gap-3">
                                <span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-camera-video"></i></span>
                                <div><h2 class="section-title mb-0">รายละเอียดงาน AV</h2><p class="small text-muted mb-0">บันทึกรายละเอียด Event และอุปกรณ์ที่ใช้</p></div>
                            </div>
                            <div class="card-body p-4">
                                <div class="row g-4">
                                    <div class="col-12">
                                        <label for="workDescription" class="form-label">รายละเอียด Event / งาน</label>
                                        <textarea class="form-control" id="workDescription" name="work_description" rows="5" placeholder="อธิบายกิจกรรม จุดประสงค์ หรือข้อมูลสำคัญของ Event"><?php echo htmlspecialchars(task_post_string("work_description"), ENT_QUOTES, "UTF-8"); ?></textarea>
                                        <div class="form-text">รายละเอียดเชิงปัญหาและการแก้ไขสามารถเติมภายหลังได้ที่ Report → Edit</div>
                                    </div>
                                    <div class="col-12" id="avEquipmentGuide">
                                        <div class="task-equipment-guide">
                                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2"><div><i class="bi bi-camera-reels me-2"></i><strong>อุปกรณ์ที่ใช้งาน (ถ้ามี)</strong></div><button class="btn btn-sm btn-outline-primary" type="button" id="addEquipmentRow"><i class="bi bi-plus-lg me-1"></i>เพิ่มอุปกรณ์</button></div>
                                            <div class="mt-3" id="equipmentRows"></div>
                                            <?php if (!$equipment_items): ?><div class="form-text text-warning mt-2">ยังไม่มีอุปกรณ์ที่เปิดใช้งาน กรุณาให้ ADMIN เพิ่มในหน้า Config</div><?php endif; ?>
                                        </div>
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
                                            <label for="finishDate" class="form-label" id="finishDateLabel"><?php echo $selected_task_department === "IT" ? "วันที่สิ้นสุด" : "วันที่สิ้นสุดกิจกรรม"; ?> <span class="task-optional-label" id="finishDateOptionalLabel"><?php echo $selected_task_department === "IT" ? "ไม่บังคับ" : "แนะนำให้ระบุ"; ?></span></label>
                                            <input type="text" class="form-control date-picker" id="finishDate" name="finish_date" value="<?php echo htmlspecialchars(task_post_string("finish_date"), ENT_QUOTES, "UTF-8"); ?>" placeholder="<?php echo $selected_task_department === "IT" ? "เว้นว่างได้" : "ระบุวันที่สิ้นสุดกิจกรรม"; ?>">
                                        </div>
                                        <div class="col-12">
                                            <label for="finishWorkTime" class="form-label" id="finishTimeLabel"><?php echo $selected_task_department === "IT" ? "เวลาสิ้นสุดงาน" : "เวลาสิ้นสุดกิจกรรม"; ?> <span class="task-optional-label" id="finishTimeOptionalLabel"><?php echo $selected_task_department === "IT" ? "ไม่บังคับ" : "แนะนำให้ระบุ"; ?></span></label>
                                            <input type="text" class="form-control time-picker" id="finishWorkTime" name="finish_work_time" value="<?php echo htmlspecialchars(task_post_string("finish_work_time"), ENT_QUOTES, "UTF-8"); ?>" placeholder="<?php echo $selected_task_department === "IT" ? "เว้นว่างได้" : "ระบุเวลาสิ้นสุดกิจกรรม"; ?>">
                                        </div>
                                        <div class="col-12"><p class="small text-muted mb-0"><i class="bi bi-info-circle me-1"></i>งาน IT เว้นเวลาสิ้นสุดไว้เติมภายหลังได้ ส่วนงาน AV แนะนำให้ระบุตามกำหนดการ</p></div>
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
    const avDetailsSection = document.getElementById("avDetailsSection");
    const workDescriptionControl = document.getElementById("workDescription");
    const taskTimeSectionTitle = document.getElementById("taskTimeSectionTitle");
    const taskTimeSectionSubtitle = document.getElementById("taskTimeSectionSubtitle");
    const startDateLabel = document.getElementById("startDateLabel");
    const startTimeLabel = document.getElementById("startTimeLabel");
    const finishDateLabel = document.getElementById("finishDateLabel");
    const finishTimeLabel = document.getElementById("finishTimeLabel");
    const finishDateOptionalLabel = document.getElementById("finishDateOptionalLabel");
    const finishTimeOptionalLabel = document.getElementById("finishTimeOptionalLabel");
    const finishDateControl = document.getElementById("finishDate");
    const finishTimeControl = document.getElementById("finishWorkTime");
    const autoStatusBadge = document.getElementById("taskAutoStatusBadge");
    const autoStatusHint = document.getElementById("taskAutoStatusHint");
    const responsibleControl = document.getElementById("responsibleName");
    const defaultResponsibleName = <?php echo json_encode($default_responsible_name, JSON_UNESCAPED_UNICODE); ?>;
    const taskResponsibleSuggestions = <?php echo json_encode($task_responsible_suggestions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const equipmentItems = <?php echo json_encode($equipment_items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const postedEquipmentRows = <?php echo json_encode($posted_equipment_values, JSON_UNESCAPED_UNICODE); ?>;

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

    setupTaskAutocomplete(document.getElementById("responsibleName"), document.getElementById("responsibleSuggestionMenu"), taskResponsibleSuggestions);

    const equipmentRows = document.getElementById("equipmentRows");
    const addEquipmentRowButton = document.getElementById("addEquipmentRow");
    const equipmentOptionMarkup = (selectedId = 0) => [
        '<option value="">เลือกอุปกรณ์</option>',
        ...equipmentItems.map((item) => `<option value="${Number(item.id)}"${Number(item.id) === Number(selectedId) ? " selected" : ""}>${String(item.name).replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;")}</option>`)
    ].join("");

    const mergeDuplicateEquipment = (changedRow) => {
        const changedSelect = changedRow.querySelector("select");
        if (!changedSelect?.value) return;
        const duplicateRow = Array.from(equipmentRows.querySelectorAll(".task-equipment-row"))
            .find((row) => row !== changedRow && row.querySelector("select")?.value === changedSelect.value);
        if (!duplicateRow) return;
        const currentQuantity = changedRow.querySelector("input");
        const duplicateQuantity = duplicateRow.querySelector("input");
        duplicateQuantity.value = Math.max(1, Number(duplicateQuantity.value) || 1) + Math.max(1, Number(currentQuantity.value) || 1);
        changedRow.remove();
    };

    const addEquipmentRow = (selectedId = 0, initialQuantity = 1) => {
        if (!equipmentRows || !equipmentItems.length) return;
        const row = document.createElement("div");
        row.className = "task-equipment-row";
        row.innerHTML = `<select class="form-select" name="equipment_id[]" aria-label="เลือกอุปกรณ์">${equipmentOptionMarkup(selectedId)}</select><div class="task-equipment-quantity"><button class="btn btn-outline-secondary" type="button" data-quantity-action="decrease" aria-label="ลดจำนวน">−</button><input class="form-control text-center" type="number" name="equipment_quantity[]" value="${Math.max(1, Number(initialQuantity) || 1)}" min="1" step="1" aria-label="จำนวน"><button class="btn btn-outline-secondary" type="button" data-quantity-action="increase" aria-label="เพิ่มจำนวน">+</button></div><button class="btn btn-outline-danger" type="button" data-equipment-remove aria-label="นำรายการนี้ออก"><i class="bi bi-trash"></i></button>`;
        const select = row.querySelector("select");
        const quantity = row.querySelector("input");
        select.addEventListener("change", () => mergeDuplicateEquipment(row));
        row.querySelector('[data-quantity-action="decrease"]').addEventListener("click", () => { quantity.value = Math.max(1, (Number(quantity.value) || 1) - 1); });
        row.querySelector('[data-quantity-action="increase"]').addEventListener("click", () => { quantity.value = Math.max(1, (Number(quantity.value) || 1) + 1); });
        row.querySelector("[data-equipment-remove]").addEventListener("click", () => row.remove());
        equipmentRows.append(row);
    };

    (postedEquipmentRows.length ? postedEquipmentRows : [{ equipment_id: 0, quantity: 1 }]).forEach((row) => addEquipmentRow(row.equipment_id, row.quantity));
    addEquipmentRowButton?.addEventListener("click", () => addEquipmentRow());

    const updateITWorkflow = () => {
        if (!departmentControl || !statusControl) return;
        const isIT = departmentControl.value === "IT";
        const isAV = departmentControl.value === "AV";
        const hasFinishTime = Boolean(finishDateControl?.value.trim() && finishTimeControl?.value.trim());

        avDetailsSection?.classList.toggle("d-none", !isAV);
        if (workDescriptionControl) workDescriptionControl.disabled = !isAV;
        equipmentRows?.querySelectorAll("select, input, button").forEach((control) => { control.disabled = !isAV; });
        if (addEquipmentRowButton) addEquipmentRowButton.disabled = !isAV || !equipmentItems.length;
        if (responsibleControl) {
            responsibleControl.readOnly = isAV;
            if (isAV) responsibleControl.value = defaultResponsibleName;
        }
        if (taskTimeSectionTitle) taskTimeSectionTitle.textContent = isIT ? "ระยะเวลาดำเนินงาน" : "วันที่และเวลากิจกรรม";
        if (taskTimeSectionSubtitle) taskTimeSectionSubtitle.textContent = isIT ? "ระบบใส่วันและเวลาเริ่มให้แล้ว" : "ระบุช่วงเวลาของ Event / Seminar / Operation";
        const setLabelText = (element, text) => { if (element?.firstChild) element.firstChild.nodeValue = `${text} `; };
        setLabelText(startDateLabel, isIT ? "วันเริ่มดำเนินการ" : "วันที่เริ่มกิจกรรม");
        setLabelText(startTimeLabel, isIT ? "เวลาเริ่มงาน" : "เวลาเริ่มกิจกรรม");
        setLabelText(finishDateLabel, isIT ? "วันที่สิ้นสุด" : "วันที่สิ้นสุดกิจกรรม");
        setLabelText(finishTimeLabel, isIT ? "เวลาสิ้นสุดงาน" : "เวลาสิ้นสุดกิจกรรม");
        if (finishDateOptionalLabel) finishDateOptionalLabel.textContent = isIT ? "ถ้ามี" : "แนะนำให้ระบุ";
        if (finishTimeOptionalLabel) finishTimeOptionalLabel.textContent = isIT ? "ถ้ามี" : "แนะนำให้ระบุ";
        if (finishDateControl) finishDateControl.placeholder = isIT ? "เว้นว่างได้" : "ระบุวันที่สิ้นสุดกิจกรรม";
        if (finishTimeControl) finishTimeControl.placeholder = isIT ? "เว้นว่างได้" : "ระบุเวลาสิ้นสุดกิจกรรม";

        let automaticStatus = "pending";
        if (isIT) {
            automaticStatus = "pending";
        } else if (isAV) {
            automaticStatus = hasFinishTime ? "completed" : "in_progress";
        }
        statusControl.value = automaticStatus;
        if (autoStatusBadge) {
            const statusDisplay = {
                pending: ["รอดำเนินการ", "status-pending"],
                in_progress: ["กำลังดำเนินการ", "status-progress"],
                completed: ["เสร็จสิ้น", "status-completed"]
            }[automaticStatus];
            autoStatusBadge.className = `badge rounded-pill ${statusDisplay[1]}`;
            autoStatusBadge.textContent = statusDisplay[0];
        }
        if (autoStatusHint) {
            autoStatusHint.textContent = isIT
                ? "สร้างเป็น “รอดำเนินการ” และเติม Problem/Solution ภายหลังที่ Report → Edit"
                : (hasFinishTime
                    ? "มีเวลาสิ้นสุดแล้ว ระบบจะสร้างเป็น “เสร็จสิ้น”"
                    : "ยังไม่มีเวลาสิ้นสุด ระบบจะสร้างเป็น “กำลังดำเนินการ”");
        }
    };

    departmentControl?.addEventListener("change", updateITWorkflow);
    finishDateControl?.addEventListener("change", updateITWorkflow);
    finishTimeControl?.addEventListener("change", updateITWorkflow);
    updateITWorkflow();

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
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>
