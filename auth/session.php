<?php
// Shared session hardening for public and authenticated pages.
const AUTH_IDLE_TIMEOUT_SECONDS = 30 * 60;

function request_uses_https(): bool
{
    return (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
        || (string) ($_SERVER["SERVER_PORT"] ?? "") === "443";
}

function expire_session_cookie(): void
{
    if (!ini_get("session.use_cookies")) return;

    $params = session_get_cookie_params();
    setcookie(session_name(), "", [
        "expires" => time() - 42000,
        "path" => $params["path"] ?: "/",
        "domain" => $params["domain"] ?? "",
        "secure" => (bool) ($params["secure"] ?? false),
        "httponly" => true,
        "samesite" => $params["samesite"] ?? "Lax"
    ]);
}

function expire_remember_cookie(): void
{
    setcookie("remember_me", "", [
        "expires" => time() - 3600,
        "path" => "/",
        "secure" => request_uses_https(),
        "httponly" => true,
        "samesite" => "Lax"
    ]);
}

function start_secure_session(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) return false;

    ini_set("session.use_only_cookies", "1");
    ini_set("session.use_strict_mode", "1");
    ini_set("session.cookie_httponly", "1");
    ini_set("session.cookie_samesite", "Lax");
    ini_set("session.cookie_secure", request_uses_https() ? "1" : "0");
    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => request_uses_https(),
        "httponly" => true,
        "samesite" => "Lax"
    ]);
    session_start();

    $timed_out = isset($_SESSION["user_id"], $_SESSION["last_activity"])
        && time() - (int) $_SESSION["last_activity"] > AUTH_IDLE_TIMEOUT_SECONDS;

    if ($timed_out) {
        $_SESSION = [];
        expire_session_cookie();
        expire_remember_cookie();
        session_destroy();
        unset($_COOKIE[session_name()], $_COOKIE["remember_me"]);
        session_id("");
        session_start();
    }

    if (isset($_SESSION["user_id"])) {
        $_SESSION["last_activity"] = time();
    }

    return $timed_out;
}
?>
