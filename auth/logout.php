<?php
// This page removes all login data and returns the user to the login page.

require_once __DIR__ . "/session.php";
start_secure_session();

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
    http_response_code(405);
    header("Allow: POST");
    exit("Method Not Allowed");
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/remember_tokens.php";

$submitted_token = is_string($_POST["csrf_token"] ?? null) ? $_POST["csrf_token"] : "";
$session_token = is_string($_SESSION["logout_csrf_token"] ?? null) ? $_SESSION["logout_csrf_token"] : "";
if ($session_token === "" || !hash_equals($session_token, $submitted_token)) {
    // A failed token almost always means the session already expired on a stale page.
    // The visitor still wants out, so clear everything locally and return to the login screen.
    delete_remember_token_from_cookie($conn);
    $_SESSION = [];
    expire_remember_cookie();
    expire_session_cookie();
    session_destroy();
    header("Location: login.php?expired=1");
    exit;
}

delete_remember_token_from_cookie($conn);
$_SESSION = [];

// Also remove the revocable Remember Me cookie so this browser stays logged out.
expire_remember_cookie();

// Remove the session cookie too, when PHP is using cookies for sessions.
expire_session_cookie();

session_destroy();
header("Location: login.php");
exit;
?>
