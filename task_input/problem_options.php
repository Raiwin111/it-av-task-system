<?php
require_once __DIR__ . "/../auth/auth_check.php";
require_once __DIR__ . "/../auth/authorization.php";
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/constants.php";

header("Content-Type: application/json; charset=utf-8");

if (empty($_SESSION["task_problem_options_csrf"])) {
    $_SESSION["task_problem_options_csrf"] = bin2hex(random_bytes(32));
}

$role = strtoupper($_SESSION["role"] ?? "USER");
$session_department = (string) ($_SESSION["department"] ?? "");

function problem_options_response(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function requested_problem_department(array $departments, string $role, string $session_department): string
{
    $requested_department = $_REQUEST["department"] ?? $session_department;
    $department = is_string($requested_department) ? $requested_department : "";
    if (!in_array($department, $departments, true)) problem_options_response(422, ["message" => "ทีมไม่ถูกต้อง"]);
    if ($role === "USER" && $department !== $session_department) problem_options_response(403, ["message" => "คุณไม่มีสิทธิ์จัดการรายการของทีมนี้"]);
    return $department;
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "GET") {
    if (!is_account_approved()) {
        problem_options_response(200, ["options" => []]);
    }
    $department = requested_problem_department($departments, $role, $session_department);
    $stmt = $conn->prepare("SELECT id, problem_text FROM team_problem_options WHERE department = ? ORDER BY problem_text ASC, id ASC");
    $stmt->bind_param("s", $department);
    $stmt->execute();
    $options = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    problem_options_response(200, ["options" => $options]);
}

$submitted_csrf = is_string($_POST["csrf_token"] ?? null) ? $_POST["csrf_token"] : "";
if (!hash_equals($_SESSION["task_problem_options_csrf"], $submitted_csrf)) {
    problem_options_response(403, ["message" => "ไม่สามารถยืนยันคำขอได้"]);
}
if (!is_account_approved()) {
    problem_options_response(403, ["message" => "บัญชีนี้ยังไม่มีสิทธิ์แก้ไขข้อมูล"]);
}

$department = requested_problem_department($departments, $role, $session_department);
$action = is_string($_POST["action"] ?? null) ? $_POST["action"] : "";

if ($action === "add") {
    $problem_text = is_string($_POST["problem_text"] ?? null) ? trim($_POST["problem_text"]) : "";
    if ($problem_text === "" || $problem_text === "-" || mb_strlen($problem_text) > 255) {
        problem_options_response(422, ["message" => "ข้อความปัญหาไม่ถูกต้อง"]);
    }

    $created_by = (int) ($_SESSION["user_id"] ?? 0);
    $stmt = $conn->prepare("INSERT IGNORE INTO team_problem_options (department, problem_text, created_by) VALUES (?, ?, ?)");
    $stmt->bind_param("ssi", $department, $problem_text, $created_by);
    $stmt->execute();
    $stmt->close();
    problem_options_response(200, ["message" => "บันทึกรายการแล้ว"]);
}

if ($action === "delete") {
    $id_value = $_POST["id"] ?? 0;
    $id = is_scalar($id_value) ? (int) $id_value : 0;
    if ($id <= 0) problem_options_response(422, ["message" => "ไม่พบรายการที่ต้องการลบ"]);

    $stmt = $conn->prepare("DELETE FROM team_problem_options WHERE id = ? AND department = ?");
    $stmt->bind_param("is", $id, $department);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();
    if (!$deleted) problem_options_response(404, ["message" => "ไม่พบรายการในทีมนี้"]);
    problem_options_response(200, ["message" => "ลบรายการแล้ว"]);
}

problem_options_response(422, ["message" => "คำขอไม่ถูกต้อง"]);
