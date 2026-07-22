<?php
// This file creates one reusable connection to the MySQL database.

$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "it-av-task-system";

// Change this long, private value before using the system in production.
// It signs the Remember Me cookie, so the cookie cannot be changed by a user.
$remember_me_secret = "4f7bd8cf9bd049ae9e25d7422519939149da2ee805ce85913b4051e5b7a14ac2";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Stop the current page with a simple message if MySQL cannot be reached.
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Use UTF-8 so Thai and other international text is stored correctly.
$conn->set_charset("utf8mb4");
?>
