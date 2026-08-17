<?php
// This internal system provisions accounts through Config (ADMIN) only.
http_response_code(404);
exit;

// Public self-registration. New accounts can sign in with view-only access until ADMIN approval.
require_once __DIR__ . "/session.php";
start_secure_session();
require_once __DIR__ . "/../config/db.php";

if (isset($_SESSION["user_id"])) {
    header("Location: ../dashboard/");
    exit;
}

if (empty($_SESSION["register_csrf_token"])) {
    $_SESSION["register_csrf_token"] = bin2hex(random_bytes(32));
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $full_name = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if (!hash_equals($_SESSION["register_csrf_token"], $_POST["csrf_token"] ?? "")) {
        $error = "แบบฟอร์มหมดอายุ กรุณาลองใหม่อีกครั้ง";
    } elseif ($username === "" || strlen($username) > 50 || strlen($password) < 8 || !hash_equals($password, $confirm_password)) {
        $error = "กรุณากรอกข้อมูลให้ครบถ้วน และรหัสผ่านต้องตรงกันอย่างน้อย 8 ตัวอักษร";
    } elseif ($email !== "" && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150)) {
        $error = "รูปแบบอีเมลไม่ถูกต้อง";
    } else {
        $duplicate_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR (email IS NOT NULL AND email = ?) LIMIT 1");
        $duplicate_stmt->bind_param("ss", $username, $email);
        $duplicate_stmt->execute();
        $already_exists = (bool) $duplicate_stmt->get_result()->fetch_assoc();
        $duplicate_stmt->close();

        if ($already_exists) {
            $error = "ชื่อผู้ใช้งานหรืออีเมลนี้มีอยู่ในระบบแล้ว";
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $department = "IT"; // ADMIN assigns the real team during approval.
            $role = "USER"; // New accounts never self-assign privileged roles.
            $is_enabled = 1;
            $is_approved = 0;
            $register_stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password, department, role, is_enabled, is_approved) VALUES (?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?)");
            $register_stmt->bind_param("ssssssii", $username, $full_name, $email, $password_hash, $department, $role, $is_enabled, $is_approved);
            $registered = $register_stmt->execute();
            $register_stmt->close();

            if ($registered) {
                unset($_SESSION["register_csrf_token"]);
                header("Location: login.php?registered=1");
                exit;
            }
            $error = "ไม่สามารถสมัครบัญชีได้ ชื่อผู้ใช้งานหรืออีเมลอาจถูกใช้แล้ว";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครบัญชี | IT / AV Task Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body{min-height:100vh;background:linear-gradient(135deg,#061a32,#0b3a68);font-family:"Segoe UI",sans-serif}.register-card{max-width:520px;border:0;border-radius:1rem;box-shadow:0 20px 45px rgba(1,17,34,.3)}.brand-icon{width:58px;height:58px;color:#fff;background:linear-gradient(135deg,#2584de,#0a427c)}.form-control{min-height:45px}
    </style>
</head>
<body class="d-flex align-items-center py-4">
    <main class="container d-flex justify-content-center">
        <section class="card register-card w-100"><div class="card-body p-4 p-md-5">
            <div class="text-center mb-4"><span class="brand-icon rounded-circle d-inline-flex align-items-center justify-content-center fs-4 mb-3"><i class="bi bi-person-plus-fill"></i></span><h1 class="h4 fw-bold mb-1">สมัครบัญชีผู้ใช้</h1><p class="text-muted mb-0">สร้างบัญชีเพื่อเข้าใช้งาน IT / AV Task Management System</p></div>
            <?php if ($error !== ""): ?><div class="alert alert-danger text-center"><?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?></div><?php endif; ?>
            <form method="post" novalidate><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["register_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                <div class="mb-3"><label class="form-label" for="full_name">ชื่อ-นามสกุล</label><input class="form-control" id="full_name" name="full_name" maxlength="120" value="<?php echo htmlspecialchars($_POST["full_name"] ?? "", ENT_QUOTES, "UTF-8"); ?>"></div>
                <div class="mb-3"><label class="form-label" for="username">ชื่อผู้ใช้งาน <span class="text-danger">*</span></label><input class="form-control" id="username" name="username" maxlength="50" autocomplete="username" required value="<?php echo htmlspecialchars($_POST["username"] ?? "", ENT_QUOTES, "UTF-8"); ?>"></div>
                <div class="mb-3"><label class="form-label" for="email">อีเมล</label><input class="form-control" type="email" id="email" name="email" maxlength="150" autocomplete="email" value="<?php echo htmlspecialchars($_POST["email"] ?? "", ENT_QUOTES, "UTF-8"); ?>"></div>
                <div class="row g-3"><div class="col-md-6"><label class="form-label" for="password">รหัสผ่าน <span class="text-danger">*</span></label><div class="input-group"><input class="form-control" type="password" id="password" name="password" minlength="8" autocomplete="new-password" required><button class="btn btn-outline-secondary" type="button" data-password-toggle="password" aria-label="แสดงรหัสผ่าน"><i class="bi bi-eye"></i></button></div><div class="mt-2"><div class="progress" style="height: 6px;"><div id="passwordStrengthBar" class="progress-bar" role="progressbar" style="width:0"></div></div><div id="passwordStrengthText" class="form-text">รหัสผ่านอย่างน้อย 8 ตัวอักษร</div></div></div><div class="col-md-6"><label class="form-label" for="confirm_password">ยืนยันรหัสผ่าน <span class="text-danger">*</span></label><div class="input-group"><input class="form-control" type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required><button class="btn btn-outline-secondary" type="button" data-password-toggle="confirm_password" aria-label="แสดงรหัสผ่าน"><i class="bi bi-eye"></i></button></div><div id="passwordMatchText" class="form-text"></div></div></div>
                <button class="btn btn-primary w-100 mt-4 py-2" type="submit"><i class="bi bi-person-plus me-1"></i>สมัครสมาชิก</button>
            </form>
            <div class="text-center small mt-3">มีบัญชีอยู่แล้ว? <a href="login.php" class="text-decoration-none fw-semibold">เข้าสู่ระบบ</a></div>
        </div></section>
    </main>
    <script>
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const strengthBar = document.getElementById('passwordStrengthBar');
        const strengthText = document.getElementById('passwordStrengthText');
        const matchText = document.getElementById('passwordMatchText');

        const updatePasswordFeedback = () => {
            const password = passwordInput.value;
            let score = 0;
            if (password.length >= 8) score++;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score++;
            if (/\d/.test(password)) score++;
            if (/[^A-Za-z0-9]/.test(password)) score++;
            const labels = ['กรุณาตั้งรหัสผ่านอย่างน้อย 8 ตัวอักษร', 'อ่อน', 'พอใช้', 'ดี', 'แข็งแรง'];
            const colors = ['bg-danger', 'bg-danger', 'bg-warning', 'bg-info', 'bg-success'];
            strengthBar.style.width = `${score * 25}%`;
            strengthBar.className = `progress-bar ${colors[score]}`;
            strengthText.textContent = labels[score];
            if (confirmPasswordInput.value) {
                const matches = password === confirmPasswordInput.value;
                matchText.className = `form-text ${matches ? 'text-success' : 'text-danger'}`;
                matchText.textContent = matches ? 'รหัสผ่านตรงกัน' : 'รหัสผ่านไม่ตรงกัน';
            } else {
                matchText.textContent = '';
            }
        };
        passwordInput.addEventListener('input', updatePasswordFeedback);
        confirmPasswordInput.addEventListener('input', updatePasswordFeedback);
        document.querySelectorAll('[data-password-toggle]').forEach((button) => button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.passwordToggle);
            const visible = input.type === 'text';
            input.type = visible ? 'password' : 'text';
            button.querySelector('i').className = visible ? 'bi bi-eye' : 'bi bi-eye-slash';
        }));
    </script>
</body>
</html>
