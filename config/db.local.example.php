<?php
// Copy this file to db.local.php and fill in values for this machine only.
// db.local.php is ignored by Git and blocked from direct web access.
$db_host = "localhost";
$db_user = "replace-me";
$db_pass = "replace-me";
$db_name = "it-av-task-system";

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    error_log("Database connection failed.");
    http_response_code(503);
    exit("ระบบฐานข้อมูลไม่พร้อมให้บริการชั่วคราว");
}

$conn->set_charset("utf8mb4");
?>
