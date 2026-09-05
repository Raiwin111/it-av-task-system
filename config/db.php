<?php
// Load machine-local database credentials without committing them to Git.
$local_database_config = __DIR__ . "/db.local.php";

if (!is_file($local_database_config)) {
    error_log("Missing local database configuration: " . $local_database_config);
    http_response_code(503);
    exit("ระบบไม่พร้อมให้บริการชั่วคราว กรุณาติดต่อผู้ดูแลระบบ");
}

require $local_database_config;

if (!isset($conn) || !($conn instanceof mysqli)) {
    error_log("Local database configuration did not create a mysqli connection.");
    http_response_code(503);
    exit("ระบบไม่พร้อมให้บริการชั่วคราว กรุณาติดต่อผู้ดูแลระบบ");
}

// Keep application timestamps and MySQL NOW()/CURRENT_TIMESTAMP aligned.
date_default_timezone_set("Asia/Bangkok");
$conn->query("SET time_zone = '+07:00'");
?>
