<?php
// Include this file at the top of pages that require a logged-in user.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Send visitors without a login back to the login page.
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}
?>
