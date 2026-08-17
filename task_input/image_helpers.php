<?php
// Shared, server-side validation for task image attachments.
function prepare_task_image_uploads(string $field_name = "task_images"): array
{
    if (!isset($_FILES[$field_name]) || !is_array($_FILES[$field_name]["name"] ?? null)) {
        return [[], null];
    }

    $allowed_types = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp"
    ];
    $prepared = [];
    $names = $_FILES[$field_name]["name"];
    $upload_errors = $_FILES[$field_name]["error"] ?? [];
    if (!is_array($upload_errors)) return [[], "ข้อมูลไฟล์อัปโหลดไม่ถูกต้อง"];

    if (count(array_filter($upload_errors, static fn($error) => $error !== UPLOAD_ERR_NO_FILE)) > 5) {
        return [[], "แนบรูปภาพได้ไม่เกิน 5 รูปต่อครั้ง"];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($names as $index => $original_name) {
        if (!is_string($original_name)) return [[], "ข้อมูลไฟล์อัปโหลดไม่ถูกต้อง"];
        $error = (int) ($_FILES[$field_name]["error"][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) return [[], "อัปโหลดรูปภาพไม่สำเร็จ กรุณาลองใหม่"];

        $temporary_path = $_FILES[$field_name]["tmp_name"][$index] ?? "";
        if (!is_string($temporary_path) || $temporary_path === "") return [[], "ข้อมูลไฟล์อัปโหลดไม่ถูกต้อง"];
        $file_size = (int) ($_FILES[$field_name]["size"][$index] ?? 0);
        $mime_type = $finfo->file($temporary_path) ?: "";
        if ($file_size <= 0 || $file_size > 5 * 1024 * 1024 || !isset($allowed_types[$mime_type])) {
            return [[], "รูปภาพต้องเป็น JPG, PNG หรือ WebP และมีขนาดไม่เกิน 5 MB ต่อรูป"];
        }

        $prepared[] = [
            "temporary_path" => $temporary_path,
            "original_name" => mb_substr(basename($original_name), 0, 255),
            "mime_type" => $mime_type,
            "extension" => $allowed_types[$mime_type],
            "file_size" => $file_size
        ];
    }

    return [$prepared, null];
}

function store_task_images(mysqli $conn, int $task_id, int $user_id, array $images): bool
{
    if (!$images) return true;
    $upload_directory = __DIR__ . "/../uploads/tasks";
    if (!is_dir($upload_directory) && !mkdir($upload_directory, 0755, true)) return false;

    $stored_paths = [];
    $insert_stmt = $conn->prepare("INSERT INTO task_images (task_id, file_path, original_name, mime_type, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
    $conn->begin_transaction();
    $completed = true;
    foreach ($images as $image) {
        $file_name = bin2hex(random_bytes(16)) . "." . $image["extension"];
        $absolute_path = $upload_directory . "/" . $file_name;
        if (!move_uploaded_file($image["temporary_path"], $absolute_path)) {
            $completed = false;
            break;
        }

        $stored_paths[] = $absolute_path;
        $relative_path = "uploads/tasks/" . $file_name;
        $original_name = $image["original_name"];
        $mime_type = $image["mime_type"];
        $file_size = (int) $image["file_size"];
        $insert_stmt->bind_param(
            "isssii",
            $task_id,
            $relative_path,
            $original_name,
            $mime_type,
            $file_size,
            $user_id
        );
        if (!$insert_stmt->execute()) {
            $completed = false;
            break;
        }
    }
    $insert_stmt->close();
    if ($completed) {
        $conn->commit();
        return true;
    }

    $conn->rollback();
    foreach ($stored_paths as $stored_path) @unlink($stored_path);
    return false;
}
?>
