<?php
require_once __DIR__ . "/../auth/auth_check.php";
require_once __DIR__ . "/../auth/remember_tokens.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/helpers.php";

if (empty($_SESSION["account_settings_csrf_token"])) {
    $_SESSION["account_settings_csrf_token"] = bin2hex(random_bytes(32));
}

$current_user_id = (int) $_SESSION["user_id"];
$form_error = "";
$password_modal_open = false;

$profile_stmt = $conn->prepare("SELECT username, full_name, department, role, profile_image FROM users WHERE id = ? LIMIT 1");
$profile_stmt->bind_param("i", $current_user_id);
$profile_stmt->execute();
$account_profile = $profile_stmt->get_result()->fetch_assoc();
$profile_stmt->close();

function account_settings_redirect(string $notice): void
{
    header("Location: index.php?saved=" . urlencode($notice));
    exit;
}

function account_password_meets_policy(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[a-z]/', $password) === 1
        && preg_match('/[A-Z]/', $password) === 1
        && preg_match('/[^A-Za-z0-9\x{0E00}-\x{0E7F}\s]/u', $password) === 1;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = is_string($_POST["action"] ?? null) ? $_POST["action"] : "";
    $password_modal_open = $action === "change_own_password";

    if (!hash_equals($_SESSION["account_settings_csrf_token"], $_POST["csrf_token"] ?? "")) {
        $form_error = "ไม่สามารถยืนยันคำขอได้ กรุณาลองใหม่อีกครั้ง";
    } elseif ($action === "update_own_profile") {
        $username = trim((string) ($_POST["username"] ?? ""));
        $full_name = trim((string) ($_POST["full_name"] ?? ""));
        $account_profile["username"] = $username;
        $account_profile["full_name"] = $full_name;

        if ($username === "" || strlen($username) > 50 || mb_strlen($full_name) > 120) {
            $form_error = "กรุณาระบุชื่อผู้ใช้งานไม่เกิน 50 ตัวอักษร และชื่อ-นามสกุลไม่เกิน 120 ตัวอักษร";
        } else {
            $username_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
            $username_stmt->bind_param("si", $username, $current_user_id);
            $username_stmt->execute();
            $username_used = (bool) $username_stmt->get_result()->fetch_assoc();
            $username_stmt->close();

            if ($username_used) {
                $form_error = "ชื่อผู้ใช้งานนี้มีอยู่ในระบบแล้ว";
            } else {
                $stored_image = (string) ($account_profile["profile_image"] ?? "");
                $old_stored_image = $stored_image;
                $new_profile_absolute_path = null;

                if (isset($_FILES["profile_image"]) && $_FILES["profile_image"]["error"] !== UPLOAD_ERR_NO_FILE) {
                    $image = $_FILES["profile_image"];
                    $allowed_mimes = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
                    $mime = $image["error"] === UPLOAD_ERR_OK ? (new finfo(FILEINFO_MIME_TYPE))->file($image["tmp_name"]) : "";

                    if ($image["error"] !== UPLOAD_ERR_OK || $image["size"] > 20 * 1024 * 1024 || !isset($allowed_mimes[$mime])) {
                        $form_error = "รูปโปรไฟล์ต้องเป็น JPG, PNG หรือ WebP ขนาดไม่เกิน 20 MB";
                    } else {
                        $upload_dir = __DIR__ . "/../uploads/profile";
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        $file_name = bin2hex(random_bytes(16)) . "." . $allowed_mimes[$mime];
                        if (!move_uploaded_file($image["tmp_name"], $upload_dir . "/" . $file_name)) {
                            $form_error = "ไม่สามารถบันทึกรูปโปรไฟล์ได้";
                        } else {
                            $new_profile_absolute_path = $upload_dir . "/" . $file_name;
                            $stored_image = "uploads/profile/" . $file_name;
                        }
                    }
                }

                if ($form_error === "") {
                    $save_stmt = $conn->prepare("UPDATE users SET username = ?, full_name = NULLIF(?, ''), profile_image = ? WHERE id = ?");
                    $save_stmt->bind_param("sssi", $username, $full_name, $stored_image, $current_user_id);
                    $saved = $save_stmt->execute();
                    $save_stmt->close();

                    if ($saved) {
                        if ($new_profile_absolute_path !== null && $old_stored_image !== "" && $old_stored_image !== $stored_image) {
                            $old_path = __DIR__ . "/../uploads/profile/" . basename($old_stored_image);
                            if (is_file($old_path)) @unlink($old_path);
                        }
                        $_SESSION["username"] = $username;
                        $_SESSION["profile_image"] = $stored_image;
                        account_settings_redirect("profile_updated");
                    }
                    $form_error = "ไม่สามารถบันทึกข้อมูลส่วนตัวได้ กรุณาลองใหม่อีกครั้ง";
                }

                if ($form_error !== "" && $new_profile_absolute_path !== null && is_file($new_profile_absolute_path)) {
                    @unlink($new_profile_absolute_path);
                }
            }
        }
    } elseif ($action === "change_own_password") {
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
        } elseif (!account_password_meets_policy($new_password)) {
            $form_error = "รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัว พร้อมตัวพิมพ์เล็ก ตัวพิมพ์ใหญ่ และอักขระพิเศษ";
        } elseif (!hash_equals($new_password, $confirm_password)) {
            $form_error = "รหัสผ่านใหม่และการยืนยันไม่ตรงกัน";
        } else {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $save_password_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $save_password_stmt->bind_param("si", $new_hash, $current_user_id);
            $save_password_stmt->execute();
            $save_password_stmt->close();
            delete_user_remember_tokens($conn, $current_user_id);
            expire_remember_cookie();
            account_settings_redirect("password_changed");
        }
    }
}

$app_page_title = "Account Settings | IT / AV Task Management System";
$active_nav = "account_settings";
require_once __DIR__ . "/../includes/app_header.php";
?>
<div class="app-shell d-flex">
    <?php require_once __DIR__ . "/../includes/app_sidebar.php"; ?>
    <main class="main-content flex-grow-1 p-4 p-lg-5">
        <div class="mb-4"><h1 class="page-heading h3 fw-bold mb-1">Account Settings</h1><p class="page-subtitle mb-0">จัดการข้อมูลส่วนตัวและความปลอดภัยของบัญชี</p></div>
        <?php if (($_GET["error"] ?? "") === "config_forbidden"): ?><div class="alert alert-warning"><i class="bi bi-shield-lock me-2"></i>System Config ใช้งานได้เฉพาะผู้ดูแลระบบ</div><?php endif; ?>
        <?php if (isset($_GET["saved"])): ?><div class="alert alert-success"><?php echo $_GET["saved"] === "profile_updated" ? "บันทึกข้อมูลส่วนตัวเรียบร้อยแล้ว" : "เปลี่ยนรหัสผ่านเรียบร้อยแล้ว"; ?></div><?php endif; ?>
        <?php if ($form_error !== ""): ?><div class="alert alert-danger"><?php echo htmlspecialchars($form_error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>

        <section class="card form-card" id="profileSettings">
            <div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-person-gear"></i></span><div><h2 class="section-title mb-0">ข้อมูลส่วนตัว</h2><p class="text-muted small mb-0">แก้ไขชื่อผู้ใช้งาน ชื่อ-นามสกุล และรูปโปรไฟล์ของคุณ</p></div></div>
            <form class="card-body p-4" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["account_settings_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                <input type="hidden" name="action" value="update_own_profile">
                <div class="row g-4 align-items-stretch">
                    <div class="col-lg-3 d-flex flex-column align-items-center justify-content-center text-center py-3">
                        <?php if (!empty($account_profile["profile_image"])): ?>
                        <button class="btn p-0 border-0 bg-transparent mb-3 position-relative" type="button" id="profileAvatarView" data-profile-view src-data="../<?php echo htmlspecialchars($account_profile["profile_image"], ENT_QUOTES, "UTF-8"); ?>" aria-label="ดูรูปโปรไฟล์ขนาดเต็ม" title="คลิกเพื่อดูรูปโปรไฟล์">
                            <span class="profile-avatar rounded-circle d-inline-flex align-items-center justify-content-center overflow-hidden" style="width:112px;height:112px;font-size:2.4rem;" id="profileAvatarBox">
                                <img src="../<?php echo htmlspecialchars($account_profile["profile_image"], ENT_QUOTES, "UTF-8"); ?>" alt="รูปโปรไฟล์" class="w-100 h-100 object-fit-cover">
                            </span>
                            <span class="profile-view-hint rounded-circle d-inline-flex align-items-center justify-content-center position-absolute"><i class="bi bi-arrows-fullscreen"></i></span>
                        </button>
                        <?php else: ?>
                        <div class="profile-avatar rounded-circle d-inline-flex align-items-center justify-content-center overflow-hidden mb-3" style="width:112px;height:112px;font-size:2.4rem;" id="profileAvatarBox">
                            <i class="bi bi-person-fill"></i>
                        </div>
                        <?php endif; ?>
                        <label class="btn btn-outline-secondary btn-sm" for="accountProfileImage"><i class="bi bi-camera me-1"></i>เลือกรูปโปรไฟล์</label>
                        <input class="visually-hidden" type="file" id="accountProfileImage" name="profile_image" accept="image/jpeg,image/png,image/webp">
                        <div class="form-text mt-2" id="accountProfileFileName">JPG, PNG หรือ WebP ไม่เกิน 20 MB</div>
                    </div>
                    <div class="col-lg-5"><div class="d-grid gap-3">
                        <div><label class="form-label" for="accountUsername">ชื่อผู้ใช้งาน</label><input class="form-control" id="accountUsername" name="username" maxlength="50" value="<?php echo htmlspecialchars($account_profile["username"] ?? "", ENT_QUOTES, "UTF-8"); ?>" required></div>
                        <div><input type="hidden" name="full_name" value="<?php echo htmlspecialchars($account_profile["full_name"] ?? "", ENT_QUOTES, "UTF-8"); ?>"></div>
                        <div><label class="form-label" for="accountDepartment">ทีม</label><input class="form-control" id="accountDepartment" value="<?php echo htmlspecialchars($account_profile["department"] ?? "", ENT_QUOTES, "UTF-8"); ?>" readonly></div>
                        <div><label class="form-label" for="accountRole">สิทธิ์</label><input class="form-control" id="accountRole" value="<?php echo htmlspecialchars(ui_role_label((string) ($account_profile["role"] ?? "USER")), ENT_QUOTES, "UTF-8"); ?>" readonly></div>
                        <div class="text-end"><button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>บันทึกข้อมูลส่วนตัว</button></div>
                    </div></div>
                    <div class="col-lg-4"><div class="h-100 rounded-3 border bg-light p-4 d-flex flex-column align-items-center justify-content-center text-center" id="securitySettings">
                        <span class="section-icon d-inline-flex align-items-center justify-content-center mb-3" style="width:52px;height:52px;font-size:1.35rem;"><i class="bi bi-shield-lock"></i></span>
                        <h3 class="h6 fw-bold mb-2">ความปลอดภัยของบัญชี</h3>
                        <p class="text-muted small mb-4">จัดการรหัสผ่านสำหรับเข้าสู่ระบบของคุณ</p>
                        <button class="btn btn-danger w-100" type="button" data-bs-toggle="modal" data-bs-target="#changePasswordModal"><i class="bi bi-key-fill me-1"></i>เปลี่ยนรหัสผ่าน</button>
                    </div></div>
                </div>
            </form>
        </section>
    </main>
</div>

<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" id="changePasswordForm">
            <div class="modal-header"><h2 class="modal-title fs-5" id="changePasswordModalLabel"><i class="bi bi-shield-lock me-2 text-danger"></i>เปลี่ยนรหัสผ่าน</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["account_settings_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                <input type="hidden" name="action" value="change_own_password">
                <div class="mb-3"><label class="form-label" for="ownCurrentPassword">รหัสผ่านเดิม</label><div class="input-group"><input class="form-control" type="password" id="ownCurrentPassword" name="current_password" autocomplete="current-password" required><button class="btn btn-outline-secondary" type="button" data-account-password-toggle="ownCurrentPassword" aria-label="แสดงรหัสผ่าน"><i class="bi bi-eye"></i></button></div></div>
                <div class="mb-3"><label class="form-label" for="ownNewPassword">รหัสผ่านใหม่</label><div class="input-group"><input class="form-control" type="password" id="ownNewPassword" name="new_password" minlength="8" autocomplete="new-password" aria-describedby="ownPasswordPolicy" required><button class="btn btn-outline-secondary" type="button" data-account-password-toggle="ownNewPassword" aria-label="แสดงรหัสผ่าน"><i class="bi bi-eye"></i></button></div></div>
                <div class="mb-3"><label class="form-label" for="ownConfirmPassword">ยืนยันรหัสผ่านใหม่</label><div class="input-group"><input class="form-control" type="password" id="ownConfirmPassword" name="confirm_password" minlength="8" autocomplete="new-password" required><button class="btn btn-outline-secondary" type="button" data-account-password-toggle="ownConfirmPassword" aria-label="แสดงรหัสผ่าน"><i class="bi bi-eye"></i></button></div><div class="form-text" id="ownPasswordMatch" aria-live="polite"></div></div>
                <div class="rounded-3 bg-light border p-3" id="ownPasswordPolicy"><strong class="small d-block mb-2">รหัสผ่านต้องมี:</strong><ul class="list-unstyled small mb-0 d-grid gap-1"><li data-password-rule="length"><i class="bi bi-circle me-1"></i>อย่างน้อย 8 ตัวอักษร</li><li data-password-rule="lowercase"><i class="bi bi-circle me-1"></i>ตัวพิมพ์เล็กภาษาอังกฤษ</li><li data-password-rule="uppercase"><i class="bi bi-circle me-1"></i>ตัวพิมพ์ใหญ่ภาษาอังกฤษ</li><li data-password-rule="special"><i class="bi bi-circle me-1"></i>อักขระพิเศษ เช่น ! @ # $ %</li></ul></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" class="btn btn-danger"><i class="bi bi-key-fill me-1"></i>บันทึกรหัสผ่านใหม่</button></div>
        </form>
    </div>
</div>

<style>
    .profile-view-hint { right: 4px; bottom: 4px; width: 32px; height: 32px; color: #fff; background: rgba(15, 23, 42, .62); font-size: .85rem; opacity: 0; transition: opacity .15s ease; }
    #profileAvatarView:hover .profile-view-hint, #profileAvatarView:focus .profile-view-hint { opacity: 1; }
    .profile-lightbox { position: fixed; inset: 0; z-index: 2000; display: none; align-items: center; justify-content: center; background: rgba(7, 11, 25, .93); }
    .profile-lightbox.is-open { display: flex; }
    .profile-lightbox img { max-width: 90vw; max-height: 88vh; border-radius: .6rem; box-shadow: 0 28px 70px rgba(0, 0, 0, .6); }
    .profile-lightbox-close { position: absolute; top: 1rem; right: 1rem; width: 44px; height: 44px; padding: 0; color: #fff; border: 1px solid rgba(255, 255, 255, .3); border-radius: .6rem; background: rgba(255, 255, 255, .12); font-size: 1.1rem; }
    .profile-lightbox-close:hover { border-color: rgba(248, 113, 113, .6); background: rgba(220, 38, 38, .55); }
    .profile-lightbox-caption { position: absolute; bottom: 1.2rem; left: 50%; padding: .4rem 1.1rem; color: #e2e8f0; border-radius: 999px; background: rgba(15, 23, 42, .66); font-size: .95rem; transform: translateX(-50%); }
</style>
<div class="profile-lightbox" id="profileLightbox" role="dialog" aria-modal="true" aria-label="รูปโปรไฟล์ขนาดเต็ม">
    <button class="profile-lightbox-close" type="button" data-profile-close aria-label="ปิด"><i class="bi bi-x-lg"></i></button>
    <img src="" alt="รูปโปรไฟล์ขนาดเต็ม">
    <div class="profile-lightbox-caption">รูปโปรไฟล์ของคุณ · กากบาทหรือคลิกพื้นหลังเพื่อปิด</div>
</div>
<script>
(() => {
    // Full-screen profile picture viewer: click the avatar to open, X / background / Esc to close.
    const viewButton = document.getElementById("profileAvatarView");
    const lightbox = document.getElementById("profileLightbox");
    if (!viewButton || !lightbox) return;

    const image = lightbox.querySelector("img");
    const closeButton = lightbox.querySelector("[data-profile-close]");

    const close = () => {
        lightbox.classList.remove("is-open");
        image.removeAttribute("src");
    };

    viewButton.addEventListener("click", () => {
        image.src = viewButton.getAttribute("src-data");
        lightbox.classList.add("is-open");
        closeButton.focus();
    });
    closeButton.addEventListener("click", close);
    lightbox.addEventListener("click", (event) => {
        if (event.target === lightbox) close();
    });
    document.addEventListener("keydown", (event) => {
        if (lightbox.classList.contains("is-open") && event.key === "Escape") {
            event.preventDefault();
            close();
        }
    });
})();
</script>
<script>
(() => {
    const profileImageInput = document.getElementById('accountProfileImage');
    const profileFileName = document.getElementById('accountProfileFileName');
    profileImageInput?.addEventListener('change', () => {
        const file = profileImageInput.files?.[0];
        if (profileFileName) {
            if (file && file.size > 20 * 1024 * 1024) {
                profileFileName.textContent = `ไฟล์ "${file.name}" ใหญ่เกิน 20 MB กรุณาเลือกไฟล์อื่น`;
                profileFileName.classList.add('text-danger');
            } else {
                profileFileName.textContent = file ? file.name : 'JPG, PNG หรือ WebP ไม่เกิน 20 MB';
                profileFileName.classList.remove('text-danger');
            }
        }
        // Show the chosen picture immediately, before pressing save.
        const avatarBox = document.getElementById('profileAvatarBox');
        if (avatarBox && file && file.type.startsWith('image/')) {
            let preview = avatarBox.querySelector('img');
            if (!preview) {
                preview = document.createElement('img');
                preview.alt = 'ตัวอย่างรูปโปรไฟล์';
                preview.className = 'w-100 h-100 object-fit-cover';
                avatarBox.replaceChildren(preview);
            }
            preview.src = URL.createObjectURL(file);
        }
    });

    const passwordForm = document.getElementById('changePasswordForm');
    const newPassword = document.getElementById('ownNewPassword');
    const confirmPassword = document.getElementById('ownConfirmPassword');
    const matchText = document.getElementById('ownPasswordMatch');
    const ruleElements = Object.fromEntries(Array.from(document.querySelectorAll('[data-password-rule]')).map((element) => [element.dataset.passwordRule, element]));
    const updatePasswordPolicy = () => {
        const value = newPassword?.value || '';
        const rules = { length: value.length >= 8, lowercase: /[a-z]/.test(value), uppercase: /[A-Z]/.test(value), special: /[^A-Za-z0-9\u0E00-\u0E7F\s]/.test(value) };
        Object.entries(rules).forEach(([name, passed]) => {
            const element = ruleElements[name];
            if (!element) return;
            element.classList.toggle('text-success', passed);
            element.classList.toggle('text-muted', !passed);
            element.querySelector('i').className = `bi ${passed ? 'bi-check-circle-fill' : 'bi-circle'} me-1`;
        });
        newPassword?.setCustomValidity(Object.values(rules).every(Boolean) ? '' : 'รหัสผ่านยังไม่ครบตามเงื่อนไข');
        const confirmationEntered = Boolean(confirmPassword?.value);
        const matches = confirmationEntered && value === confirmPassword.value;
        confirmPassword?.setCustomValidity(!confirmationEntered || matches ? '' : 'รหัสผ่านไม่ตรงกัน');
        if (matchText) {
            matchText.textContent = confirmationEntered ? (matches ? 'รหัสผ่านตรงกัน' : 'รหัสผ่านไม่ตรงกัน') : '';
            matchText.className = `form-text ${matches ? 'text-success' : 'text-danger'}`;
        }
    };
    newPassword?.addEventListener('input', updatePasswordPolicy);
    confirmPassword?.addEventListener('input', updatePasswordPolicy);
    passwordForm?.addEventListener('submit', updatePasswordPolicy);
    document.querySelectorAll('[data-account-password-toggle]').forEach((button) => button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.accountPasswordToggle);
        if (!input) return;
        const showPassword = input.type === 'password';
        input.type = showPassword ? 'text' : 'password';
        button.setAttribute('aria-label', showPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
        button.querySelector('i').className = showPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
    }));
    updatePasswordPolicy();
    <?php if ($password_modal_open && $form_error !== ""): ?>bootstrap.Modal.getOrCreateInstance(document.getElementById('changePasswordModal')).show();<?php endif; ?>
})();
</script>
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>
