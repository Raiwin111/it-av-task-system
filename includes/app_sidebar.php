    <?php
    $active_nav = $active_nav ?? "task_input";
    $sidebar_can_manage_users = strtoupper((string) ($_SESSION["role"] ?? "USER")) === "ADMIN";
    ?>
    <aside class="sidebar desktop-sidebar d-none p-3">
        <nav class="nav flex-column" aria-label="เมนูหลัก">
            <div class="sidebar-label fw-semibold px-3 mb-2 mt-1">MAIN</div>
            <a class="nav-link<?php echo $active_nav === "dashboard" ? " active" : ""; ?>" href="../dashboard/"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
            <a class="nav-link<?php echo $active_nav === "task_input" ? " active" : ""; ?>" href="../task_input/"><i class="bi bi-plus-square me-2"></i>บันทึกงาน</a>
            <a class="nav-link<?php echo $active_nav === "report" ? " active" : ""; ?>" href="../report/"><i class="bi bi-card-list me-2"></i>รายการงาน</a>
            <div class="sidebar-label fw-semibold px-3 mb-2 mt-4">SYSTEM</div>
            <a class="nav-link<?php echo $active_nav === "config" ? " active" : ""; ?>" href="../config/"><i class="bi <?php echo $sidebar_can_manage_users ? "bi-gear" : "bi-key"; ?> me-2"></i><?php echo $sidebar_can_manage_users ? "ตั้งค่าระบบ" : "ตั้งค่าบัญชี"; ?></a>
            <a class="nav-link<?php echo $active_nav === "help" ? " active" : ""; ?>" href="../help/"><i class="bi bi-question-circle me-2"></i>คู่มือ</a>
        </nav>
    </aside>
    <div class="offcanvas offcanvas-start offcanvas-sidebar sidebar text-white" tabindex="-1" id="sidebarMenu" aria-labelledby="sidebarMenuLabel">
        <div class="offcanvas-header border-bottom border-light border-opacity-25">
            <h5 class="offcanvas-title" id="sidebarMenuLabel">เมนู</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="ปิดเมนู"></button>
        </div>
        <div class="offcanvas-body p-3">
            <nav class="nav flex-column" aria-label="เมนูสำหรับมือถือ">
                <a class="nav-link<?php echo $active_nav === "dashboard" ? " active" : ""; ?>" href="../dashboard/"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
                <a class="nav-link<?php echo $active_nav === "task_input" ? " active" : ""; ?>" href="../task_input/"><i class="bi bi-plus-square me-2"></i>บันทึกงาน</a>
                <a class="nav-link<?php echo $active_nav === "report" ? " active" : ""; ?>" href="../report/"><i class="bi bi-card-list me-2"></i>รายการงาน</a>
                <a class="nav-link mt-3<?php echo $active_nav === "config" ? " active" : ""; ?>" href="../config/"><i class="bi <?php echo $sidebar_can_manage_users ? "bi-gear" : "bi-key"; ?> me-2"></i><?php echo $sidebar_can_manage_users ? "ตั้งค่าระบบ" : "ตั้งค่าบัญชี"; ?></a>
                <a class="nav-link<?php echo $active_nav === "help" ? " active" : ""; ?>" href="../help/"><i class="bi bi-question-circle me-2"></i>คู่มือ</a>
            </nav>
        </div>
    </div>
