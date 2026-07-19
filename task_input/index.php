<?php
require_once __DIR__ . "/../auth/auth_check.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/constants.php";

function parse_thai_datetime(string $value): ?string
{
    $value = trim($value);
    if ($value === "") return null;
    if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2})(?:\s*น\.)?$/u', $value, $matches)) return null;
    [, $day, $month, $year, $hour, $minute] = $matches;
    $gregorian_year = (int) $year - 543;
    if (!checkdate((int) $month, (int) $day, $gregorian_year) || (int) $hour > 23 || (int) $minute > 59) return null;
    return sprintf("%04d-%02d-%02d %02d:%02d:00", $gregorian_year, $month, $day, $hour, $minute);
}

$task_role = strtoupper($_SESSION["role"] ?? "USER");
$task_department = $_SESSION["department"] ?? "";
$can_select_department = in_array($task_role, ["SUPER", "ADMIN"], true);
$form_error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $title = trim($_POST["title"] ?? "");
    $category = $_POST["category"] ?? "";
    $department = $can_select_department ? ($_POST["department"] ?? "") : $task_department;
    $problem = trim($_POST["problem"] ?? "");
    $solution = trim($_POST["solution"] ?? "");
    $status = $_POST["status"] ?? "pending";
    $remark = trim($_POST["remark"] ?? "");
    $start_time = parse_thai_datetime($_POST["start_time"] ?? "") ?? date("Y-m-d H:i:s");
    $finish_time = parse_thai_datetime($_POST["finish_time"] ?? "") ?? $start_time;

    if ($title === "" || $problem === "" || !array_key_exists($category, $problem_category_options) || !array_key_exists($status, $task_status_options) || !in_array($department, $departments, true)) {
        $form_error = "กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน";
    } else {
        $location = "";
        $created_by = (int) $_SESSION["user_id"];
        $stmt = $conn->prepare("INSERT INTO tasks (title, category, department, location, problem, solution, status, start_time, finish_time, remark, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssssi", $title, $category, $department, $location, $problem, $solution, $status, $start_time, $finish_time, $remark, $created_by);
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: index.php?saved=1");
            exit;
        }
        $stmt->close();
        $form_error = "ไม่สามารถบันทึกข้อมูลได้ กรุณาลองอีกครั้ง";
    }
}

require_once __DIR__ . "/../includes/app_header.php";
$active_nav = "task_input";
?>
<div class="app-shell d-flex">
    <?php require_once __DIR__ . "/../includes/app_sidebar.php"; ?>
    <main class="main-content flex-grow-1 p-4 p-lg-5">
        <div class="mb-4"><h1 class="page-heading h3 fw-bold mb-1">บันทึกงาน</h1><p class="page-subtitle mb-0">บันทึกข้อมูลเหตุขัดข้องของงาน IT / AV และแนวทางการแก้ไข</p></div>
        <?php if (isset($_GET["saved"])): ?><div class="alert alert-success">บันทึกข้อมูลงานเรียบร้อยแล้ว</div><?php endif; ?>
        <?php if ($form_error !== ""): ?><div class="alert alert-danger"><?php echo htmlspecialchars($form_error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>

        <form method="post" action="">
            <section class="card form-card mb-4">
                <div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-clipboard-plus"></i></span><h2 class="section-title mb-0">ข้อมูลการแจ้งงาน</h2></div>
                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-12"><label for="taskTitle" class="form-label">หัวข้องาน <span class="required-mark">*</span></label><input type="text" class="form-control" id="taskTitle" name="title" placeholder="เช่น Projector no signal" required></div>
                        <div class="col-md-6"><label for="department" class="form-label">แผนก</label><?php if ($can_select_department): ?><select class="form-select" id="department" name="department"><?php foreach ($departments as $department_option): ?><option value="<?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?>"<?php echo $task_department === $department_option ? " selected" : ""; ?>><?php echo htmlspecialchars($department_option, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select><div class="form-text">เลือกแผนกที่รับผิดชอบ</div><?php else: ?><input type="text" class="form-control bg-light" id="department" name="department" value="<?php echo htmlspecialchars($task_department, ENT_QUOTES, "UTF-8"); ?>" readonly><div class="form-text">กำหนดอัตโนมัติจากบัญชีผู้ใช้งาน</div><?php endif; ?></div>
                        <div class="col-md-6"><div class="form-label mb-1">สร้างเมื่อ</div><div class="small text-muted pt-2" id="createdAt" aria-live="polite">กำลังแสดงเวลาปัจจุบัน...</div><div class="form-text">ข้อมูลอ้างอิงแบบอ่านอย่างเดียว</div></div>
                        <div class="col-12"><label for="problemCategory" class="form-label">ประเภทปัญหา <span class="required-mark">*</span></label><select class="form-select" id="problemCategory" name="category" required><option selected disabled value="">เลือกประเภทปัญหา</option><?php foreach ($problem_category_options as $category_value => $category_label): ?><option value="<?php echo htmlspecialchars($category_value, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($category_label, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div>
                        <div class="col-12 d-none" id="otherCategoryGroup"><label for="otherCategory" class="form-label">โปรดระบุประเภทปัญหา <span class="required-mark">*</span></label><input type="text" class="form-control" id="otherCategory" placeholder="ระบุประเภทปัญหา"></div>
                    </div>
                </div>
            </section>

            <section class="card form-card mb-4">
                <div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-file-earmark-text"></i></span><h2 class="section-title mb-0">รายละเอียดปัญหา</h2></div>
                <div class="card-body p-4"><div class="row g-4"><div class="col-12"><label for="problemDetail" class="form-label">รายละเอียดปัญหา <span class="required-mark">*</span></label><textarea class="form-control" id="problemDetail" name="problem" rows="5" placeholder="อธิบายปัญหาที่พบหรือความต้องการ" required></textarea></div><div class="col-12"><label for="solution" class="form-label">วิธีแก้ไข / การดำเนินการ</label><textarea class="form-control" id="solution" name="solution" rows="5" placeholder="ระบุวิธีแก้ไขหรือแนวทางการดำเนินงาน"></textarea></div></div></div>
            </section>

            <section class="card form-card mb-4">
                <div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-clock-history"></i></span><h2 class="section-title mb-0">เวลาและสถานะงาน</h2></div>
                <div class="card-body p-4"><div class="row g-4"><div class="col-md-4"><label for="taskStatus" class="form-label">สถานะงาน</label><select class="form-select" id="taskStatus" name="status"><?php foreach ($task_status_options as $status_value => $status_label): ?><option value="<?php echo htmlspecialchars($status_value, ENT_QUOTES, "UTF-8"); ?>"<?php echo $status_value === "pending" ? " selected" : ""; ?>><?php echo htmlspecialchars($status_label, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label for="incidentTime" class="form-label">เวลาที่เกิดเหตุ</label><input type="text" class="form-control datetime-picker" id="incidentTime" name="start_time" placeholder="19/07/2569 10:18 น."><div class="form-text">พิมพ์เองหรือเลือกจากปฏิทิน</div></div><div class="col-md-4"><label for="finishTime" class="form-label">เวลาที่เสร็จ</label><input type="text" class="form-control datetime-picker" id="finishTime" name="finish_time" placeholder="19/07/2569 10:18 น."><div class="form-text">พิมพ์เองหรือเลือกจากปฏิทิน</div></div></div></div>
            </section>

            <section class="card form-card mb-4">
                <div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-journal-text"></i></span><h2 class="section-title mb-0">หมายเหตุ</h2></div>
                <div class="card-body p-4"><label for="remark" class="form-label">หมายเหตุเพิ่มเติม</label><textarea class="form-control" id="remark" name="remark" rows="4" placeholder="ระบุข้อมูลเพิ่มเติม (ถ้ามี)"></textarea></div>
            </section>

            <div class="form-actions d-flex flex-column flex-sm-row justify-content-end gap-2 pt-4"><button type="reset" class="btn btn-outline-secondary px-4"><i class="bi bi-arrow-counterclockwise me-1"></i>ล้างข้อมูล</button><button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i>บันทึกข้อมูล</button></div>
        </form>
    </main>
</div>
<script>
    // UI display only: format the current browser time for Thai users.
    const createdAt = document.getElementById('createdAt');
    const now = new Date();
    const thaiDate = new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(now);
    const thaiTime = new Intl.DateTimeFormat('th-TH', { hour: '2-digit', minute: '2-digit', hour12: false }).format(now);
    createdAt.textContent = `${thaiDate} ${thaiTime} น.`;

    const problemCategory = document.getElementById('problemCategory');
    const otherCategoryGroup = document.getElementById('otherCategoryGroup');
    problemCategory.addEventListener('change', () => otherCategoryGroup.classList.toggle('d-none', problemCategory.value !== 'Other'));

</script>
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>
