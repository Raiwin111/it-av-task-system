<?php
declare(strict_types=1);

const REMEMBER_ME_LIFETIME_SECONDS = 30 * 24 * 60 * 60;

function remember_cookie_options(int $expires_at): array
{
    return [
        "expires" => $expires_at,
        "path" => "/",
        "secure" => request_uses_https(),
        "httponly" => true,
        "samesite" => "Lax",
    ];
}

function parse_remember_cookie(?string $cookie_value = null): ?array
{
    $cookie_value ??= is_string($_COOKIE["remember_me"] ?? null) ? $_COOKIE["remember_me"] : "";
    $parts = explode("|", $cookie_value);
    if (
        count($parts) !== 2
        || !preg_match('/^[a-f0-9]{24}$/D', $parts[0])
        || !preg_match('/^[a-f0-9]{64}$/D', $parts[1])
    ) {
        return null;
    }

    return ["selector" => $parts[0], "validator" => $parts[1]];
}

function delete_remember_token_from_cookie(mysqli $conn, ?string $cookie_value = null): void
{
    $parsed = parse_remember_cookie($cookie_value);
    if (!$parsed) return;

    $stmt = $conn->prepare("DELETE FROM auth_remember_tokens WHERE selector = ?");
    $stmt->bind_param("s", $parsed["selector"]);
    $stmt->execute();
    $stmt->close();
}

function delete_user_remember_tokens(mysqli $conn, int $user_id): void
{
    $stmt = $conn->prepare("DELETE FROM auth_remember_tokens WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
}

function issue_remember_token(mysqli $conn, int $user_id): void
{
    delete_remember_token_from_cookie($conn);
    $conn->query("DELETE FROM auth_remember_tokens WHERE expires_at <= NOW()");

    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $validator_hash = hash("sha256", $validator);
    $expires_at = time() + REMEMBER_ME_LIFETIME_SECONDS;
    $expires_sql = date("Y-m-d H:i:s", $expires_at);

    $stmt = $conn->prepare(
        "INSERT INTO auth_remember_tokens (user_id, selector, validator_hash, expires_at)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param("isss", $user_id, $selector, $validator_hash, $expires_sql);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException("Unable to create Remember Me token.");
    }
    $stmt->close();

    setcookie("remember_me", $selector . "|" . $validator, remember_cookie_options($expires_at));
}

function restore_user_from_remember_token(mysqli $conn): ?array
{
    $parsed = parse_remember_cookie();
    if (!$parsed) return null;

    $stmt = $conn->prepare(
        "SELECT rt.id AS token_id, rt.validator_hash, UNIX_TIMESTAMP(rt.expires_at) AS expires_at,
                u.id, u.username, u.department, u.role, u.profile_image, u.is_approved
         FROM auth_remember_tokens AS rt
         INNER JOIN users AS u ON u.id = rt.user_id
         WHERE rt.selector = ?
           AND rt.expires_at > NOW()
           AND u.is_enabled = 1
           AND (u.lock_until IS NULL OR u.lock_until <= NOW())
         LIMIT 1"
    );
    $stmt->bind_param("s", $parsed["selector"]);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $candidate_hash = hash("sha256", $parsed["validator"]);
    if (!$row || !hash_equals((string) $row["validator_hash"], $candidate_hash)) {
        delete_remember_token_from_cookie($conn);
        return null;
    }

    // Rotate the secret part on every automatic login to limit token replay.
    $new_validator = bin2hex(random_bytes(32));
    $new_validator_hash = hash("sha256", $new_validator);
    $token_id = (int) $row["token_id"];
    $rotate_stmt = $conn->prepare(
        "UPDATE auth_remember_tokens
         SET validator_hash = ?, last_used_at = NOW()
         WHERE id = ?"
    );
    $rotate_stmt->bind_param("si", $new_validator_hash, $token_id);
    $rotate_stmt->execute();
    $rotate_stmt->close();

    $expires_at = (int) $row["expires_at"];
    setcookie(
        "remember_me",
        $parsed["selector"] . "|" . $new_validator,
        remember_cookie_options($expires_at)
    );

    unset($row["token_id"], $row["validator_hash"], $row["expires_at"]);
    return $row;
}
