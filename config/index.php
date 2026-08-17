<?php
require_once __DIR__ . "/../auth/auth_check.php";
require_once __DIR__ . "/../auth/authorization.php";
require_once __DIR__ . "/../auth/remember_tokens.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/../includes/helpers.php";

// Every authenticated user may change their own password here. Account management
// and system information remain visible to ADMIN only.
$current_role = current_role();
$is_admin = can_manage_users();

if (empty($_SESSION["config_csrf_token"])) {
    $_SESSION["config_csrf_token"] = bin2hex(random_bytes(32));
}

$departments = ["IT", "AV"];
$roles = ["USER", "SUPER", "ADMIN"];
$current_user_id = (int) $_SESSION["user_id"];
$form_error = "";

function config_thai_date(string $value): string
{
    return format_thai_date_time($value);
}

function config_redirect(string $notice): void
{
    header("Location: index.php?saved=" . urlencode($notice));
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($_SESSION["config_csrf_token"], $_POST["csrf_token"] ?? "")) {
        $form_error = "ไม่สามารถยืนยันคำขอได้ กรุณาลองใหม่อีกครั้ง";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "change_own_password") {
            $current_password = $_POST["current_password"] ?? "";
            $new_password = $_POST["new_password"] ?? "";
            $confirm_password = $_POST["confirm_password"] ?? "";
            $password_stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
            $password_stmt->bind_param("i", $current_user_id);
            $password_stmt->execute();
            $password_row = $password_stmt->get_result()->fetch_assoc();
            $password_stmt->close();

            if (!$password_row || !password_verify($current_password, $password_row["password"])) {
                $form_error = "รหัสผ่านเดิมไม่ถูกต้อง";
            } elseif (strlen($new_password) < 8 || !hash_equals($new_password, $confirm_password)) {
                $form_error = "รหัสผ่านใหม่ต้องตรงกันและมีอย่างน้อย 8 ตัวอักษร";
            } else {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $save_password_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $save_password_stmt->bind_param("si", $new_hash, $current_user_id);
                $save_password_stmt->execute();
                $save_password_stmt->close();
                delete_user_remember_tokens($conn, $current_user_id);
                expire_remember_cookie();
                config_redirect("password_changed");
            }
        } elseif (!$is_admin) {
            http_response_code(403);
            $form_error = "คุณไม่มีสิทธิ์จัดการบัญชีผู้ใช้งาน";
        } elseif ($action === "create_user") {
            $username = trim($_POST["username"] ?? "");
            $full_name = trim($_POST["full_name"] ?? "");
            $new_password = $_POST["new_password"] ?? "";
            $confirm_password = $_POST["confirm_password"] ?? "";
            $department = $_POST["department"] ?? "";
            $role = strtoupper($_POST["role"] ?? "");
            $is_enabled = ($_POST["is_enabled"] ?? "1") === "1" ? 1 : 0;

            if ($username === "" || strlen($username) > 50 || strlen($full_name) > 120 || strlen($new_password) < 8 || !hash_equals($new_password, $confirm_password) || !in_array($department, $departments, true) || !in_array($role, $roles, true)) {
                $form_error = "ข้อมูลผู้ใช้งานไม่ถูกต้อง กรุณาตรวจสอบชื่อ รหัสผ่าน ทีม และบทบาท";
            } else {
                $exists_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $exists_stmt->bind_param("s", $username);
                $exists_stmt->execute();
                $username_exists = (bool) $exists_stmt->get_result()->fetch_assoc();
                $exists_stmt->close();

                if ($username_exists) {
                    $form_error = "ชื่อผู้ใช้งานนี้มีอยู่ในระบบแล้ว";
                } else {
                    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $create_stmt = $conn->prepare("INSERT INTO users (username, full_name, password, department, role, is_enabled, is_approved) VALUES (?, NULLIF(?, ''), ?, ?, ?, ?, 1)");
                    $create_stmt->bind_param("sssssi", $username, $full_name, $password_hash, $department, $role, $is_enabled);
                    $created = $create_stmt->execute();
                    $create_stmt->close();
                    if ($created) {
                        config_redirect("created");
                    }
                    $form_error = "ไม่สามารถสร้างบัญชีผู้ใช้งานได้";
                }
            }
        } elseif ($action === "edit_user") {
            $user_id = (int) ($_POST["user_id"] ?? 0);
            $username = trim($_POST["username"] ?? "");
            $full_name = trim($_POST["full_name"] ?? "");
            $new_password = $_POST["new_password"] ?? "";
            $department = $_POST["department"] ?? "";
            $role = strtoupper($_POST["role"] ?? "");
            $is_enabled = ($_POST["is_enabled"] ?? "1") === "1" ? 1 : 0;

            if ($user_id <= 0 || $username === "" || strlen($username) > 50 || strlen($full_name) > 120 || !in_array($department, $departments, true) || !in_array($role, $roles, true) || ($new_password !== "" && strlen($new_password) < 8)) {
                $form_error = "ข้อมูลผู้ใช้ไม่ถูกต้อง";
            } elseif ($user_id === $current_user_id && ($role !== "ADMIN" || $is_enabled !== 1)) {
                $form_error = "ไม่สามารถปิดใช้งานหรือลดสิทธิ์บัญชี ADMIN ที่กำลังใช้งานอยู่ได้";
            } else {
                $exists_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
                $exists_stmt->bind_param("si", $username, $user_id);
                $exists_stmt->execute();
                $username_exists = (bool) $exists_stmt->get_result()->fetch_assoc();
                $exists_stmt->close();

                if ($username_exists) {
                    $form_error = "ชื่อผู้ใช้งานนี้มีอยู่ในระบบแล้ว";
                } elseif ($new_password !== "") {
                    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $edit_stmt = $conn->prepare("UPDATE users SET username = ?, full_name = NULLIF(?, ''), password = ?, department = ?, role = ?, is_enabled = ?, is_approved = 1 WHERE id = ?");
                    $edit_stmt->bind_param("sssssii", $username, $full_name, $password_hash, $department, $role, $is_enabled, $user_id);
                    $edit_stmt->execute();
                    $edit_stmt->close();
                    delete_user_remember_tokens($conn, $user_id);
                    config_redirect("updated");
                } else {
                    $edit_stmt = $conn->prepare("UPDATE users SET username = ?, full_name = NULLIF(?, ''), department = ?, role = ?, is_enabled = ?, is_approved = 1 WHERE id = ?");
                    $edit_stmt->bind_param("ssssii", $username, $full_name, $department, $role, $is_enabled, $user_id);
                    $edit_stmt->execute();
                    $edit_stmt->close();
                    if ($is_enabled !== 1) {
                        delete_user_remember_tokens($conn, $user_id);
                    }
                    config_redirect("updated");
                }
            }
        }

        if ($action === "reset_password") {
            $user_id = (int) ($_POST["user_id"] ?? 0);
            $new_password = $_POST["new_password"] ?? "";
            $confirm_password = $_POST["confirm_password"] ?? "";

            if ($user_id <= 0 || strlen($new_password) < 8 || !hash_equals($new_password, $confirm_password)) {
                $form_error = "รหัสผ่านต้องตรงกันและมีอย่างน้อย 8 ตัวอักษร";
            } else {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $password_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $password_stmt->bind_param("si", $password_hash, $user_id);
                $password_stmt->execute();
                $password_stmt->close();
                delete_user_remember_tokens($conn, $user_id);
                config_redirect("password_reset");
            }
        }

        if ($action === "toggle_status") {
            $user_id = (int) ($_POST["user_id"] ?? 0);
            $is_enabled = (int) ($_POST["is_enabled"] ?? 0) === 1 ? 1 : 0;

            if ($user_id <= 0) {
                $form_error = "ไม่พบผู้ใช้ที่ต้องการแก้ไข";
            } elseif ($user_id === $current_user_id && $is_enabled === 0) {
                $form_error = "ไม่สามารถปิดใช้งานบัญชีที่กำลังใช้งานอยู่ได้";
            } else {
                $status_stmt = $conn->prepare("UPDATE users SET is_enabled = ? WHERE id = ?");
                $status_stmt->bind_param("ii", $is_enabled, $user_id);
                $status_stmt->execute();
                $status_stmt->close();
                if ($is_enabled !== 1) {
                    delete_user_remember_tokens($conn, $user_id);
                }
                config_redirect($is_enabled === 1 ? "enabled" : "disabled");
            }
        }

        if ($action === "delete_user") {
            $user_id = (int) ($_POST["user_id"] ?? 0);

            if ($user_id <= 0) {
                $form_error = "ไม่พบผู้ใช้งานที่ต้องการลบ";
            } elseif ($user_id === $current_user_id) {
                $form_error = "ไม่สามารถลบบัญชีที่กำลังใช้งานอยู่ได้";
            } else {
                // Keep task history intact: an account with task records must be disabled instead.
                $task_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tasks WHERE created_by = ?");
                $task_stmt->bind_param("i", $user_id);
                $task_stmt->execute();
                $task_total = (int) ($task_stmt->get_result()->fetch_assoc()["total"] ?? 0);
                $task_stmt->close();

                if ($task_total > 0) {
                    $form_error = "ไม่สามารถลบผู้ใช้งานที่มีประวัติงานได้ กรุณาปิดใช้งานบัญชีแทน";
                } else {
                    $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                    $delete_stmt->bind_param("i", $user_id);
                    $delete_stmt->execute();
                    $delete_stmt->close();
                    config_redirect("deleted");
                }
            }
        }
    }
}

$users = [];
$currently_active_user_count = 0;
$task_total = 0;
if ($is_admin) {
    $user_result = $conn->query("SELECT id, username, full_name, email, department, role, is_enabled, created_at FROM users ORDER BY created_at DESC, id DESC");
    while ($user = $user_result->fetch_assoc()) $users[] = $user;
    $active_user_result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM users
         WHERE is_enabled = 1
           AND last_activity_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
    );
    $currently_active_user_count = (int) ($active_user_result->fetch_assoc()["total"] ?? 0);
    $task_total_result = $conn->query("SELECT COUNT(*) AS total FROM tasks WHERE is_deleted = 0");
    $task_total = (int) ($task_total_result->fetch_assoc()["total"] ?? 0);
}

$app_page_title = ($is_admin ? "ตั้งค่าระบบ" : "ตั้งค่าบัญชี") . " | IT / AV Task Management System";
$active_nav = "config";
require_once __DIR__ . "/../includes/app_header.php";
?>
<div class="app-shell d-flex">
    <?php require_once __DIR__ . "/../includes/app_sidebar.php"; ?>
    <main class="main-content flex-grow-1 p-4 p-lg-5">
        <div class="mb-4"><h1 class="page-heading h3 fw-bold mb-1"><?php echo $is_admin ? "ตั้งค่าระบบ" : "ตั้งค่าบัญชี"; ?></h1><p class="page-subtitle mb-0"><?php echo $is_admin ? "System Settings และการจัดการผู้ใช้งาน" : "เปลี่ยนรหัสผ่านสำหรับบัญชีของคุณ"; ?></p></div>

        <?php if (isset($_GET["saved"])): ?><div class="alert alert-success">บันทึกข้อมูลเรียบร้อยแล้ว</div><?php endif; ?>
        <?php if ($form_error !== ""): ?><div class="alert alert-danger"><?php echo htmlspecialchars($form_error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>

        <section class="card form-card mb-4">
            <div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-key"></i></span><div><h2 class="section-title mb-0">เปลี่ยนรหัสผ่านของฉัน</h2><p class="text-muted small mb-0">กรอกรหัสผ่านเดิมและตั้งรหัสผ่านใหม่อย่างน้อย 8 ตัวอักษร</p></div></div>
            <form class="card-body p-4" method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["config_csrf_token"], ENT_QUOTES, "UTF-8"); ?>"><input type="hidden" name="action" value="change_own_password"><div class="row g-3"><div class="col-md-4"><label class="form-label" for="current_password">รหัสผ่านเดิม</label><input class="form-control" type="password" id="current_password" name="current_password" autocomplete="current-password" required></div><div class="col-md-4"><label class="form-label" for="new_password">รหัสผ่านใหม่</label><input class="form-control" type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password" required></div><div class="col-md-4"><label class="form-label" for="confirm_password">ยืนยันรหัสผ่านใหม่</label><input class="form-control" type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required></div></div><div class="mt-4 text-end"><button class="btn btn-primary" type="submit"><i class="bi bi-key-fill me-1"></i>บันทึกรหัสผ่านใหม่</button></div></form>
        </section>

        <?php if ($is_admin): ?>
        <section class="card form-card mb-4">
            <div class="card-header d-flex align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-people"></i></span><div><h2 class="section-title mb-0">จัดการผู้ใช้งาน</h2><p class="text-muted small mb-0">จัดการบัญชีผู้ใช้งานในระบบ</p></div></div>
                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#createUserModal"><i class="bi bi-person-plus me-1"></i>เพิ่มผู้ใช้งาน</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0"><thead><tr><th class="ps-4 py-3">ชื่อผู้ใช้งาน</th><th>ทีม</th><th>บทบาท</th><th>สถานะ</th><th>วันที่สร้าง</th><th class="pe-4 text-end">การจัดการ</th></tr></thead><tbody>
                    <?php foreach ($users as $user): ?>
                        <tr><td class="ps-4 fw-semibold"><div><?php echo htmlspecialchars($user["username"], ENT_QUOTES, "UTF-8"); ?></div><?php if (!empty($user["full_name"])): ?><small class="text-muted"><?php echo htmlspecialchars($user["full_name"], ENT_QUOTES, "UTF-8"); ?></small><?php endif; ?></td><td><?php echo htmlspecialchars($user["department"], ENT_QUOTES, "UTF-8"); ?></td><td><span class="badge text-bg-primary" title="Role: <?php echo htmlspecialchars($user["role"], ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars(ui_role_label((string) $user["role"]), ENT_QUOTES, "UTF-8"); ?></span></td><td><?php if ((int) $user["is_enabled"] === 0): ?><span class="badge text-bg-secondary">ปิดใช้งาน</span><?php elseif ((int) $user["id"] === $current_user_id): ?><span class="badge text-bg-success">กำลังใช้งาน</span><?php else: ?><span class="badge text-bg-light text-dark border">พร้อมใช้งาน</span><?php endif; ?></td><td><?php echo config_thai_date($user["created_at"]); ?></td><td class="pe-4 text-end"><button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo (int) $user["id"]; ?>">แก้ไข</button> <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#resetPasswordModal<?php echo (int) $user["id"]; ?>">รีเซ็ตรหัสผ่าน</button><form class="d-inline" method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["config_csrf_token"], ENT_QUOTES, "UTF-8"); ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="user_id" value="<?php echo (int) $user["id"]; ?>"><input type="hidden" name="is_enabled" value="<?php echo (int) $user["is_enabled"] === 1 ? 0 : 1; ?>"><button class="btn btn-sm <?php echo (int) $user["is_enabled"] === 1 ? "btn-outline-danger" : "btn-outline-success"; ?>" type="submit"<?php echo (int) $user["id"] === $current_user_id && (int) $user["is_enabled"] === 1 ? " disabled" : ""; ?>><?php echo (int) $user["is_enabled"] === 1 ? "ปิดใช้งาน" : "เปิดใช้งาน"; ?></button></form></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
            </div>
        </section>

        <section class="card form-card">
            <div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-info-circle"></i></span><div><h2 class="section-title mb-0">ข้อมูลระบบ</h2><p class="text-muted small mb-0">ข้อมูลปัจจุบันของระบบ</p></div></div>
            <div class="card-body p-4"><div class="row g-4"><div class="col-sm-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">เวอร์ชันระบบ</div><div class="fw-bold mt-1">1.0</div></div></div><div class="col-sm-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">ผู้ใช้ที่ใช้งานใน 5 นาทีล่าสุด</div><div class="fw-bold mt-1"><?php echo $currently_active_user_count; ?></div></div></div><div class="col-sm-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">จำนวนงานทั้งหมด</div><div class="fw-bold mt-1"><?php echo $task_total; ?></div></div></div><div class="col-sm-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">สถานะการเชื่อมต่อฐานข้อมูล</div><div class="fw-bold text-success mt-1"><i class="bi bi-check-circle me-1"></i>เชื่อมต่อแล้ว</div></div></div></div></div>
        </section>
        <?php endif; ?>
    </main>
</div>


<?php if ($is_admin): ?>
<div class="modal fade" id="createUserModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post"><div class="modal-header"><h2 class="modal-title fs-5">เพิ่มผู้ใช้งาน</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div><div class="modal-body"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["config_csrf_token"], ENT_QUOTES, "UTF-8"); ?>"><input type="hidden" name="action" value="create_user"><div class="mb-3"><label class="form-label">ชื่อผู้ใช้งาน</label><input class="form-control" name="username" maxlength="50" autocomplete="username" required></div><div class="mb-3"><label class="form-label">ชื่อ-นามสกุล</label><input class="form-control" name="full_name" maxlength="120"></div><div class="row g-3"><div class="col-md-6"><label class="form-label">รหัสผ่านเริ่มต้น</label><input class="form-control" type="password" name="new_password" minlength="8" autocomplete="new-password" required></div><div class="col-md-6"><label class="form-label">ยืนยันรหัสผ่าน</label><input class="form-control" type="password" name="confirm_password" minlength="8" autocomplete="new-password" required></div></div><div class="row g-3 mt-0"><div class="col-md-6"><label class="form-label">ทีม</label><select class="form-select" name="department"><?php foreach ($departments as $department): ?><option value="<?php echo $department; ?>"><?php echo $department; ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">บทบาท</label><select class="form-select" name="role"><?php foreach ($roles as $role): ?><option value="<?php echo $role; ?>"><?php echo htmlspecialchars(ui_role_label($role), ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div></div><div class="mt-3"><label class="form-label">สถานะ</label><select class="form-select" name="is_enabled"><option value="1">ใช้งาน</option><option value="0">ปิดใช้งาน</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-primary" type="submit"><i class="bi bi-person-plus me-1"></i>สร้างบัญชี</button></div></form></div></div>
<?php foreach ($users as $user): ?>
<div class="modal fade" id="editUserModal<?php echo (int) $user["id"]; ?>" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post"><div class="modal-header"><h2 class="modal-title fs-5">แก้ไขผู้ใช้งาน: <?php echo htmlspecialchars($user["username"], ENT_QUOTES, "UTF-8"); ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div><div class="modal-body"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["config_csrf_token"], ENT_QUOTES, "UTF-8"); ?>"><input type="hidden" name="action" value="edit_user"><input type="hidden" name="user_id" value="<?php echo (int) $user["id"]; ?>"><div class="mb-3"><label class="form-label">ชื่อผู้ใช้งาน</label><input class="form-control" name="username" value="<?php echo htmlspecialchars($user["username"], ENT_QUOTES, "UTF-8"); ?>" maxlength="50" required></div><div class="mb-3"><label class="form-label">ชื่อ-นามสกุล</label><input class="form-control" name="full_name" value="<?php echo htmlspecialchars($user["full_name"] ?? "", ENT_QUOTES, "UTF-8"); ?>" maxlength="120"></div><div class="mb-3"><label class="form-label">รหัสผ่านใหม่</label><input class="form-control" type="password" name="new_password" minlength="8"><div class="form-text">เว้นว่างไว้หากไม่ต้องการเปลี่ยนรหัสผ่าน</div></div><div class="mb-3"><label class="form-label">แผนก</label><select class="form-select" name="department"><?php foreach ($departments as $department): ?><option value="<?php echo $department; ?>"<?php echo $user["department"] === $department ? " selected" : ""; ?>><?php echo $department; ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label">บทบาท</label><select class="form-select" name="role"><?php foreach ($roles as $role): ?><option value="<?php echo $role; ?>"<?php echo $user["role"] === $role ? " selected" : ""; ?>><?php echo htmlspecialchars(ui_role_label($role), ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></div><div><label class="form-label">สถานะ</label><select class="form-select" name="is_enabled"><option value="1"<?php echo (int) $user["is_enabled"] === 1 ? " selected" : ""; ?>>ใช้งาน</option><option value="0"<?php echo (int) $user["is_enabled"] === 0 ? " selected" : ""; ?>>ปิดใช้งาน</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-primary" type="submit">บันทึกการแก้ไข</button></div></form></div></div>
<div class="modal fade" id="resetPasswordModal<?php echo (int) $user["id"]; ?>" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post"><div class="modal-header"><h2 class="modal-title fs-5">รีเซ็ตรหัสผ่าน: <?php echo htmlspecialchars($user["username"], ENT_QUOTES, "UTF-8"); ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div><div class="modal-body"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["config_csrf_token"], ENT_QUOTES, "UTF-8"); ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?php echo (int) $user["id"]; ?>"><div class="mb-3"><label class="form-label">รหัสผ่านใหม่</label><input class="form-control" type="password" name="new_password" minlength="8" required></div><div><label class="form-label">ยืนยันรหัสผ่านใหม่</label><input class="form-control" type="password" name="confirm_password" minlength="8" required></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-primary" type="submit">รีเซ็ตรหัสผ่าน</button></div></form></div></div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($is_admin): ?>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const token = <?php echo json_encode($_SESSION['config_csrf_token']); ?>;
        const currentUserId = <?php echo $current_user_id; ?>;
        const modalElement = document.createElement('div');
        modalElement.className = 'modal fade';
        modalElement.id = 'deleteUserModal';
        modalElement.tabIndex = -1;
        modalElement.setAttribute('aria-hidden', 'true');
        modalElement.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="post">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5">ยืนยันการลบผู้ใช้งาน</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="${token}">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" id="deleteUserId">
                        <p class="mb-1">ต้องการลบผู้ใช้งาน <strong id="deleteUserName"></strong> หรือไม่?</p>
                        <p class="small text-muted mb-0">การลบไม่สามารถย้อนกลับได้ และลบได้เฉพาะผู้ใช้งานที่ยังไม่มีประวัติงาน</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>ลบผู้ใช้งาน</button>
                    </div>
                </form>
            </div>`;
        document.body.appendChild(modalElement);

        document.querySelectorAll('[data-bs-target^="#editUserModal"]').forEach((editButton) => {
            const match = editButton.dataset.bsTarget.match(/(\d+)$/);
            if (!match) return;

            const userId = Number(match[1]);
            const actionCell = editButton.closest('td');
            const username = editButton.closest('tr')?.querySelector('td')?.textContent.trim() || '';
            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'btn btn-sm btn-outline-danger';
            deleteButton.innerHTML = '<i class="bi bi-trash me-1"></i>ลบ';
            deleteButton.disabled = userId === currentUserId;
            deleteButton.title = deleteButton.disabled ? 'ไม่สามารถลบบัญชีที่กำลังใช้งานอยู่ได้' : 'ลบผู้ใช้งาน';
            deleteButton.addEventListener('click', () => {
                modalElement.querySelector('#deleteUserId').value = String(userId);
                modalElement.querySelector('#deleteUserName').textContent = username;
                bootstrap.Modal.getOrCreateInstance(modalElement).show();
            });
            actionCell.append(' ', deleteButton);
        });
    });
</script>
<?php endif; ?>
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>
