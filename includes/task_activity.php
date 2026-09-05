<?php

function record_task_activity(
    mysqli $conn,
    int $task_id,
    string $event_type,
    string $description,
    ?string $old_status = null,
    ?string $new_status = null,
    ?array $details = null
): bool {
    if ($task_id <= 0 || $event_type === "" || $description === "") return false;

    $actor_user_id = isset($_SESSION["user_id"]) ? (int) $_SESSION["user_id"] : null;
    $actor_name = trim((string) ($_SESSION["username"] ?? ""));
    if ($actor_name === "") $actor_name = "ระบบ";

    $details_json = $details === null ? null : json_encode(
        $details,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $stmt = $conn->prepare(
        "INSERT INTO task_activity_logs
            (task_id, actor_user_id, actor_name, event_type, description, old_status, new_status, details)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        "iissssss",
        $task_id,
        $actor_user_id,
        $actor_name,
        $event_type,
        $description,
        $old_status,
        $new_status,
        $details_json
    );
    $saved = $stmt->execute();
    $stmt->close();
    return $saved;
}

function task_activity_changed_labels(array $before, array $after): array
{
    $fields = [
        "title" => "ชื่องาน",
        "category" => "ประเภทปัญหา",
        "department" => "ทีม",
        "responsible_name" => "ผู้รับผิดชอบ",
        "location" => "สถานที่",
        "work_description" => "รายละเอียดงาน",
        "work_action" => "การดำเนินงาน",
        "problem" => "ปัญหาที่พบ",
        "solution" => "วิธีแก้ไขปัญหา",
        "start_time" => "เวลาเริ่ม",
        "finish_time" => "เวลาสิ้นสุด",
        "remark" => "หมายเหตุ"
    ];

    $changed = [];
    foreach ($fields as $field => $label) {
        $before_value = trim((string) ($before[$field] ?? ""));
        $after_value = trim((string) ($after[$field] ?? ""));
        if ($before_value !== $after_value) $changed[] = $label;
    }
    return $changed;
}

function task_activity_changed_values(array $before, array $after): array
{
    $fields = [
        "title" => "ชื่องาน",
        "category" => "ประเภทปัญหา",
        "department" => "ทีม",
        "responsible_name" => "ผู้รับผิดชอบ",
        "location" => "สถานที่",
        "work_description" => "รายละเอียดงาน",
        "work_action" => "การดำเนินงาน",
        "problem" => "ปัญหาที่พบ",
        "solution" => "วิธีแก้ไขปัญหา",
        "start_time" => "เวลาเริ่ม",
        "finish_time" => "เวลาสิ้นสุด",
        "remark" => "หมายเหตุ"
    ];

    $changes = [];
    foreach ($fields as $field => $label) {
        $before_value = trim((string) ($before[$field] ?? ""));
        $after_value = trim((string) ($after[$field] ?? ""));
        if ($before_value === $after_value) continue;
        // Keep the expandable history compact: long values are cut to 300 chars.
        $changes[] = [
            "label" => $label,
            "before" => mb_substr($before_value === "" ? "(ว่าง)" : $before_value, 0, 300),
            "after" => mb_substr($after_value === "" ? "(ว่าง)" : $after_value, 0, 300)
        ];
    }
    return $changes;
}

function record_task_update_activities(
    mysqli $conn,
    int $task_id,
    array $before,
    array $after
): bool {
    $saved = true;
    $changed_labels = task_activity_changed_labels($before, $after);
    if ($changed_labels) {
        $saved = record_task_activity(
            $conn,
            $task_id,
            "updated",
            "แก้ไขข้อมูล: " . implode(", ", $changed_labels),
            null,
            null,
            ["changes" => task_activity_changed_values($before, $after)]
        ) && $saved;
    }

    $old_status = (string) ($before["status"] ?? "");
    $new_status = (string) ($after["status"] ?? "");
    if ($old_status !== $new_status) {
        [$old_label] = task_status_meta($old_status);
        [$new_label] = task_status_meta($new_status);
        $saved = record_task_activity(
            $conn,
            $task_id,
            "status_changed",
            "เปลี่ยนสถานะจาก {$old_label} เป็น {$new_label}",
            $old_status,
            $new_status
        ) && $saved;
    }

    return $saved;
}

function load_task_activities(mysqli $conn, array $task_ids, int $limit_per_task = 20): array
{
    $task_ids = array_values(array_unique(array_filter(
        array_map("intval", $task_ids),
        static fn(int $task_id): bool => $task_id > 0
    )));
    if (!$task_ids) return [];

    $limit_per_task = max(1, min(100, $limit_per_task));
    $id_list = implode(",", $task_ids);
    $result = $conn->query(
        "SELECT id, task_id, actor_user_id, actor_name, event_type,
                description, old_status, new_status, details, created_at
         FROM task_activity_logs
         WHERE task_id IN ({$id_list})
         ORDER BY created_at DESC, id DESC"
    );

    $grouped = [];
    while ($row = $result->fetch_assoc()) {
        $task_id = (int) $row["task_id"];
        if (count($grouped[$task_id] ?? []) >= $limit_per_task) continue;
        $row["details"] = $row["details"] ? json_decode((string) $row["details"], true) : null;
        $grouped[$task_id][] = $row;
    }
    return $grouped;
}

?>
