<?php
// Central authorization helpers. Authentication remains in auth_check.php.
function current_role(): string
{
    return strtoupper((string) ($_SESSION["role"] ?? "USER"));
}

function current_department(): string
{
    return (string) ($_SESSION["department"] ?? "");
}

function can_manage_users(): bool
{
    return current_role() === "ADMIN";
}

function is_account_approved(): bool
{
    // Internal accounts are created by ADMIN and have no approval queue.
    // Authentication and enabled-account checks remain enforced by auth_check.php.
    return true;
}

function can_manage_all_tasks(): bool
{
    return in_array(current_role(), ["SUPER", "ADMIN"], true);
}

function can_access_task_department(string $task_department): bool
{
    if (!is_account_approved()) return false;
    if (can_manage_all_tasks()) return true;

    return strtoupper(trim($task_department)) === strtoupper(trim(current_department()));
}

function can_view_task(array $task): bool
{
    return isset($task["department"])
        && can_access_task_department((string) $task["department"]);
}

function can_edit_task(array $task): bool
{
    // Team members collaborate on every task in their assigned team.
    return can_view_task($task);
}

function can_delete_task(array $task): bool
{
    // Deletion follows team scope, not the task creator.
    return can_view_task($task);
}
?>
