<?php
// This page checks a username and password, then starts a secure user session.

session_start();
require_once __DIR__ . "/../config/db.php";

// A user who is already logged in can go straight to the dashboard.
if (isset($_SESSION["user_id"])) {
    header("Location: ../dashboard/index.php");
    exit;
}

$message = "";
$message_type = "warning";

// Cookie options protect the Remember Me cookie from JavaScript and cross-site use.
// The secure flag is enabled automatically when this site is using HTTPS.
$remember_cookie_options = [
    "expires" => time() + (30 * 24 * 60 * 60),
    "path" => "/",
    "secure" => !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off",
    "httponly" => true,
    "samesite" => "Lax"
];

// A valid signed cookie can restore a login for up to 30 days. It contains no password.
if (isset($_COOKIE["remember_me"])) {
    $cookie_parts = explode("|", $_COOKIE["remember_me"]);

    if (count($cookie_parts) === 3) {
        [$remember_user_id, $expires_at, $signature] = $cookie_parts;
        $cookie_data = $remember_user_id . "|" . $expires_at;
        $expected_signature = hash_hmac("sha256", $cookie_data, $remember_me_secret);

        if (ctype_digit($remember_user_id) && ctype_digit($expires_at) && time() <= (int) $expires_at && hash_equals($expected_signature, $signature)) {
            $remember_stmt = $conn->prepare("SELECT id, username, department, role FROM users WHERE id = ? LIMIT 1");
            $remember_stmt->bind_param("i", $remember_user_id);
            $remember_stmt->execute();
            $remember_user = $remember_stmt->get_result()->fetch_assoc();
            $remember_stmt->close();

            if ($remember_user) {
                session_regenerate_id(true);
                $_SESSION["user_id"] = $remember_user["id"];
                $_SESSION["username"] = $remember_user["username"];
                $_SESSION["department"] = $remember_user["department"];
                $_SESSION["role"] = $remember_user["role"];

                header("Location: ../dashboard/index.php");
                exit;
            }
        }
    }

    // Remove an expired or invalid Remember Me cookie.
    setcookie("remember_me", "", [
        "expires" => time() - 3600,
        "path" => "/",
        "secure" => $remember_cookie_options["secure"],
        "httponly" => true,
        "samesite" => "Lax"
    ]);
}

// Do not allow login while the five-minute lock is active.
if (isset($_SESSION["login_lock_until"]) && time() < $_SESSION["login_lock_until"]) {
    $remaining_seconds = $_SESSION["login_lock_until"] - time();
    $message = "Too many failed attempts. Please try again in " . ceil($remaining_seconds / 60) . " minute(s).";
    $message_type = "danger";
} elseif (isset($_SESSION["login_lock_until"])) {
    // Clear the old lock and failed attempts after the lock period ends.
    unset($_SESSION["login_lock_until"], $_SESSION["failed_login_attempts"]);
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $message === "") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    // Prepared statements keep submitted values out of the SQL command itself.
    $stmt = $conn->prepare("SELECT id, username, password, department, role FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    // password_verify compares the submitted password with password_hash data.
    if ($user && password_verify($password, $user["password"])) {
        // Prevent session fixation by changing the session ID after login.
        session_regenerate_id(true);

        $_SESSION["user_id"] = $user["id"];
        $_SESSION["username"] = $user["username"];
        $_SESSION["department"] = $user["department"];
        $_SESSION["role"] = $user["role"];
        unset($_SESSION["failed_login_attempts"], $_SESSION["login_lock_until"]);

        // Store a signed user ID and expiry date only when Remember Me is checked.
        if (isset($_POST["remember_me"])) {
            $expires_at = time() + (30 * 24 * 60 * 60);
            $cookie_data = $user["id"] . "|" . $expires_at;
            $signature = hash_hmac("sha256", $cookie_data, $remember_me_secret);
            $remember_cookie_options["expires"] = $expires_at;
            setcookie("remember_me", $cookie_data . "|" . $signature, $remember_cookie_options);
        }

        header("Location: ../dashboard/index.php");
        exit;
    }

    // Count failed attempts in this browser session.
    $_SESSION["failed_login_attempts"] = ($_SESSION["failed_login_attempts"] ?? 0) + 1;
    $attempts_left = 5 - $_SESSION["failed_login_attempts"];

    if ($_SESSION["failed_login_attempts"] >= 5) {
        $_SESSION["login_lock_until"] = time() + (5 * 60);
        $message = "Too many failed attempts. Login is locked for 5 minutes.";
        $message_type = "danger";
    } else {
        $message = "Invalid username or password. Attempts left: " . $attempts_left;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | IT / AV Task Management System</title>
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
                    <p class="system-subtitle mb-0">Please sign in to continue</p>
                </div>

                <?php if ($message !== "" && isset($_SESSION["login_lock_until"])): ?>
                    <!-- This uses the existing server lock timestamp; it does not change lockout behavior. -->
                    <div class="alert alert-danger login-alert text-center mb-4" role="alert" data-lock-until="<?php echo (int) $_SESSION["login_lock_until"]; ?>">
                        <span class="alert-icon rounded-circle d-inline-flex align-items-center justify-content-center mb-2"><i class="bi bi-lock-fill"></i></span>
                        <div class="fw-bold">Too many failed login attempts.</div>
                        <div class="small mt-2">Please try again in:</div>
                        <div id="lockCountdown" class="countdown-value fw-bold mt-1" aria-live="polite">05:00</div>
                    </div>
                <?php elseif ($message !== ""): ?>
                    <div class="alert alert-danger login-alert text-center mb-4" role="alert">
                        <span class="alert-icon rounded-circle d-inline-flex align-items-center justify-content-center mb-2"><i class="bi bi-exclamation-triangle-fill"></i></span>
                        <div class="fw-bold">Invalid username or password.</div>
                        <div class="small mt-2">Attempts left: <span class="fw-bold text-danger"><?php echo max(0, 5 - ($_SESSION["failed_login_attempts"] ?? 0)); ?></span></div>
                    </div>
                <?php endif; ?>

                <form method="post" action="">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                            <input type="text" class="form-control" id="username" name="username" autocomplete="username" required autofocus>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                        </div>
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me" value="1">
                        <label class="form-check-label" for="remember_me">Remember Me for 30 days</label>
                    </div>

                    <button type="submit" class="login-button btn btn-primary btn-lg w-100">Login</button>
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
    </script>
</body>
</html>
