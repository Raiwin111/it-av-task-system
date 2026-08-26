<?php
// This page checks a username and password, then starts a secure user session.

require_once __DIR__ . "/session.php";
start_secure_session();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/remember_tokens.php";

// A distinct token protects this public form from cross-site login requests.
if (empty($_SESSION["login_csrf_token"])) {
    $_SESSION["login_csrf_token"] = bin2hex(random_bytes(32));
}

// Store a minimal audit record. Passwords, cookies and session IDs are never logged.
function write_login_log(mysqli $conn, string $username, ?int $user_id, bool $is_success, ?string $failed_reason): void
{
    $ip_address = client_ip_address();
    $browser = substr((string) ($_SERVER["HTTP_USER_AGENT"] ?? ""), 0, 255);
    $success_value = $is_success ? 1 : 0;

    $log_stmt = $conn->prepare("INSERT INTO login_logs (username, user_id, ip_address, browser, is_success, failed_reason) VALUES (?, ?, ?, ?, ?, ?)");
    $log_stmt->bind_param("sissis", $username, $user_id, $ip_address, $browser, $success_value, $failed_reason);
    $log_stmt->execute();
    $log_stmt->close();
}

function client_ip_address(): string
{
    return substr((string) ($_SERVER["REMOTE_ADDR"] ?? ""), 0, 45);
}

// A user who is already logged in can go straight to the dashboard only while active.
if (isset($_SESSION["user_id"])) {
    $session_user_id = (int) $_SESSION["user_id"];
    $session_status_stmt = $conn->prepare("SELECT is_enabled, is_approved, lock_until FROM users WHERE id = ? LIMIT 1");
    $session_status_stmt->bind_param("i", $session_user_id);
    $session_status_stmt->execute();
    $session_user = $session_status_stmt->get_result()->fetch_assoc();
    $session_status_stmt->close();

    if ($session_user && (int) $session_user["is_enabled"] === 1 && (empty($session_user["lock_until"]) || strtotime($session_user["lock_until"]) <= time())) {
        header("Location: ../dashboard/index.php");
        exit;
    }

    $_SESSION = [];
    session_destroy();
}

$message = "";
$message_type = "warning";
$attempts_left = null;
$lock_until_timestamp = null;
$session_expired_notice = isset($_GET["expired"]);

// A one-time validator can restore a login for up to 30 days. The database stores
// only its hash, and the validator is rotated after every automatic login.
if (isset($_COOKIE["remember_me"])) {
    $remember_user = restore_user_from_remember_token($conn);
    if ($remember_user) {
        session_regenerate_id(true);
        $_SESSION["user_id"] = $remember_user["id"];
        $_SESSION["username"] = $remember_user["username"];
        $_SESSION["department"] = $remember_user["department"];
        $_SESSION["role"] = $remember_user["role"];
        $_SESSION["profile_image"] = $remember_user["profile_image"];
        $_SESSION["is_approved"] = (int) $remember_user["is_approved"];
        $_SESSION["last_activity"] = time();

        header("Location: ../dashboard/index.php");
        exit;
    }

    // Remove an expired, disabled or invalid Remember Me cookie.
    expire_remember_cookie();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    $submitted_csrf_token = $_POST["login_csrf_token"] ?? "";

    if (!hash_equals($_SESSION["login_csrf_token"], $submitted_csrf_token)) {
        $message = "แบบฟอร์มหมดอายุ กรุณาลองใหม่อีกครั้ง";
        $message_type = "danger";
    } else {
        // A separate IP limit protects unknown usernames, which cannot be locked by account.
        $ip_address = client_ip_address();
        $ip_limit_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM login_logs WHERE ip_address = ? AND is_success = 0 AND login_time >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $ip_limit_stmt->bind_param("s", $ip_address);
        $ip_limit_stmt->execute();
        $recent_ip_failures = (int) ($ip_limit_stmt->get_result()->fetch_assoc()["total"] ?? 0);
        $ip_limit_stmt->close();

        if ($recent_ip_failures >= 20) {
            write_login_log($conn, $username, null, false, "ip_rate_limited");
            $message = "มีการพยายามเข้าสู่ระบบจากเครือข่ายนี้มากเกินไป กรุณาลองใหม่อีกครั้งภายหลัง";
            $message_type = "danger";
        } else {
        // Prepared statements keep submitted values out of the SQL command itself.
        $stmt = $conn->prepare("SELECT id, username, password, department, role, profile_image, is_enabled, is_approved, failed_login_attempts, lock_until FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && !empty($user["lock_until"]) && strtotime($user["lock_until"]) > time()) {
            write_login_log($conn, $username, (int) $user["id"], false, "account_locked");
            $lock_until_timestamp = strtotime($user["lock_until"]);
            $message = "มีการเข้าสู่ระบบผิดหลายครั้งเกินกำหนด";
            $message_type = "danger";
        } elseif ($user && (int) $user["is_enabled"] !== 1) {
            write_login_log($conn, $username, (int) $user["id"], false, "account_disabled");
            $message = "บัญชีนี้ถูกปิดใช้งาน กรุณาติดต่อผู้ดูแลระบบ";
            $message_type = "danger";
        } elseif ($user && password_verify($password, $user["password"])) {
            // Reset the per-account lock state only after a valid password.
            $reset_stmt = $conn->prepare("UPDATE users SET failed_login_attempts = 0, lock_until = NULL WHERE id = ?");
            $reset_stmt->bind_param("i", $user["id"]);
            $reset_stmt->execute();
            $reset_stmt->close();
            write_login_log($conn, $username, (int) $user["id"], true, null);

            // Prevent session fixation by changing the session ID after login.
            session_regenerate_id(true);
            $_SESSION["user_id"] = $user["id"];
            $_SESSION["username"] = $user["username"];
            $_SESSION["department"] = $user["department"];
            $_SESSION["role"] = $user["role"];
            $_SESSION["profile_image"] = $user["profile_image"];
            $_SESSION["is_approved"] = (int) $user["is_approved"];
            $_SESSION["last_activity"] = time();

            // Store a revocable, per-device token only when Remember Me is checked.
            if (isset($_POST["remember_me"])) {
                issue_remember_token($conn, (int) $user["id"]);
            }

            header("Location: ../dashboard/index.php");
            exit;
        } else {
            // A known account is locked by account, not by browser session.
            if ($user) {
                $new_attempt_count = (int) $user["failed_login_attempts"] + 1;
                $user_id = (int) $user["id"];

                if ($new_attempt_count >= 5) {
                    $lock_stmt = $conn->prepare("UPDATE users SET failed_login_attempts = 0, lock_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ?");
                    $lock_stmt->bind_param("i", $user_id);
                    $lock_stmt->execute();
                    $lock_stmt->close();
                    $lock_until_timestamp = time() + (5 * 60);
                    $message = "มีการเข้าสู่ระบบผิดหลายครั้งเกินกำหนด";
                    $message_type = "danger";
                    write_login_log($conn, $username, $user_id, false, "account_locked");
                } else {
                    $attempt_stmt = $conn->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?");
                    $attempt_stmt->bind_param("ii", $new_attempt_count, $user_id);
                    $attempt_stmt->execute();
                    $attempt_stmt->close();
                    $attempts_left = 5 - $new_attempt_count;
                    $message = "ชื่อผู้ใช้งานหรือรหัสผ่านไม่ถูกต้อง";
                    write_login_log($conn, $username, $user_id, false, "wrong_password");
                }
            } else {
                write_login_log($conn, $username, null, false, "user_not_found");
                $message = "ชื่อผู้ใช้งานหรือรหัสผ่านไม่ถูกต้อง";
            }
        }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ | IT / AV Task Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --navy-900: #061a32;
            --navy-700: #0b3a68;
            --primary: #1769c2;
            --primary-dark: #0e56a2;
        }

        body {
            min-height: 100vh;
            overflow-x: hidden;
            font-family: "Poppins", "Inter", "Segoe UI", sans-serif;
            background: linear-gradient(135deg, var(--navy-900) 0%, #0a315b 52%, var(--navy-700) 100%);
        }

        body::before {
            position: fixed;
            inset: 0;
            z-index: 0;
            content: "";
            background-image: linear-gradient(rgba(255, 255, 255, .035) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, .035) 1px, transparent 1px);
            background-size: 34px 34px;
            pointer-events: none;
        }

        .background-orb {
            position: fixed;
            z-index: 0;
            width: 330px;
            height: 330px;
            border-radius: 50%;
            filter: blur(18px);
            opacity: .28;
            pointer-events: none;
        }

        .orb-one { top: -115px; left: -95px; background: #3c9cf4; }
        .orb-two { right: -125px; bottom: -140px; background: #1d7ed8; }

        main { position: relative; z-index: 1; }
        .login-wrapper { width: 100%; max-width: 440px; }

        .login-card {
            width: 100%;
            border: 1px solid rgba(255, 255, 255, .72);
            border-radius: 20px;
            background: rgba(255, 255, 255, .94);
            box-shadow: 0 20px 45px rgba(1, 17, 34, .30);
            backdrop-filter: blur(12px);
            animation: card-fade-in .65s ease-out both;
            transition: transform .3s ease, box-shadow .3s ease;
        }

        .login-alert {
            border: 0;
            border-radius: 12px;
            box-shadow: 0 7px 18px rgba(164, 35, 35, .12);
            animation: alert-fade-in .45s ease-out both;
        }

        .login-alert .alert-icon {
            width: 36px;
            height: 36px;
            color: #b42318;
            background: rgba(220, 53, 69, .12);
        }

        .countdown-value {
            color: #b42318;
            font-size: 1.65rem;
            font-variant-numeric: tabular-nums;
            letter-spacing: .06em;
            line-height: 1;
        }

        .login-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 27px 58px rgba(1, 17, 34, .43);
        }

        .brand-icon {
            width: 62px;
            height: 62px;
            color: #fff;
            background: linear-gradient(135deg, #2584de, #0a427c);
            box-shadow: 0 9px 20px rgba(20, 102, 186, .28);
        }

        .system-title { color: #102b49; letter-spacing: -.02em; }
        .system-subtitle { color: #64748b; }

        .input-group-text {
            color: #497099;
            border: 1px solid #d9e3ee;
            border-right: 0;
            border-radius: 12px 0 0 12px;
            background: #f8fafc;
            transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
        }

        .form-control {
            border-color: #d9e3ee;
            border-left: 0;
            border-radius: 0 12px 12px 0;
            transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
        }

        .input-group:focus-within { transform: scale(1.015); }

        .input-group:focus-within .input-group-text,
        .input-group:focus-within .form-control {
            border-color: #4f9dea;
            box-shadow: 0 0 0 .22rem rgba(23, 105, 194, .16);
        }

        .input-group:focus-within .input-group-text { box-shadow: 0 0 0 .22rem rgba(23, 105, 194, .16); }
        .form-control:focus { box-shadow: none; }

        .login-button {
            border: 0;
            border-radius: 12px;
            background: linear-gradient(135deg, #2079d2, #125ba9);
            box-shadow: 0 8px 17px rgba(18, 91, 169, .25);
            transition: background .2s ease, transform .2s ease, box-shadow .2s ease;
        }

        .login-button:hover,
        .login-button:focus {
            background: linear-gradient(135deg, #145fae, #0c4788);
            transform: translateY(-2px);
            box-shadow: 0 12px 22px rgba(13, 70, 132, .32);
        }

        .form-check-input:checked { background-color: var(--primary); border-color: var(--primary); }
        .page-footer { color: rgba(226, 232, 240, .72); font-size: .78rem; line-height: 1.65; }

        @keyframes card-fade-in {
            from { opacity: 0; transform: translateY(18px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes alert-fade-in {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 575.98px) {
            .login-card .card-body { padding: 2rem 1.35rem !important; }
            .system-title { font-size: 1.2rem; }
        }
    </style>
</head>
<body class="d-flex align-items-center py-4">
    <!-- Decorative background layers keep the corporate page visually subtle. -->
    <div class="background-orb orb-one"></div>
    <div class="background-orb orb-two"></div>

    <main class="container d-flex justify-content-center">
        <div class="login-wrapper">
            <!-- Centered glass-like Bootstrap card for the login form. -->
            <section class="card login-card">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="brand-icon rounded-circle d-inline-flex align-items-center justify-content-center fs-4 mb-3"><i class="bi bi-buildings-fill"></i></div>
                    <h1 class="system-title h4 fw-bold mb-1">IT / AV Task Management System</h1>
                    <p class="system-subtitle mb-0">เข้าสู่ระบบเพื่อใช้งานต่อ</p>
                </div>

                <?php if ($session_expired_notice): ?>
                    <div class="alert alert-warning text-center login-alert mb-4" role="alert"><i class="bi bi-clock-history me-1"></i>เซสชันหมดอายุเนื่องจากไม่มีการใช้งาน กรุณาเข้าสู่ระบบอีกครั้ง</div>
                <?php endif; ?>

                <?php if ($message !== "" && $lock_until_timestamp !== null): ?>
                    <!-- The server enforces the per-account lock; this timer only displays it. -->
                    <div class="alert alert-danger login-alert text-center mb-4" role="alert" data-lock-until="<?php echo (int) $lock_until_timestamp; ?>">
                        <span class="alert-icon rounded-circle d-inline-flex align-items-center justify-content-center mb-2"><i class="bi bi-lock-fill"></i></span>
                        <div class="fw-bold">มีการเข้าสู่ระบบผิดหลายครั้งเกินกำหนด</div>
                        <div class="small mt-2">กรุณาลองใหม่ใน:</div>
                        <div id="lockCountdown" class="countdown-value fw-bold mt-1" aria-live="polite">05:00</div>
                    </div>
                <?php elseif ($message !== ""): ?>
                    <div class="alert alert-danger login-alert text-center mb-4" role="alert">
                        <span class="alert-icon rounded-circle d-inline-flex align-items-center justify-content-center mb-2"><i class="bi bi-exclamation-triangle-fill"></i></span>
                        <div class="fw-bold"><?php echo htmlspecialchars($message, ENT_QUOTES, "UTF-8"); ?></div>
                        <?php if ($attempts_left !== null): ?>
                            <div class="small mt-2">จำนวนครั้งที่เหลือ: <span class="fw-bold text-danger"><?php echo max(0, $attempts_left); ?></span></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="">
                    <input type="hidden" name="login_csrf_token" value="<?php echo htmlspecialchars($_SESSION["login_csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                    <div class="mb-3">
                        <label for="username" class="form-label">ชื่อผู้ใช้งาน</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                            <input type="text" class="form-control" id="username" name="username" autocomplete="off" required autofocus>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">รหัสผ่าน</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                            <button class="btn btn-outline-secondary border-start-0" type="button" data-password-toggle="password" aria-label="แสดงรหัสผ่าน" title="แสดง/ซ่อนรหัสผ่าน"><i class="bi bi-eye"></i></button>
                        </div>
                        <div id="capsLockWarning" class="form-text text-warning d-none"><i class="bi bi-exclamation-triangle me-1"></i>กำลังเปิด Caps Lock อยู่</div>
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me" value="1">
                        <label class="form-check-label" for="remember_me">จดจำการเข้าสู่ระบบ 30 วัน</label>
                    </div>

                    <button type="submit" class="login-button btn btn-primary btn-lg w-100">เข้าสู่ระบบ</button>
                </form>
            </div>
            </section>

            <!-- Small company footer shown below the card. -->
            <footer class="page-footer text-center mt-4">
                <div>Version 1.0</div>
                <div>© 2026 IT / AV Task Management System</div>
                <div>Grand Palazzo Hotel Pattaya</div>
            </footer>
        </div>
    </main>
    <script>
        // The server still enforces the lock. This only keeps the visible timer current.
        const lockAlert = document.querySelector('[data-lock-until]');

        if (lockAlert) {
            const countdown = document.getElementById('lockCountdown');
            const lockUntil = Number(lockAlert.dataset.lockUntil) * 1000;

            const updateCountdown = () => {
                const remainingSeconds = Math.max(0, Math.ceil((lockUntil - Date.now()) / 1000));
                const minutes = String(Math.floor(remainingSeconds / 60)).padStart(2, '0');
                const seconds = String(remainingSeconds % 60).padStart(2, '0');
                countdown.textContent = `${minutes}:${seconds}`;

                if (remainingSeconds === 0) {
                    window.location.reload();
                }
            };

            updateCountdown();
            window.setInterval(updateCountdown, 1000);
        }

        document.querySelectorAll('[data-password-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.passwordToggle);
                if (!input) return;
                const visible = input.type === 'text';
                input.type = visible ? 'password' : 'text';
                button.setAttribute('aria-label', visible ? 'แสดงรหัสผ่าน' : 'ซ่อนรหัสผ่าน');
                button.querySelector('i').className = visible ? 'bi bi-eye' : 'bi bi-eye-slash';
            });
        });

        document.getElementById('password')?.addEventListener('keyup', (event) => {
            document.getElementById('capsLockWarning')?.classList.toggle('d-none', !event.getModifierState('CapsLock'));
        });
    </script>
</body>
</html>
