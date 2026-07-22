<?php
require_once __DIR__ . "/../auth/auth_check.php";
require_once __DIR__ . "/db.php";

// Every signed-in user may view Config, while only ADMIN may change data.
$current_role = strtoupper($_SESSION["role"] ?? "USER");
$is_admin = $current_role === "ADMIN";

if (empty($_SESSION["config_csrf_token"])) {
    $_SESSION["config_csrf_token"] = bin2hex(random_bytes(32));
}

$departments = ["IT", "AV"];
$roles = ["USER", "SUPER", "ADMIN"];
$current_user_id = (int) $_SESSION["user_id"];
$form_error = "";

function config_thai_date(string $value): string
{
    $time = strtotime($value);
    return date("d/m/", $time) . (date("Y", $time) + 543) . " " . date("H:i", $time) . " น.";
}

function config_redirect(string $notice): void
{
    header("Location: index.php?saved=" . urlencode($notice));
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!$is_admin) {
        http_response_code(403);
        $form_error = "คุณมีสิทธิ์ดูข้อมูลเท่านั้น";
    } elseif (!hash_equals($_SESSION["config_csrf_token"], $_POST["csrf_token"] ?? "")) {
        $form_error = "ไม่สามารถยืนยันคำขอได้ กรุณาลองใหม่อีกครั้ง";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "add_user") {
            $username = trim($_POST["username"] ?? "");
            $password = $_POST["password"] ?? "";
            $department = $_POST["department"] ?? "";
            $role = strtoupper($_POST["role"] ?? "");

            if ($username === "" || strlen($username) > 50 || strlen($password) < 8 || !in_array($department, $departments, true) || !in_array($role, $roles, true)) {
                $form_error = "กรุณากรอกข้อมูลผู้ใช้ให้ครบถ้วนและรหัสผ่านอย่างน้อย 8 ตัวอักษร";
            } else {
                $exists_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $exists_stmt->bind_param("s", $username);
                $exists_stmt->execute();
                $username_exists = (bool) $exists_stmt->get_result()->fetch_assoc();
                $exists_stmt->close();

                if ($username_exists) {
                    $form_error = "ชื่อผู้ใช้งานนี้มีอยู่ในระบบแล้ว";
                } else {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $add_stmt = $conn->prepare("INSERT INTO users (username, password, department, role, is_enabled) VALUES (?, ?, ?, ?, 1)");
                    $add_stmt->bind_param("ssss", $username, $password_hash, $department, $role);
                    $add_stmt->execute();
                    $add_stmt->close();
                    config_redirect("added");
                }
            }
        }

        if ($action === "edit_user") {
            $user_id = (int) ($_POST["user_id"] ?? 0);
            $username = trim($_POST["username"] ?? "");
            $new_password = $_POST["new_password"] ?? "";
            $department = $_POST["department"] ?? "";
            $role = strtoupper($_POST["role"] ?? "");
            $is_enabled = ($_POST["is_enabled"] ?? "1") === "1" ? 1 : 0;

            if ($user_id <= 0 || $username === "" || strlen($username) > 50 || !in_array($department, $departments, true) || !in_array($role, $roles, true) || ($new_password !== "" && strlen($new_password) < 8)) {
                $form_error = "ข้อมูลผู้ใช้ไม่ถูกต้อง";
            } elseif ($user_id === $current_user_id && ($role !== "ADMIN" || $is_enabled !== 1)) {
                $form_error = "ไม่สามารถปิดใช้งานหรือเอาสิทธิ์ ADMIN ของบัญชีที่กำลังใช้งานอยู่ได้";
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
                    $edit_stmt = $conn->prepare("UPDATE users SET username = ?, password = ?, department = ?, role = ?, is_enabled = ? WHERE id = ?");
                    $edit_stmt->bind_param("ssssii", $username, $password_hash, $department, $role, $is_enabled, $user_id);
                    $edit_stmt->execute();
                    $edit_stmt->close();
                    config_redirect("updated");
                } else {
                    $edit_stmt = $conn->prepare("UPDATE users SET username = ?, department = ?, role = ?, is_enabled = ? WHERE id = ?");
                    $edit_stmt->bind_param("sssii", $username, $department, $role, $is_enabled, $user_id);
                    $edit_stmt->execute();
                    $edit_stmt->close();
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
$user_result = $conn->query("SELECT id, username, department, role, is_enabled, created_at FROM users ORDER BY created_at DESC, id DESC");
while ($user = $user_result->fetch_assoc()) $users[] = $user;
$currently_active_user_count = $current_user_id > 0 ? 1 : 0;

$task_counts = ["total" => 0, "pending" => 0, "in_progress" => 0, "completed" => 0];
$task_count_result = $conn->query("SELECT status, COUNT(*) AS total FROM tasks WHERE is_deleted = 0 GROUP BY status");
while ($task_count = $task_count_result->fetch_assoc()) {
    $status = $task_count["status"];
    $task_counts["total"] += (int) $task_count["total"];
    if (isset($task_counts[$status])) $task_counts[$status] += (int) $task_count["total"];
}

$last_updated = null;
$last_updated_result = $conn->query("SELECT MAX(updated_at) AS last_updated FROM tasks WHERE is_deleted = 0");
if ($last_updated_row = $last_updated_result->fetch_assoc()) $last_updated = $last_updated_row["last_updated"];

$app_page_title = "Config | IT / AV Task Management System";
$active_nav = "config";
require_once __DIR__ . "/../includes/app_header.php";
?>
<div class="app-shell d-flex">
    <?php require_once __DIR__ . "/../includes/app_sidebar.php"; ?>
    <main class="main-content flex-grow-1 p-4 p-lg-5">
        <div class="mb-4"><h1 class="page-heading h3 fw-bold mb-1">ตั้งค่าระบบ</h1><p class="page-subtitle mb-0">การตั้งค่าระบบ IT / AV Task Management System</p></div>

        <?php if (isset($_GET["saved"])): ?><div class="alert alert-success">บันทึกข้อมูลเรียบร้อยแล้ว</div><?php endif; ?>
        <?php if ($form_error !== ""): ?><div class="alert alert-danger"><?php echo htmlspecialchars($form_error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>

        <section class="card form-card mb-4">
            <div class="card-header d-flex align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-people"></i></span><div><h2 class="section-title mb-0">จัดการผู้ใช้งาน</h2><p class="text-muted small mb-0">จัดการบัญชีผู้ใช้งานในระบบ</p></div></div>
                <?php if ($is_admin): ?>
                    <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="bi bi-person-plus me-1"></i>เพิ่มผู้ใช้งาน</button>
                <?php else: ?>
                    <button class="btn btn-outline-secondary text-muted" type="button" disabled><i class="bi bi-lock me-1"></i>ดูข้อมูลเท่านั้น</button>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0"><thead><tr><th class="ps-4 py-3">ชื่อผู้ใช้งาน</th><th>แผนก</th><th>บทบาท</th><th>สถานะ</th><th>วันที่สร้าง</th><th class="pe-4 text-end">การจัดการ</th></tr></thead><tbody>
                    <?php foreach ($users as $user): ?>
                        <tr><td class="ps-4 fw-semibold"><?php echo htmlspecialchars($user["username"], ENT_QUOTES, "UTF-8"); ?></td><td><?php echo htmlspecialchars($user["department"], ENT_QUOTES, "UTF-8"); ?></td><td><span class="badge text-bg-primary"><?php echo htmlspecialchars($user["role"], ENT_QUOTES, "UTF-8"); ?></span></td><td><?php if ((int) $user["is_enabled"] === 0): ?><span class="badge text-bg-secondary">ปิดใช้งาน</span><?php elseif ((int) $user["id"] === $current_user_id): ?><span class="badge text-bg-success">กำลังใช้งาน</span><?php else: ?><span class="badge text-bg-light text-dark border">พร้อมใช้งาน</span><?php endif; ?></td><td><?php echo config_thai_date($user["created_at"]); ?></td><td class="pe-4 text-end"><?php if ($is_admin): ?><button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo (int) $user["id"]; ?>">แก้ไข</button> <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#resetPasswordModal<?php echo (int) $user["id"]; ?>">รีเซ็ตรหัสผ่าน</button><form class="d-inline" method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["config_csrf_token"], ENT_QUOTES, "UTF-8"); ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="user_id" value="<?php echo (int) $user["id"]; ?>"><input type="hidden" name="is_enabled" value="<?php echo (int) $user["is_enabled"] === 1 ? 0 : 1; ?>"><button class="btn btn-sm <?php echo (int) $user["is_enabled"] === 1 ? "btn-outline-danger" : "btn-outline-success"; ?>" type="submit"<?php echo (int) $user["id"] === $current_user_id && (int) $user["is_enabled"] === 1 ? " disabled" : ""; ?>><?php echo (int) $user["is_enabled"] === 1 ? "ปิดใช้งาน" : "เปิดใช้งาน"; ?></button></form><?php else: ?><span class="text-muted small"><i class="bi bi-lock me-1"></i>ดูข้อมูลเท่านั้น</span><?php endif; ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
            </div>
        </section>

        <section class="card form-card">
            <div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-info-circle"></i></span><div><h2 class="section-title mb-0">ข้อมูลระบบ</h2><p class="text-muted small mb-0">ข้อมูลปัจจุบันของระบบ</p></div></div>
            <div class="card-body p-4"><div class="row g-4"><div class="col-sm-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">เวอร์ชันระบบ</div><div class="fw-bold mt-1">1.0</div></div></div><div class="col-sm-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">จำนวนผู้ใช้งานทั้งหมด</div><div class="fw-bold mt-1"><?php echo count($users); ?></div></div></div><div class="col-sm-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">จำนวนงานทั้งหมด</div><div class="fw-bold mt-1"><?php echo $task_counts["total"]; ?></div></div></div><div class="col-sm-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">สถานะการเชื่อมต่อฐานข้อมูล</div><div class="fw-bold text-success mt-1"><i class="bi bi-check-circle me-1"></i>เชื่อมต่อแล้ว</div></div></div><div class="col-sm-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">งานรอดำเนินการ</div><div class="fw-bold mt-1"><?php echo $task_counts["pending"]; ?></div></div></div><div class="col-sm-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">งานกำลังดำเนินการ</div><div class="fw-bold mt-1"><?php echo $task_counts["in_progress"]; ?></div></div></div><div class="col-sm-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">งานเสร็จสิ้น</div><div class="fw-bold mt-1"><?php echo $task_counts["completed"]; ?></div></div></div><div class="col-sm-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small">วันที่อัปเดตงานล่าสุด</div><div class="fw-bold mt-1"><?php echo $last_updated ? config_thai_date($last_updated) : "-"; ?></div></div></div></div></div>
        </section>
    </main>
</div>

<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post"><div class="modal-header"><h2 class="modal-title fs-5">เพิ่มผู้ใช้งาน</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div><div class="modal-body"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["config_csrf_token"], ENT_QUOTES, "UTF-8"); ?>"><input type="hidden" name="action" value="add_user"><div class="mb-3"><label class="form-label" for="addUsername">ชื่อผู้ใช้งาน</label><input class="form-control" id="addUsername" name="username" maxlength="50" required></div><div class="mb-3"><label class="form-label" for="addPassword">รหัสผ่าน</label><input class="form-control" type="password" id="addPassword" name="password" minlength="8" required><div class="form-text">กำหนดรหัสผ่านได้ตามต้องการ (อย่างน้อย 8 ตัวอักษร)</div></div><div class="mb-3"><label class="form-label" for="addDepartment">แผนก</label><select class="form-select" id="addDepartment" name="department"><?php foreach ($departments as $department): ?><option value="<?php echo $department; ?>"><?php echo $department; ?></option><?php endforeach; ?></select></div><div><label class="form-label" for="addRole">บทบาท</label><select class="form-select" id="addRole" name="role"><?php foreach ($roles as $role): ?><option value="<?php echo $role; ?>"><?php echo $role; ?></option><?php endforeach; ?></select></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-primary" type="submit">เพิ่มผู้ใช้งาน</button></div></form></div></div>

<?php foreach ($users as $user): ?>
<div class="modal fade" id="editUserModal<?php echo (int) $user["id"]; ?>" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post"><div class="modal-header"><h2 class="modal-title fs-5">แก้ไขผู้ใช้งาน: <?php echo htmlspecialchars($user["username"], ENT_QUOTES, "UTF-8"); ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div><div class="modal-body"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["config_csrf_token"], ENT_QUOTES, "UTF-8"); ?>"><input type="hidden" name="action" value="edit_user"><input type="hidden" name="user_id" value="<?php echo (int) $user["id"]; ?>"><div class="mb-3"><label class="form-label">ชื่อผู้ใช้งาน</label><input class="form-control" name="username" value="<?php echo htmlspecialchars($user["username"], ENT_QUOTES, "UTF-8"); ?>" maxlength="50" required></div><div class="mb-3"><label class="form-label">รหัสผ่านใหม่</label><input class="form-control" type="password" name="new_password" minlength="8"><div class="form-text">เว้นว่างไว้หากไม่ต้องการเปลี่ยนรหัสผ่าน</div></div><div class="mb-3"><label class="form-label">แผนก</label><select class="form-select" name="department"><?php foreach ($departments as $department): ?><option value="<?php echo $department; ?>"<?php echo $user["department"] === $department ? " selected" : ""; ?>><?php echo $department; ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label">บทบาท</label><select class="form-select" name="role"><?php foreach ($roles as $role): ?><option value="<?php echo $role; ?>"<?php echo $user["role"] === $role ? " selected" : ""; ?>><?php echo $role; ?></option><?php endforeach; ?></select></div><div><label class="form-label">สถานะ</label><select class="form-select" name="is_enabled"><option value="1"<?php echo (int) $user["is_enabled"] === 1 ? " selected" : ""; ?>>ใช้งาน</option><option value="0"<?php echo (int) $user["is_enabled"] === 0 ? " selected" : ""; ?>>ปิดใช้งาน</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-primary" type="submit">บันทึกการแก้ไข</button></div></form></div></div>
<div class="modal fade" id="resetPasswordModal<?php echo (int) $user["id"]; ?>" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post"><div class="modal-header"><h2 class="modal-title fs-5">รีเซ็ตรหัสผ่าน: <?php echo htmlspecialchars($user["username"], ENT_QUOTES, "UTF-8"); ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div><div class="modal-body"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["config_csrf_token"], ENT_QUOTES, "UTF-8"); ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?php echo (int) $user["id"]; ?>"><div class="mb-3"><label class="form-label">รหัสผ่านใหม่</label><input class="form-control" type="password" name="new_password" minlength="8" required></div><div><label class="form-label">ยืนยันรหัสผ่านใหม่</label><input class="form-control" type="password" name="confirm_password" minlength="8" required></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button><button class="btn btn-primary" type="submit">รีเซ็ตรหัสผ่าน</button></div></form></div></div>
<?php endforeach; ?>

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
<script>
    // The system summary shows only the account currently active in this session.
    document.querySelectorAll('.card .text-muted.small').forEach((label) => {
        if (label.textContent.trim() !== 'จำนวนผู้ใช้งานทั้งหมด') return;
        const countValue = label.nextElementSibling;
        label.textContent = 'จำนวนผู้ใช้งานที่กำลังใช้งาน';
        if (countValue) countValue.textContent = '<?php echo $currently_active_user_count; ?>';
    });
    const addUserTeamLabel = document.querySelector('label[for="addDepartment"]');
    if (addUserTeamLabel) addUserTeamLabel.textContent = 'ทีม';
    const userTableTeamHeader = document.querySelector('.table thead th:nth-child(2)');
    if (userTableTeamHeader) userTableTeamHeader.textContent = 'ทีม';

    const hiddenSystemInfoLabels = new Set([
        'งานรอดำเนินการ',
        'งานกำลังดำเนินการ',
        'งานเสร็จสิ้น',
        'วันที่อัปเดตงานล่าสุด'
    ]);
    document.querySelectorAll('.card .text-muted.small').forEach((label) => {
        if (hiddenSystemInfoLabels.has(label.textContent.trim())) {
            label.closest('.col-sm-6')?.remove();
        }
    });
</script>
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>
