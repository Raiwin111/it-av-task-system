<?php
// Open this page once to add the four requested starter users to the users table.

require_once __DIR__ . "/config/db.php";

$starter_users = [
    ["ADMIN001", "1234", "IT", "ADMIN"],
    ["SUPER001", "1234", "IT", "SUPER"],
    ["IT001", "1234", "IT", "USER"],
    ["AV001", "1234", "AV", "USER"]
];

$messages = [];

foreach ($starter_users as $starter_user) {
    [$username, $plain_password, $department, $role] = $starter_user;

    // Check first so opening this page again does not create the same username twice.
    $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $check_stmt->bind_param("s", $username);
    $check_stmt->execute();
    $already_exists = $check_stmt->get_result()->num_rows > 0;
    $check_stmt->close();

    if ($already_exists) {
        $messages[] = $username . " already exists.";
        continue;
    }

    // Store only a secure password hash, never the plain password.
    $password_hash = password_hash($plain_password, PASSWORD_DEFAULT);
    $insert_stmt = $conn->prepare("INSERT INTO users (username, password, department, role) VALUES (?, ?, ?, ?)");
    $insert_stmt->bind_param("ssss", $username, $password_hash, $department, $role);

    if ($insert_stmt->execute()) {
        $messages[] = $username . " was created.";
    } else {
        $messages[] = $username . " could not be created.";
    }

    $insert_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Starter Users</title>
</head>
<body>
    <!-- Show the result of creating each starter user. -->
    <h1>Create Starter Users</h1>
    <?php foreach ($messages as $message): ?>
        <p><?php echo htmlspecialchars($message, ENT_QUOTES, "UTF-8"); ?></p>
    <?php endforeach; ?>

    <p><a href="auth/login.php">Go to Login</a></p>
</body>
</html>
