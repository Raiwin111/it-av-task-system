<?php
// This page removes all login data and returns the user to the login page.

session_start();
$_SESSION = [];

// Also remove the signed Remember Me cookie so this browser stays logged out.
$is_https = !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";
setcookie("remember_me", "", [
    "expires" => time() - 3600,
    "path" => "/",
    "secure" => $is_https,
    "httponly" => true,
    "samesite" => "Lax"
]);

// Remove the session cookie too, when PHP is using cookies for sessions.
if (ini_get("session.use_cookies")) {
    $cookie_params = session_get_cookie_params();
    setcookie(session_name(), "", time() - 42000, $cookie_params["path"], $cookie_params["domain"], $cookie_params["secure"], $cookie_params["httponly"]);
}

session_destroy();
header("Location: login.php");
exit;
?>
