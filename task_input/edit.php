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

function combine_edit_task_date_time(string $date_value, string $time_value): ?string
{
    if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', trim($date_value), $date_matches)) return null;
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($time_value), $time_matches)) return null;
    [, $day, $month, $year] = $date_matches;
    [, $hour, $minute] = $time_matches;
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
$current_department = (string) ($_SESSION["department"] ?? "");
$task_problem_options_csrf = $_SESSION["task_problem_options_csrf"] ??= bin2hex(random_bytes(32));
$can_manage_all_tasks = in_array($current_role, ["SUPER", "ADMIN"], true);
$can_select_department = $can_manage_all_tasks;
$form_error = "";
$task_input_status_options = $task_status_options;
unset($task_input_status_options["cancelled"]);

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

// USER accounts may edit tasks within their own team, regardless of creator.
if (!$can_manage_all_tasks && (string) $task["department"] !== $current_department) {
    header("Location: ../report/?error=forbidden");
    exit;
}

$location_options = ["เมฆา1", "เมฆา2", "เมฆา3", "วารินทร์", "พิมาน"];

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    $title = trim($_POST["title"] ?? "");
    $category = trim($_POST["category"] ?? "");
    $category = $category === "" ? "-" : $category;
    $department = $can_select_department ? ($_POST["department"] ?? "") : $task["department"];
    $responsible_name = trim($_POST["responsible_name"] ?? "");
    $location_choice = trim($_POST["location"] ?? "");
    $location = $location_choice === "__other__" ? trim($_POST["other_location"] ?? "") : $location_choice;
    $work_description = trim($_POST["work_description"] ?? "");
    $work_action = trim($_POST["work_action"] ?? "");
    $problem = trim($_POST["problem"] ?? "");
    $solution = trim($_POST["solution"] ?? "");
    $status = $_POST["status"] ?? "";
    $start_time = combine_edit_task_date_time($_POST["start_date"] ?? "", $_POST["start_work_time"] ?? "");
    $finish_time = combine_edit_task_date_time($_POST["finish_date"] ?? "", $_POST["finish_work_time"] ?? "");
    $remark = trim($_POST["remark"] ?? "");
    $location = $location === "" ? "-" : $location;
    $work_description = $work_description === "" ? "-" : $work_description;
    $work_action = $work_action === "" ? "-" : $work_action;
    $problem = $problem === "" ? "-" : $problem;
    $solution = $solution === "" ? "-" : $solution;
    $remark = $remark === "" ? "-" : $remark;

    if ($title === "" || ($category !== "-" && !array_key_exists($category, $problem_category_options)) || !array_key_exists($status, $task_input_status_options) || !in_array($department, $departments, true) || !$start_time || !$finish_time) {
        $form_error = "กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วนและตรวจสอบวันเวลา";
    } else {
        // Update the timestamp whenever a task edit is saved.
        $update_stmt = $conn->prepare("UPDATE tasks SET title = ?, category = ?, department = ?, responsible_name = ?, location = ?, work_description = ?, work_action = ?, problem = ?, solution = ?, status = ?, start_time = ?, finish_time = ?, remark = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $update_stmt->bind_param("sssssssssssssi", $title, $category, $department, $responsible_name, $location, $work_description, $work_action, $problem, $solution, $status, $start_time, $finish_time, $remark, $task_id);

        if ($update_stmt->execute()) {
            $update_stmt->close();
            if ($problem !== "-") {
                $option_stmt = $conn->prepare("INSERT IGNORE INTO team_problem_options (department, problem_text, created_by) VALUES (?, ?, ?)");
                $option_stmt->bind_param("ssi", $department, $problem, $current_user_id);
                $option_stmt->execute();
                $option_stmt->close();
            }
            header("Location: edit.php?id=" . $task_id . "&updated=1");
            exit;
        }

        $update_stmt->close();
        $form_error = "ไม่สามารถบันทึกการแก้ไขได้ กรุณาลองอีกครั้ง";
    }

    $task = array_merge($task, ["title" => $title, "category" => $category, "department" => $department, "responsible_name" => $responsible_name, "location" => $location, "work_description" => $work_description, "work_action" => $work_action, "problem" => $problem, "solution" => $solution, "status" => $status, "start_time" => $start_time ?: $task["start_time"], "finish_time" => $finish_time ?: $task["finish_time"], "remark" => $remark]);
}

$selected_location = in_array($task["location"], $location_options, true) ? $task["location"] : (($task["location"] === "" || $task["location"] === "-") ? "" : "__other__");
$other_location = $selected_location === "__other__" ? $task["location"] : "";

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
            <section class="card form-card mb-4"><div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-card-checklist"></i></span><h2 class="section-title mb-0">ข้อมูลงาน</h2></div><div class="card-body p-4"><div class="row g-4"><div class="col-md-9"><label for="title" class="form-label">ชื่องาน <span class="required-mark">*</span></label><input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($task["title"], ENT_QUOTES, "UTF-8"); ?>" required></div><div class="col-md-3"><label for="department" class="form-label">แผนก <span class="required-mark">*</span></label><?php if ($can_select_department): ?><select class="form-select" id="department" name="department"><?php foreach ($departments as $department_option): ?><option value="<?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?>"<?php echo $task["department"] === $department_option ? " selected" : ""; ?>><?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select><?php else: ?><input type="text" class="form-control bg-light" id="department" value="<?php echo htmlspecialchars($task["department"], ENT_QUOTES, "UTF-8"); ?>" readonly><?php endif; ?></div><div class="col-md-6"><label for="location" class="form-label">สถานที่</label><select class="form-select" id="location" name="location"><option value="">เลือกสถานที่ (ถ้ามี)</option><?php foreach ($location_options as $location_option): ?><option value="<?php echo htmlspecialchars($location_option, ENT_QUOTES, "UTF-8"); ?>"<?php echo $selected_location === $location_option ? " selected" : ""; ?>><?php echo htmlspecialchars($location_option, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?><option value="__other__"<?php echo $selected_location === "__other__" ? " selected" : ""; ?>>อื่นๆ</option></select></div><div class="col-md-6<?php echo $selected_location === "__other__" ? "" : " d-none"; ?>" id="otherLocationGroup"><label for="otherLocation" class="form-label">ระบุสถานที่</label><input type="text" class="form-control" id="otherLocation" name="other_location" value="<?php echo htmlspecialchars($other_location, ENT_QUOTES, "UTF-8"); ?>" placeholder="ระบุสถานที่อื่น"<?php echo $selected_location === "__other__" ? " required" : ""; ?>></div><div class="col-md-6"><label for="status" class="form-label">สถานะ <span class="required-mark">*</span></label><select class="form-select" id="status" name="status"><?php foreach ($task_status_options as $value => $label): ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, "UTF-8"); ?>"<?php echo $task["status"] === $value ? " selected" : ""; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div></div></div></section>

            <section class="card form-card mb-4"><div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-file-earmark-text"></i></span><h2 class="section-title mb-0">รายละเอียดงาน</h2></div><div class="card-body p-4"><div class="row g-4"><div class="col-12"><label for="problem" class="form-label">รายละเอียดงาน</label><textarea class="form-control" id="problem" name="problem" rows="5" placeholder="อธิบายรายละเอียดของงานที่ดำเนินการ"><?php echo htmlspecialchars($task["problem"], ENT_QUOTES, "UTF-8"); ?></textarea></div><div class="col-12"><label for="solution" class="form-label">การดำเนินการ</label><textarea class="form-control" id="solution" name="solution" rows="5" placeholder="ระบุการดำเนินการ (ถ้ามี)"><?php echo htmlspecialchars($task["solution"], ENT_QUOTES, "UTF-8"); ?></textarea></div><div class="col-12"><label for="remark" class="form-label">หมายเหตุ</label><textarea class="form-control" id="remark" name="remark" rows="4" placeholder="ระบุหมายเหตุเพิ่มเติม (ถ้ามี)"><?php echo htmlspecialchars($task["remark"], ENT_QUOTES, "UTF-8"); ?></textarea></div></div></div></section>

            <section class="card form-card mb-4"><div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-clock-history"></i></span><h2 class="section-title mb-0">เวลา</h2></div><div class="card-body p-4"><div class="row g-4"><div class="col-md-6"><label for="start_time" class="form-label">วันเริ่มดำเนินการ</label><input type="text" class="form-control datetime-picker" id="start_time" name="start_time" value="<?php echo htmlspecialchars(format_edit_thai_datetime($task["start_time"]), ENT_QUOTES, "UTF-8"); ?>" required></div><div class="col-md-6"><label for="finish_time" class="form-label">วันสิ้นสุด</label><input type="text" class="form-control datetime-picker" id="finish_time" name="finish_time" value="<?php echo htmlspecialchars(format_edit_thai_datetime($task["finish_time"]), ENT_QUOTES, "UTF-8"); ?>" required></div></div></div></section>

            <div class="form-actions d-flex flex-column flex-sm-row justify-content-end gap-2 pt-4"><a class="btn btn-outline-secondary px-4" href="../report/">ยกเลิก</a><button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i>บันทึกการแก้ไข</button></div>
        </form>
    </main>
</div>
<script>
    const editPageSubtitle = document.querySelector(".page-subtitle");
    if (editPageSubtitle) editPageSubtitle.textContent = "แก้ไขข้อมูลของงาน: " + <?php echo json_encode($task["title"], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    const problemCategories = <?php echo json_encode($problem_category_options, JSON_UNESCAPED_UNICODE); ?>;
    const selectedCategory = <?php echo json_encode($task["category"], JSON_UNESCAPED_UNICODE); ?>;
    const workDescriptionValue = <?php echo json_encode($task["work_description"] ?? "", JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const workActionValue = <?php echo json_encode($task["work_action"] ?? "", JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const responsibleNameValue = <?php echo json_encode($task["responsible_name"] ?? "", JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const startDateValue = <?php echo json_encode(date("d/m/", strtotime($task["start_time"])) . (date("Y", strtotime($task["start_time"])) + 543), JSON_UNESCAPED_UNICODE); ?>;
    const finishDateValue = <?php echo json_encode(date("d/m/", strtotime($task["finish_time"])) . (date("Y", strtotime($task["finish_time"])) + 543), JSON_UNESCAPED_UNICODE); ?>;
    const startWorkTimeValue = <?php echo json_encode(date("H:i", strtotime($task["start_time"]))); ?>;
    const finishWorkTimeValue = <?php echo json_encode(date("H:i", strtotime($task["finish_time"]))); ?>;
    const problemDetail = document.getElementById("problem");

    const oldStartTimeInput = document.getElementById("start_time");
    const timeSection = oldStartTimeInput?.closest("section");
    if (timeSection) {
        timeSection.querySelector(".card-header h2").textContent = "ระยะเวลาการดำเนินงาน";
        timeSection.querySelector(".card-body").innerHTML = `
            <div class="row g-4">
                <div class="col-md-6"><label for="startDate" class="form-label">วันเริ่มดำเนินการ <span class="required-mark">*</span></label><input type="text" class="form-control date-picker" id="startDate" name="start_date" required><div class="form-text">เลือกวันเริ่มดำเนินการ</div></div>
                <div class="col-md-6"><label for="finishDate" class="form-label">วันที่สิ้นสุด <span class="required-mark">*</span></label><input type="text" class="form-control date-picker" id="finishDate" name="finish_date" required><div class="form-text">เลือกวันสิ้นสุดการดำเนินงาน</div></div>
                <div class="col-md-6"><label for="startWorkTime" class="form-label">เวลาเริ่มงาน <span class="required-mark">*</span></label><input type="text" class="form-control time-picker" id="startWorkTime" name="start_work_time" required><div class="form-text">ระบุเวลาเริ่มงาน</div></div>
                <div class="col-md-6"><label for="finishWorkTime" class="form-label">เวลาสิ้นสุดงาน <span class="required-mark">*</span></label><input type="text" class="form-control time-picker" id="finishWorkTime" name="finish_work_time" required><div class="form-text">ระบุเวลาสิ้นสุดงาน</div></div>
            </div>`;
        document.getElementById("startDate").value = startDateValue;
        document.getElementById("finishDate").value = finishDateValue;
        document.getElementById("startWorkTime").value = startWorkTimeValue;
        document.getElementById("finishWorkTime").value = finishWorkTimeValue;
    }

    if (problemDetail) {
        const problemField = problemDetail.closest(".col-12");
        const categoryField = document.createElement("div");
        categoryField.className = "col-md-6";
        categoryField.innerHTML = '<label for="problemCategory" class="form-label">ประเภทปัญหา</label><select class="form-select" id="problemCategory" name="category"></select>';
        const categorySelect = categoryField.querySelector("select");

        const emptyOption = document.createElement("option");
        emptyOption.value = "-";
        emptyOption.textContent = "-";
        emptyOption.selected = selectedCategory === "-";
        categorySelect.appendChild(emptyOption);

        Object.entries(problemCategories).forEach(([value, label]) => {
            const option = document.createElement("option");
            option.value = value;
            option.textContent = value === "Customer" ? "ลูกค้า (Customer)" : label;
            option.selected = value === selectedCategory;
            categorySelect.appendChild(option);
        });

        const solutionField = document.getElementById("solution")?.closest(".col-12");
        const remarkField = document.getElementById("remark")?.closest(".col-12");
        problemField.className = "col-md-6";
        problemField.querySelector("label").textContent = "ปัญหาที่พบ";
        const problemInput = document.createElement("input");
        problemInput.type = "text";
        problemInput.className = "form-control";
        problemInput.id = "problem";
        problemInput.name = "problem";
        problemInput.value = problemDetail.value;
        problemInput.placeholder = "ระบุปัญหาที่พบ (ถ้ามี)";
        problemDetail.replaceWith(problemInput);

        const createWorkField = (id, name, label, placeholder, value) => {
            const field = document.createElement("div");
            field.className = "col-12";
            const fieldLabel = document.createElement("label");
            fieldLabel.className = "form-label";
            fieldLabel.htmlFor = id;
            fieldLabel.textContent = label;
            const textarea = document.createElement("textarea");
            textarea.className = "form-control";
            textarea.id = id;
            textarea.name = name;
            textarea.rows = 3;
            textarea.placeholder = placeholder;
            textarea.value = value;
            field.append(fieldLabel, textarea);
            return field;
        };
        const workDescriptionField = createWorkField("workDescription", "work_description", "รายละเอียดงาน", "อธิบายรายละเอียดของงาน", workDescriptionValue);
        const workActionField = createWorkField("workAction", "work_action", "การดำเนินงาน", "ระบุการดำเนินงาน", workActionValue);
        problemField.before(workDescriptionField, workActionField);

        if (solutionField) {
            solutionField.className = "col-md-6";
            solutionField.querySelector("label").textContent = "วิธีแก้ไขปัญหา";
            const solutionInput = solutionField.querySelector("textarea");
            if (solutionInput) solutionInput.placeholder = "ระบุวิธีแก้ไขปัญหา (ถ้ามี)";
        }

        if (remarkField) {
            remarkField.before(categoryField);
        } else if (solutionField) {
            solutionField.after(categoryField);
        }
    }

    const departmentLabel = document.querySelector('label[for="department"]');
    if (departmentLabel) departmentLabel.innerHTML = 'ทีม <span class="required-mark">*</span>';
    document.querySelectorAll('select[name="status"] option[value="cancelled"]').forEach((option) => option.remove());

    const locationSelect = document.getElementById("location");
    const locationField = locationSelect?.closest(".col-md-6");
    if (locationField) {
        const responsibleField = document.createElement("div");
        responsibleField.className = "col-md-6";
        responsibleField.innerHTML = '<label for="responsibleName" class="form-label">ชื่อผู้รับผิดชอบ</label><input type="text" class="form-control" id="responsibleName" name="responsible_name" placeholder="ระบุชื่อผู้รับผิดชอบ (หากไม่ระบุ ระบบจะแสดงทีม)">';
        responsibleField.querySelector("input").value = responsibleNameValue;
        locationField.after(responsibleField);
    }
    const otherLocationGroup = document.getElementById("otherLocationGroup");
    const otherLocation = document.getElementById("otherLocation");

    const updateOtherLocation = () => {
        const isOther = locationSelect.value === "__other__";
        otherLocationGroup.classList.toggle("d-none", !isOther);
        otherLocation.required = isOther;
    };

    locationSelect.addEventListener("change", updateOtherLocation);
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
