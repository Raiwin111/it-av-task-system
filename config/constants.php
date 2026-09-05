<?php
// Shared display-only constants for consistent UI labels across the application.
$departments = ["IT", "AV"];

// Sourced from task_categories (single source of truth after
// config/task_category_integrity_migration.sql). "-" is intentionally
// excluded here: pages that need it add it as a fixed "ไม่ระบุ" option
// themselves, so existing dropdown markup does not need to change.
$problem_category_options = [];
if (isset($conn) && $conn instanceof mysqli) {
    $category_result = $conn->query(
        "SELECT code, display_name FROM task_categories
         WHERE is_enabled = 1 AND code <> '-'
         ORDER BY sort_order, display_name"
    );
    if ($category_result) {
        while ($category_row = $category_result->fetch_assoc()) {
            $problem_category_options[$category_row["code"]] = $category_row["display_name"];
        }
    }
}
if (!$problem_category_options) {
    // Fallback so the app stays usable if task_categories is empty/unreachable.
    $problem_category_options = [
        "Hardware" => "Hardware",
        "Software" => "Software",
        "Customer" => "Customer"
    ];
}

$task_status_options = [
    "pending" => "รอดำเนินการ",
    "in_progress" => "กำลังดำเนินการ",
    "completed" => "เสร็จสิ้น",
    "cancelled" => "ยกเลิก"
];
