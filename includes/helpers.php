<?php
// Shared formatting and parsing helpers used across task pages.
function combine_thai_date_time(string $date_value, string $time_value): ?string
{
    if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', trim($date_value), $date_matches)) return null;
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($time_value), $time_matches)) return null;

    [, $day, $month, $year] = $date_matches;
    [, $hour, $minute] = $time_matches;
    $gregorian_year = (int) $year;
    if ($gregorian_year > 2400) $gregorian_year -= 543;

    if (
        !checkdate((int) $month, (int) $day, $gregorian_year)
        || (int) $hour > 23
        || (int) $minute > 59
    ) {
        return null;
    }

    return sprintf(
        "%04d-%02d-%02d %02d:%02d:00",
        $gregorian_year,
        (int) $month,
        (int) $day,
        (int) $hour,
        (int) $minute
    );
}

function format_thai_date_time(?string $value, bool $include_time = true): string
{
    if (!$value) return "-";

    $timestamp = strtotime($value);
    if ($timestamp === false) return "-";

    $formatted = date("d/m/", $timestamp) . (date("Y", $timestamp) + 543);
    return $include_time ? $formatted . " " . date("H:i", $timestamp) . " น." : $formatted;
}

function format_thai_date_time_input(?string $value): string
{
    return $value ? format_thai_date_time($value) : "";
}

function task_problem_is_required(string $department, string $problem): bool
{
    return strtoupper(trim($department)) === "IT"
        && in_array(trim($problem), ["", "-"], true);
}

function task_workflow_status(
    string $department,
    string $solution,
    string $requested_status = "pending",
    bool $is_new_task = false,
    bool $can_override_status = false,
    string $work_action = "",
    bool $has_finish_time = false
): string
{
    if ($can_override_status) {
        return $requested_status !== "" ? $requested_status : "pending";
    }

    if (!$is_new_task && $requested_status === "cancelled") {
        return "cancelled";
    }

    $department = strtoupper(trim($department));
    if ($department === "IT") {
        return !in_array(trim($solution), ["", "-"], true)
            ? "completed"
            : "in_progress";
    }

    if ($department === "AV") {
        $has_work_action = !in_array(trim($work_action), ["", "-"], true);
        return $has_work_action || $has_finish_time
            ? "completed"
            : "in_progress";
    }

    return $requested_status !== "" ? $requested_status : "pending";
}

function task_status_meta(string $status): array
{
    $key = strtolower(str_replace(" ", "_", trim($status)));
    $key = [
        "รอดำเนินการ" => "pending",
        "กำลังดำเนินการ" => "in_progress",
        "เสร็จสิ้น" => "completed",
        "ยกเลิก" => "cancelled"
    ][$key] ?? $key;

    $labels = [
        "pending" => "รอดำเนินการ",
        "in_progress" => "กำลังดำเนินการ",
        "completed" => "เสร็จสิ้น",
        "cancelled" => "ยกเลิก"
    ];
    $classes = [
        "pending" => "status-pending",
        "in_progress" => "status-progress",
        "completed" => "status-completed",
        "cancelled" => "status-cancelled"
    ];

    return [
        htmlspecialchars($labels[$key] ?? $status, ENT_QUOTES, "UTF-8"),
        $classes[$key] ?? "status-pending"
    ];
}

// Backward-compatible names used by existing templates while the views are
// progressively split into smaller components.
function thai_date_time(?string $value): string
{
    return format_thai_date_time($value);
}

function status_meta(string $status): array
{
    return task_status_meta($status);
}
?>
