<?php
declare(strict_types=1);

/**
 * Translate internal role codes for display without changing stored values.
 */
function ui_role_label(string $role): string
{
    return match (strtoupper(trim($role))) {
        "ADMIN" => "ผู้ดูแลระบบ",
        "SUPER" => "หัวหน้าทีม",
        default => "ผู้ใช้งาน",
    };
}
