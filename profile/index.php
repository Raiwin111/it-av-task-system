<?php
// A signed-in user may update only their own non-authorization profile fields.
require_once __DIR__ . "/../auth/auth_check.php";
require_once __DIR__ . "/../auth/remember_tokens.php";
require_once __DIR__ . "/../config/db.php";

if (empty($_SESSION["profile_csrf_token"])) {
    $_SESSION["profile_csrf_token"] = bin2hex(random_bytes(32));
}

$user_id = (int) $_SESSION["user_id"];
$notice = "";
$error = "";
$profile_stmt = $conn->prepare("SELECT username, full_name, email, department, role, profile_image FROM users WHERE id = ? LIMIT 1");
$profile_stmt->bind_param("i", $user_id);
$profile_stmt->execute();
$profile = $profile_stmt->get_result()->fetch_assoc();
$profile_stmt->close();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $current_password = $_POST["current_password"] ?? "";
    $new_password = $_POST["new_password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if (!hash_equals($_SESSION["profile_csrf_token"], $_POST["csrf_token"] ?? "")) {
        $error = "แบบฟอร์มหมดอายุ กรุณาลองใหม่อีกครั้ง";
    } elseif ($email !== "" && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150)) {
        $error = "รูปแบบอีเมลไม่ถูกต้อง";
    } elseif ($new_password !== "" && (strlen($new_password) < 8 || !hash_equals($new_password, $confirm_password))) {
        $error = "รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษรและตรงกับการยืนยัน";
    } else {
        $email_stmt = $conn->prepare("SELECT id FROM users WHERE email = NULLIF(?, '') AND id != ? LIMIT 1");
        $email_stmt->bind_param("si", $email, $user_id);
        $email_stmt->execute();
        $email_used = (bool) $email_stmt->get_result()->fetch_assoc();
        $email_stmt->close();

        if ($email_used) {
            $error = "อีเมลนี้ถูกใช้ในระบบแล้ว";
        } else {
            $stored_image = $profile["profile_image"];
            $old_stored_image = (string) ($profile["profile_image"] ?? "");
            $new_profile_absolute_path = null;
            if (isset($_FILES["profile_image"]) && $_FILES["profile_image"]["error"] !== UPLOAD_ERR_NO_FILE) {
                $image = $_FILES["profile_image"];
                $allowed_mimes = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($image["tmp_name"]);
                if ($image["error"] !== UPLOAD_ERR_OK || $image["size"] > 2 * 1024 * 1024 || !isset($allowed_mimes[$mime])) {
                    $error = "รูปโปรไฟล์ต้องเป็น JPG, PNG หรือ WebP ขนาดไม่เกิน 2 MB";
                } else {
                    $upload_dir = __DIR__ . "/../uploads/profile";
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    $file_name = bin2hex(random_bytes(16)) . "." . $allowed_mimes[$mime];
                    if (!move_uploaded_file($image["tmp_name"], $upload_dir . "/" . $file_name)) {
                        $error = "ไม่สามารถบันทึกรูปโปรไฟล์ได้";
                    } else {
                        $new_profile_absolute_path = $upload_dir . "/" . $file_name;
                        $stored_image = "uploads/profile/" . $file_name;
                    }
                }
            }

            if ($error === "" && $new_password !== "") {
                $password_stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
                $password_stmt->bind_param("i", $user_id);
                $password_stmt->execute();
                $password_row = $password_stmt->get_result()->fetch_assoc();
                $password_stmt->close();
                if (!$password_row || !password_verify($current_password, $password_row["password"])) {
                    $error = "รหัสผ่านปัจจุบันไม่ถูกต้อง";
                }
            }

            if ($error === "") {
                if ($new_password !== "") {
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $save_stmt = $conn->prepare("UPDATE users SET full_name = ?, email = NULLIF(?, ''), profile_image = ?, password = ? WHERE id = ?");
                    $save_stmt->bind_param("ssssi", $full_name, $email, $stored_image, $new_hash, $user_id);
                } else {
                    $save_stmt = $conn->prepare("UPDATE users SET full_name = ?, email = NULLIF(?, ''), profile_image = ? WHERE id = ?");
                    $save_stmt->bind_param("sssi", $full_name, $email, $stored_image, $user_id);
                }
                $saved = $save_stmt->execute();
                $save_stmt->close();
                if ($saved) {
                    if ($new_password !== "") {
                        delete_user_remember_tokens($conn, $user_id);
                        expire_remember_cookie();
                    }
                    if ($new_profile_absolute_path !== null && $old_stored_image !== "" && $old_stored_image !== $stored_image) {
                        $old_profile_absolute_path = __DIR__ . "/../uploads/profile/" . basename($old_stored_image);
                        if (is_file($old_profile_absolute_path)) @unlink($old_profile_absolute_path);
                    }
                    $_SESSION["profile_image"] = $stored_image;
                    $notice = "บันทึกข้อมูลส่วนตัวเรียบร้อยแล้ว";
                    $profile["full_name"] = $full_name;
                    $profile["email"] = $email;
                    $profile["profile_image"] = $stored_image;
                } else {
                    $error = "ไม่สามารถบันทึกข้อมูลส่วนตัวได้ กรุณาลองใหม่อีกครั้ง";
                }
            }

            if ($error !== "" && $new_profile_absolute_path !== null && is_file($new_profile_absolute_path)) {
                @unlink($new_profile_absolute_path);
            }
        }
    }
}

$app_page_title = "ข้อมูลส่วนตัว | IT / AV Task Management System";
$active_nav = "profile";
require_once __DIR__ . "/../includes/app_header.php";
?>
<div class="app-shell d-flex"><?php require_once __DIR__ . "/../includes/app_sidebar.php"; ?>
<main class="main-content flex-grow-1 p-4 p-lg-5"><div class="mb-4"><h1 class="page-heading h3 fw-bold mb-1">ข้อมูลส่วนตัว</h1><p class="page-subtitle mb-0">แก้ไขข้อมูลส่วนตัวและรหัสผ่านของคุณ</p></div>
<?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>
<section class="card form-card"><div class="card-header"><h2 class="section-title mb-0"><i class="bi bi-person-gear me-2"></i>โปรไฟล์ของฉัน</h2></div><form class="card-body p-4" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["profile_csrf_token"], ENT_QUOTES, "UTF-8"); ?>"><div class="row g-3"><div class="col-md-6"><label class="form-label">ชื่อผู้ใช้งาน</label><input class="form-control" value="<?php echo htmlspecialchars($profile["username"], ENT_QUOTES, "UTF-8"); ?>" readonly></div><div class="col-md-3"><label class="form-label">ทีม</label><input class="form-control" value="<?php echo htmlspecialchars($profile["department"], ENT_QUOTES, "UTF-8"); ?>" readonly></div><div class="col-md-3"><label class="form-label">สิทธิ์</label><input class="form-control" value="<?php echo htmlspecialchars($profile["role"], ENT_QUOTES, "UTF-8"); ?>" readonly></div><div class="col-md-6"><label class="form-label">ชื่อ-นามสกุล</label><input class="form-control" name="full_name" maxlength="120" value="<?php echo htmlspecialchars($profile["full_name"] ?? "", ENT_QUOTES, "UTF-8"); ?>"></div><div class="col-md-6"><label class="form-label">อีเมล</label><input class="form-control" type="email" name="email" maxlength="150" value="<?php echo htmlspecialchars($profile["email"] ?? "", ENT_QUOTES, "UTF-8"); ?>"></div><div class="col-md-6"><label class="form-label">รูปโปรไฟล์</label><input class="form-control" type="file" name="profile_image" accept="image/jpeg,image/png,image/webp"><div class="form-text">JPG, PNG หรือ WebP ขนาดไม่เกิน 2 MB</div></div><div class="col-12"><hr><h3 class="h6 fw-bold">เปลี่ยนรหัสผ่าน <span class="text-muted fw-normal">(เว้นว่างไว้หากไม่ต้องการเปลี่ยน)</span></h3></div><div class="col-md-4"><label class="form-label">รหัสผ่านปัจจุบัน</label><input class="form-control" type="password" name="current_password" autocomplete="current-password"></div><div class="col-md-4"><label class="form-label">รหัสผ่านใหม่</label><input class="form-control" type="password" name="new_password" minlength="8" autocomplete="new-password"></div><div class="col-md-4"><label class="form-label">ยืนยันรหัสผ่านใหม่</label><input class="form-control" type="password" name="confirm_password" minlength="8" autocomplete="new-password"></div></div><div class="form-actions mt-4 pt-3 text-end"><button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>บันทึกข้อมูล</button></div></form></section></main></div>
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>
