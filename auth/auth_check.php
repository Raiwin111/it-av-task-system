<?php
// Include this file at the top of pages that require a logged-in user.

require_once __DIR__ . "/session.php";
$session_timed_out = start_secure_session();

// Send visitors without a login back to the login page.
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php" . ($session_timed_out ? "?expired=1" : ""));
    exit;
}

// Re-check account status on protected pages so a disabled or locked account
// cannot continue using an old session or a Remember Me restoration.
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/remember_tokens.php";
$authenticated_user_id = (int) $_SESSION["user_id"];
$account_stmt = $conn->prepare("SELECT username, department, role, profile_image, is_enabled, is_approved, lock_until FROM users WHERE id = ? LIMIT 1");
$account_stmt->bind_param("i", $authenticated_user_id);
$account_stmt->execute();
$account = $account_stmt->get_result()->fetch_assoc();
$account_stmt->close();

if (!$account || (int) $account["is_enabled"] !== 1 || (!empty($account["lock_until"]) && strtotime($account["lock_until"]) > time())) {
    delete_remember_token_from_cookie($conn);
    $_SESSION = [];
    session_destroy();
    expire_remember_cookie();
    header("Location: ../auth/login.php");
    exit;
}

// Approval controls edit permissions. It does not prevent a new user from viewing the system.
$_SESSION["username"] = (string) $account["username"];
$_SESSION["department"] = (string) $account["department"];
$_SESSION["role"] = (string) $account["role"];
$_SESSION["profile_image"] = (string) ($account["profile_image"] ?? "");
$_SESSION["is_approved"] = (int) $account["is_approved"];
$_SESSION["last_activity"] = time();

// Persist presence at most once per minute so Config can show an actual recent-user count.
if (!isset($_SESSION["activity_ping_at"]) || time() - (int) $_SESSION["activity_ping_at"] >= 60) {
    $activity_stmt = $conn->prepare("UPDATE users SET last_activity_at = NOW() WHERE id = ?");
    $activity_stmt->bind_param("i", $authenticated_user_id);
    $activity_stmt->execute();
    $activity_stmt->close();
    $_SESSION["activity_ping_at"] = time();
}
?>
