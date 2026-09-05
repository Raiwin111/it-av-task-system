<?php
// Reuse the existing authentication guard for pages inside the application.
require_once __DIR__ . "/../auth/auth_check.php";
require_once __DIR__ . "/ui_helpers.php";

$layout_username = htmlspecialchars($_SESSION["username"] ?? "Administrator", ENT_QUOTES, "UTF-8");
$layout_role_code = strtoupper((string) ($_SESSION["role"] ?? "USER"));
$layout_role = htmlspecialchars(ui_role_label($layout_role_code), ENT_QUOTES, "UTF-8");
$layout_department = htmlspecialchars((string) ($_SESSION["department"] ?? ""), ENT_QUOTES, "UTF-8");
$layout_badge_text = $layout_department !== "" ? $layout_department : $layout_role;
$layout_profile_image = $_SESSION["profile_image"] ?? "";
$logout_csrf_token = $_SESSION["logout_csrf_token"] ??= bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($app_page_title ?? "บันทึกงาน | IT / AV Task Management System", ENT_QUOTES, "UTF-8"); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --navy: #0f172a; --blue: #2563eb; --page-bg: #eef1f9; --sidebar-width: 260px; }
        body { color: #26394d; background: var(--page-bg); font-family: "Poppins", "Inter", "Segoe UI", sans-serif; }
        .topbar { position: fixed; top: 0; right: 0; left: 0; z-index: 1040; height: 82px; color: #e5edf7; background: #111827; box-shadow: 0 3px 16px rgba(2, 6, 23, .35); }
        .brand-mark { width: 46px; height: 46px; color: #fff; background: linear-gradient(135deg, #06b6d4, #4f46e5); box-shadow: 0 5px 14px rgba(79, 70, 229, .38); }.brand-mark i { font-size: 1.25rem; }.brand-title { color: #f8fafc; font-size: 1.22rem; letter-spacing: -.01em; }
        .profile-avatar { width: 50px; height: 50px; color: #fff; background: linear-gradient(135deg, #2563eb, #4f46e5); box-shadow: 0 0 0 2px rgba(255, 255, 255, .18); }.profile-avatar > i { font-size: 1.35rem; }.user-detail { line-height: 1.2; }.profile-username { color: #f8fafc; font-size: 1.05rem; }.role-badge { display: inline-block; margin-top: .25rem; padding: .22rem .58rem; color: #fff; border-radius: 999px; background: #0d9488; font-size: .8rem; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; }
        .sidebar { width: var(--sidebar-width); background: #0f172a; }.desktop-sidebar { position: fixed; top: 0; left: 0; z-index: 1030; height: 100vh; padding-top: 5.5rem !important; overflow-y: auto; box-shadow: 4px 0 16px rgba(2, 6, 23, .18); }.sidebar .nav-link { color: #cbd5e1; border-radius: .55rem; font-weight: 500; padding: .78rem 1rem; margin-bottom: .25rem; white-space: nowrap; }.sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: #1e293b; }.sidebar .nav-link i { width: 1.5rem; font-size: 1.05rem; }.sidebar-label { color: #94a3b8; font-size: .68rem; letter-spacing: .1em; }
        .main-content { min-width: 0; }.page-heading { color: var(--navy); }.page-subtitle { color: #66788a; }.form-card { border: 0; border-radius: .9rem; box-shadow: 0 5px 18px rgba(26, 57, 89, .07); }.form-card .card-header { padding: 1.1rem 1.5rem; border-bottom: 1px solid #e8edf3; background: #fff; }.section-title { color: var(--navy); font-size: 1.05rem; font-weight: 700; }.section-icon { width: 34px; height: 34px; color: var(--blue); border-radius: .6rem; background: #e5edff; }
        .form-label { color: #405367; font-size: .9rem; font-weight: 600; }.form-control, .form-select { min-height: 44px; border-color: #dce5ef; border-radius: .55rem; color: #405367; }.form-control:focus, .form-select:focus { border-color: #93b4f5; box-shadow: 0 0 0 .22rem rgba(37, 99, 235, .15); }.form-control::placeholder { color: #a5b1bd; }.required-mark { color: #dc3545; }.form-actions { border-top: 1px solid #e8edf3; }.btn { border-radius: .55rem; font-weight: 600; }.btn-primary { border-color: #2563eb; background: #2563eb; }.btn-primary:hover { border-color: #1d4ed8; background: #1d4ed8; }
        .topbar + .app-shell { padding-top: 82px; }
        @media (min-width: 992px) { .desktop-sidebar { display: block !important; }.offcanvas-sidebar { display: none; }.main-content { margin-left: var(--sidebar-width); } }
        @media (max-width: 575.98px) { .topbar { height: 68px; }.topbar + .app-shell { padding-top: 68px; }.brand-mark { width: 40px; height: 40px; }.brand-title { max-width: 170px; font-size: .92rem; line-height: 1.2; }.profile-avatar { width: 40px; height: 40px; }.hide-mobile { display: none; }.main-content { padding: 1.5rem !important; }.form-card .card-header, .form-card .card-body { padding-left: 1rem !important; padding-right: 1rem !important; } }
        /* Shared enterprise visual refinements. */
        body { background: #eef1f9; font-size: 1.05rem; }
        .topbar { background: linear-gradient(100deg, #1e1b4b 0%, #1e3a8a 55%, #1d4ed8 145%); box-shadow: 0 4px 22px rgba(30, 27, 75, .45); }
        .topbar::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 3px; background: linear-gradient(90deg, #06b6d4, #4f46e5 55%, rgba(79, 70, 229, 0)); }
        .brand-title { color: #fff; }.sidebar { background: linear-gradient(180deg, #1e1b4b, #172554 55%, #101b3d); box-shadow: 4px 0 22px rgba(23, 23, 74, .38); }
        .sidebar .nav-link { color: #c7d2fe; font-size: 1.05rem; transition: background .2s ease, color .2s ease, transform .2s ease; }.sidebar .nav-link i { color: #7dd3fc; }.sidebar .nav-link:hover { color: #fff; background: rgba(99, 102, 241, .18); transform: translateX(2px); }.sidebar .nav-link.active { color: #fff; background: linear-gradient(90deg, rgba(79, 70, 229, .95), rgba(37, 99, 235, .5)); box-shadow: inset 3px 0 0 #22d3ee, 0 6px 16px rgba(30, 58, 138, .35); }.sidebar-label { color: #8f9fd0; font-size: .78rem; }
        .role-badge { box-shadow: 0 1px 0 rgba(255,255,255,.12) inset; }.form-card { background: #fbfcfe; border: 1px solid #d9e3ee; box-shadow: 0 8px 24px rgba(26, 57, 89, .10); }.form-card .card-header { background: #f7f9fc; border-bottom-color: #d9e3ee; }.section-title { font-size: 1.22rem; }.section-icon { box-shadow: 0 3px 9px rgba(37, 99, 235, .14); }.form-label { font-size: 1.02rem; }.form-control, .form-select { font-size: 1rem; border-color: #cbd8e6; background-color: #fff; }.form-text { font-size: .9rem; }.btn { font-size: 1rem; }.badge { font-size: .86rem; box-shadow: 0 1px 2px rgba(15, 23, 42, .10); }.badge.status-pending { color: #8a5a10; background: #fff2d9; }.badge.status-progress { color: #4e3e97; background: #eeeaff; }.badge.status-completed { color: #176a4a; background: #def4e9; }.badge.status-cancelled { color: #8c3941; background: #fce8ea; }.table { font-size: 1rem; }.table thead th { font-size: .9rem; color: #52677f; background: #edf1fa; }
    </style>
    <?php foreach (($app_stylesheets ?? []) as $app_stylesheet): ?>
        <link href="<?php echo htmlspecialchars((string) $app_stylesheet, ENT_QUOTES, "UTF-8"); ?>" rel="stylesheet">
    <?php endforeach; ?>
</head>
<body>
    <header class="topbar d-flex align-items-center px-3 px-lg-4">
        <button class="btn btn-light d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu" aria-label="เปิดเมนู"><i class="bi bi-list fs-5"></i></button>
        <a class="d-flex align-items-center text-decoration-none" href="../dashboard/"><span class="brand-mark rounded-3 d-inline-flex align-items-center justify-content-center me-2"><i class="bi bi-grid-1x2-fill"></i></span><span class="brand-title fw-bold">IT / AV Task Management System</span></a>
        <div class="ms-auto d-flex align-items-center gap-3"><a class="d-flex align-items-center gap-2 text-decoration-none" href="../account_settings/#profileSettings" data-bs-toggle="tooltip" title="Account Settings"><span class="profile-avatar rounded-circle d-inline-flex align-items-center justify-content-center overflow-hidden"><?php if ($layout_profile_image !== ""): ?><img src="../<?php echo htmlspecialchars($layout_profile_image, ENT_QUOTES, "UTF-8"); ?>" alt="รูปโปรไฟล์" class="w-100 h-100 object-fit-cover"><?php else: ?><i class="bi bi-person-fill"></i><?php endif; ?></span><span class="user-detail hide-mobile"><span class="profile-username fw-semibold small d-block"><?php echo $layout_username; ?></span><span class="role-badge" title="ทีม: <?php echo htmlspecialchars($layout_department, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($layout_badge_text, ENT_QUOTES, "UTF-8"); ?></span></span></a><form method="post" action="../auth/logout.php" class="m-0"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($logout_csrf_token, ENT_QUOTES, "UTF-8"); ?>"><button class="btn btn-outline-danger btn-sm px-3" type="submit"><i class="bi bi-box-arrow-right me-1"></i><span class="hide-mobile">ออกจากระบบ</span></button></form></div>
    </header>