<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require __DIR__ . "/../auth/session.php";
require __DIR__ . "/../config/db.php";
require __DIR__ . "/../auth/remember_tokens.php";

$failures = [];
$user = $conn->query(
    "SELECT id FROM users WHERE username = 'admin_demo' AND role = 'ADMIN' LIMIT 1"
)->fetch_assoc();
if (!$user) {
    fwrite(STDERR, "FAIL: Arm ADMIN account is missing." . PHP_EOL);
    exit(1);
}

$conn->begin_transaction();
try {
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $validator_hash = hash("sha256", $validator);
    $expires_at = date("Y-m-d H:i:s", time() + 3600);
    $user_id = (int) $user["id"];
    $stmt = $conn->prepare(
        "INSERT INTO auth_remember_tokens (user_id, selector, validator_hash, expires_at)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param("isss", $user_id, $selector, $validator_hash, $expires_at);
    $stmt->execute();
    $stmt->close();

    $_COOKIE["remember_me"] = $selector . "|" . $validator;
    $restored = restore_user_from_remember_token($conn);
    if (!$restored || (int) $restored["id"] !== $user_id) {
        $failures[] = "valid token did not restore Arm";
    }

    $rotated = $conn->query(
        "SELECT validator_hash, last_used_at
         FROM auth_remember_tokens
         WHERE selector = '" . $conn->real_escape_string($selector) . "'
         LIMIT 1"
    )->fetch_assoc();
    if (!$rotated || hash_equals($validator_hash, (string) $rotated["validator_hash"]) || empty($rotated["last_used_at"])) {
        $failures[] = "validator was not rotated";
    }

    // Replaying the pre-rotation cookie must fail and revoke the token.
    $_COOKIE["remember_me"] = $selector . "|" . $validator;
    if (restore_user_from_remember_token($conn) !== null) {
        $failures[] = "replayed validator was accepted";
    }
    $remaining = (int) $conn->query(
        "SELECT COUNT(*) AS total
         FROM auth_remember_tokens
         WHERE selector = '" . $conn->real_escape_string($selector) . "'"
    )->fetch_assoc()["total"];
    if ($remaining !== 0) {
        $failures[] = "replayed token was not revoked";
    }
} finally {
    $conn->rollback();
    unset($_COOKIE["remember_me"]);
}

if ($failures !== []) {
    foreach ($failures as $failure) echo "FAIL: {$failure}", PHP_EOL;
    exit(1);
}

echo "PASS: valid Remember Me token restores the account", PHP_EOL;
echo "PASS: validator rotates after use", PHP_EOL;
echo "PASS: replayed validator is rejected and revoked", PHP_EOL;
