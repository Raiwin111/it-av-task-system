<?php
// Reuse the existing authentication guard for pages inside the application.
require_once __DIR__ . "/../auth/auth_check.php";

$layout_username = htmlspecialchars($_SESSION["username"] ?? "Administrator", ENT_QUOTES, "UTF-8");
$layout_role = htmlspecialchars($_SESSION["role"] ?? "USER", ENT_QUOTES, "UTF-8");
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บันทึกงาน | IT / AV Task Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --navy: #0f172a; --blue: #1769c2; --page-bg: #f4f7fb; --sidebar-width: 260px; }
        body { color: #26394d; background: var(--page-bg); font-family: "Poppins", "Inter", "Segoe UI", sans-serif; }
        .topbar { position: fixed; top: 0; right: 0; left: 0; z-index: 1040; height: 72px; color: #e5edf7; background: #111827; box-shadow: 0 3px 16px rgba(2, 6, 23, .35); }
        .brand-mark { width: 38px; height: 38px; color: #fff; background: linear-gradient(135deg, #237bd1, #103d6d); }.brand-title { color: #f8fafc; font-size: 1rem; letter-spacing: -.01em; }
        .profile-avatar { width: 40px; height: 40px; color: #fff; background: #1e3a5f; }.user-detail { line-height: 1.2; }.profile-username { color: #f8fafc; }.role-badge { display: inline-block; margin-top: .2rem; padding: .18rem .5rem; color: #fff; border-radius: 999px; background: #134e4a; font-size: .69rem; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; }
        .sidebar { width: var(--sidebar-width); background: #0f172a; }.desktop-sidebar { position: fixed; top: 0; left: 0; z-index: 1030; height: 100vh; padding-top: 5.5rem !important; overflow-y: auto; box-shadow: 4px 0 16px rgba(2, 6, 23, .18); }.sidebar .nav-link { color: #cbd5e1; border-radius: .55rem; font-weight: 500; padding: .78rem 1rem; margin-bottom: .25rem; white-space: nowrap; }.sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: #1e293b; }.sidebar .nav-link i { width: 1.5rem; font-size: 1.05rem; }.sidebar-label { color: #94a3b8; font-size: .68rem; letter-spacing: .1em; }
        .main-content { min-width: 0; }.page-heading { color: var(--navy); }.page-subtitle { color: #66788a; }.form-card { border: 0; border-radius: .9rem; box-shadow: 0 5px 18px rgba(26, 57, 89, .07); }.form-card .card-header { padding: 1.1rem 1.5rem; border-bottom: 1px solid #e8edf3; background: #fff; }.section-title { color: var(--navy); font-size: 1.05rem; font-weight: 700; }.section-icon { width: 34px; height: 34px; color: var(--blue); border-radius: .6rem; background: #e8f2fd; }
        .form-label { color: #405367; font-size: .9rem; font-weight: 600; }.form-control, .form-select { min-height: 44px; border-color: #dce5ef; border-radius: .55rem; color: #405367; }.form-control:focus, .form-select:focus { border-color: #78aee2; box-shadow: 0 0 0 .22rem rgba(23, 105, 194, .13); }.form-control::placeholder { color: #a5b1bd; }.required-mark { color: #dc3545; }.form-actions { border-top: 1px solid #e8edf3; }.btn { border-radius: .55rem; font-weight: 600; }.btn-primary { border-color: #1769c2; background: #1769c2; }.btn-primary:hover { border-color: #1058a7; background: #1058a7; }
        .topbar + .app-shell { padding-top: 72px; }
        @media (min-width: 992px) { .desktop-sidebar { display: block !important; }.offcanvas-sidebar { display: none; }.main-content { margin-left: var(--sidebar-width); } }
        @media (max-width: 575.98px) { .topbar { height: 64px; }.topbar + .app-shell { padding-top: 64px; }.brand-title { font-size: .85rem; }.hide-mobile { display: none; }.main-content { padding: 1.5rem !important; }.form-card .card-header, .form-card .card-body { padding-left: 1rem !important; padding-right: 1rem !important; } }
    </style>
</head>
<body>
    <header class="topbar d-flex align-items-center px-3 px-lg-4">
        <button class="btn btn-light d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu" aria-label="Open navigation"><i class="bi bi-list fs-5"></i></button>
        <a class="d-flex align-items-center text-decoration-none" href="../dashboard/"><span class="brand-mark rounded-3 d-inline-flex align-items-center justify-content-center me-2"><i class="bi bi-grid-1x2-fill"></i></span><span class="brand-title fw-bold">IT / AV Task Management System</span></a>
        <div class="ms-auto d-flex align-items-center gap-3"><div class="d-flex align-items-center gap-2"><span class="profile-avatar rounded-circle d-inline-flex align-items-center justify-content-center"><i class="bi bi-person-fill"></i></span><div class="user-detail hide-mobile"><div class="profile-username fw-semibold small"><?php echo $layout_username; ?></div><span class="role-badge"><?php echo $layout_role; ?></span></div></div><form method="post" action="../auth/logout.php" class="m-0"><button class="btn btn-outline-danger btn-sm px-3" type="submit"><i class="bi bi-box-arrow-right me-1"></i><span class="hide-mobile">Logout</span></button></form></div>
    </header>
