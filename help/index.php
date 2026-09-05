<?php
require_once __DIR__ . "/../auth/auth_check.php";

$app_page_title = "คู่มือ | IT / AV Task Management System";
$active_nav = "help";
require_once __DIR__ . "/../includes/app_header.php";
?>
<div class="app-shell d-flex">
    <?php require_once __DIR__ . "/../includes/app_sidebar.php"; ?>
    <main class="main-content flex-grow-1 p-4 p-lg-5 help-page">
        <div class="mb-4">
            <h1 class="page-heading h3 fw-bold mb-1">คู่มือการใช้งานระบบ</h1>
            <p class="page-subtitle mb-0">IT / AV Task Management System</p>
        </div>

        <section class="card form-card help-intro mb-4">
            <div class="card-body p-4 p-lg-5">
                <div class="row align-items-center g-4">
                    <div class="col-lg-8">
                        <span class="help-kicker"><i class="bi bi-book me-1"></i>เริ่มต้นใช้งาน</span>
                        <h2 class="page-heading h3 fw-bold mt-3 mb-2">คู่มือนี้มีหน้าที่อะไร?</h2>
                        <p class="mb-0 text-secondary">คู่มือนี้ช่วยให้ผู้ใช้งานเข้าใจแต่ละหน้าของระบบ ตั้งแต่การติดตามภาพรวมงาน การบันทึกงาน การค้นหารายงาน ไปจนถึงการตั้งค่าระบบ โดยอธิบายว่าหน้าใดมีข้อมูลอะไร และสามารถใช้งานอะไรได้บ้าง</p>
                    </div>
                    <div class="col-lg-4 text-lg-end">
                        <div class="help-hero-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-journal-text"></i></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="card form-card mb-4">
            <div class="card-header d-flex align-items-center gap-2">
                <span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-signpost-split"></i></span>
                <div><h2 class="section-title mb-0">เส้นทางการใช้งาน</h2><p class="text-muted small mb-0">เริ่มจากภาพรวม แล้วบันทึกและติดตามงาน</p></div>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-6 col-xl-3"><a class="help-route" href="../dashboard/"><span class="route-icon text-primary"><i class="bi bi-speedometer2"></i></span><span><strong>Dashboard</strong><small>ดูภาพรวมและงานล่าสุด</small></span></a></div>
                    <div class="col-md-6 col-xl-3"><a class="help-route" href="../task_input/"><span class="route-icon text-success"><i class="bi bi-plus-square"></i></span><span><strong>บันทึกงาน</strong><small>เพิ่มข้อมูลและรายละเอียดงาน</small></span></a></div>
                    <div class="col-md-6 col-xl-3"><a class="help-route" href="../report/"><span class="route-icon text-warning"><i class="bi bi-card-list"></i></span><span><strong>Report</strong><small>ค้นหา ดูรายละเอียด และแก้ไขงาน</small></span></a></div>
                    <div class="col-md-6 col-xl-3"><a class="help-route" href="../account_settings/"><span class="route-icon text-info"><i class="bi bi-person-gear"></i></span><span><strong>Account Settings</strong><small>จัดการข้อมูลส่วนตัวและเปลี่ยนรหัสผ่าน</small></span></a></div>
                    <?php if (strtoupper((string) ($_SESSION["role"] ?? "USER")) === "ADMIN"): ?><div class="col-md-6 col-xl-3"><a class="help-route" href="../config/"><span class="route-icon text-warning"><i class="bi bi-sliders"></i></span><span><strong>System Config</strong><small>จัดการผู้ใช้งานและข้อมูลระบบ</small></span></a></div><?php endif; ?>
                </div>
            </div>
        </section>

        <section class="card form-card">
            <div class="card-header d-flex align-items-center gap-2">
                <span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-collection"></i></span>
                <div><h2 class="section-title mb-0">คู่มือแยกตามหน้า</h2><p class="text-muted small mb-0">เลือกบทเพื่อดูรายละเอียดการใช้งาน</p></div>
            </div>
            <div class="card-body p-3 p-lg-4">
                <div class="accordion help-accordion" id="helpGuideAccordion">
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="guideDashboardHeading"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#guideDashboard"><span class="guide-number">1</span><span><strong>Dashboard</strong><small>ภาพรวมข้อมูลและสถิติงาน</small></span></button></h2>
                        <div id="guideDashboard" class="accordion-collapse collapse show" data-bs-parent="#helpGuideAccordion"><div class="accordion-body"><p>หน้า Dashboard ใช้ติดตามภาพรวมงานทั้งหมดในระบบอย่างรวดเร็ว</p><ul><li>ดู KPI: งานทั้งหมด, รอดำเนินการ, กำลังดำเนินการ และเสร็จสิ้น</li><li>ดูแนวโน้มจำนวนงานย้อนหลังแบบวัน, สัปดาห์, เดือน หรือปี</li><li>ดู “งานที่พบบ่อย” และสลับ IT / AV แยกกันได้ตามสิทธิ์</li><li>กดชื่องานที่พบบ่อยเพื่อเปิด Task List ที่กรองตามทีมและชื่องานนั้น</li><li>ดูรายการงานล่าสุด กดชื่องานเพื่อเปิดรายละเอียด และไปแก้ไขงานได้ตามสิทธิ์</li></ul></div></div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="guideTaskInputHeading"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#guideTaskInput"><span class="guide-number">2</span><span><strong>บันทึกงาน</strong><small>สร้างและบันทึกข้อมูลการปฏิบัติงาน</small></span></button></h2>
                        <div id="guideTaskInput" class="accordion-collapse collapse" data-bs-parent="#helpGuideAccordion"><div class="accordion-body"><p>ใช้สำหรับเพิ่มงาน IT / AV ใหม่เข้าสู่ระบบ</p><ul><li>กรอกชื่องาน, ทีม, สถานที่ และชื่อผู้รับผิดชอบ</li><li>งาน IT ต้องระบุปัญหาที่พบก่อนบันทึก ส่วนวิธีแก้ไขเว้นว่างได้จนกว่าจะดำเนินการเสร็จ</li><li>สำหรับ USER ระบบจะแสดงสถานะให้อ่านอย่างเดียวและควบคุม Workflow ของงาน IT/AV อัตโนมัติ</li><li>งาน AV จะเสร็จสิ้นเมื่อกรอกการดำเนินงานหรือเวลาสิ้นสุดจากหน้าแก้ไข</li><li>ADMIN และ SUPER สามารถเลือกหรือปรับสถานะได้เมื่อจำเป็น</li><li>รายละเอียดงาน การดำเนินงาน และหมายเหตุ เพิ่มภายหลังจากปุ่มแก้ไขในหน้า Task List</li></ul></div></div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="guideReportHeading"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#guideReport"><span class="guide-number">3</span><span><strong>Report</strong><small>Task List สำหรับค้นหาและติดตามงาน</small></span></button></h2>
                        <div id="guideReport" class="accordion-collapse collapse" data-bs-parent="#helpGuideAccordion"><div class="accordion-body"><p>หน้า Task List รวบรวมรายการงานทั้งหมดที่ยังไม่ถูกลบ</p><ul><li>ค้นหาจากชื่องาน และใช้ตัวกรอง วัน, ทีม, สถานะ และประเภทปัญหา</li><li>กำหนดจำนวนรายการต่อหน้า และเปลี่ยนหน้ารายการได้</li><li>กด <strong>ดูรายละเอียด</strong> เพื่อเปิดข้อมูลและประวัติการเปลี่ยนแปลงแบบ Popup</li><li>ประวัติงานแสดงผู้ดำเนินการ เหตุการณ์ และเวลาที่เกิดขึ้น</li><li>USER สามารถแก้ไขและลบทุกงานภายในทีมของตนเอง ไม่จำกัดว่าใครเป็นผู้สร้าง</li><li>SUPER และ ADMIN สามารถจัดการงานข้ามทีมได้</li></ul></div></div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="guideConfigHeading"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#guideConfig"><span class="guide-number">4</span><span><strong>Account Settings และ System Config</strong><small>แยกข้อมูลส่วนตัวออกจากการจัดการระบบของ ADMIN</small></span></button></h2>
                        <div id="guideConfig" class="accordion-collapse collapse" data-bs-parent="#helpGuideAccordion"><div class="accordion-body"><p>ทุก Role ใช้ Account Settings เพื่อแก้ไขข้อมูลส่วนตัวและเปลี่ยนรหัสผ่าน ส่วน System Config แสดงเฉพาะ ADMIN</p><ul><li>ระบบไม่มีหน้าสมัครสมาชิกสาธารณะ บัญชีใหม่ต้องสร้างโดย ADMIN จาก System Config</li><li>USER, SUPER และ ADMIN แก้ไขชื่อผู้ใช้งาน ชื่อ-นามสกุล รูปโปรไฟล์ และรหัสผ่านของตนเองได้</li><li>ADMIN สร้างและแก้ไขบัญชี กำหนดทีม/บทบาท รีเซ็ตรหัสผ่าน และเปิดหรือปิดบัญชีได้ใน System Config</li><li>บัญชีที่มีประวัติงานไม่สามารถลบได้ เพื่อรักษาความถูกต้องของข้อมูลรายการงาน</li><li>จำนวนผู้ใช้งานล่าสุดนับจากบัญชีที่มีการใช้งานภายใน 5 นาที</li></ul></div></div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="guideHelpHeading"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#guideHelp"><span class="guide-number">5</span><span><strong>คู่มือ</strong><small>แหล่งอ้างอิงการใช้งานระบบ</small></span></button></h2>
                        <div id="guideHelp" class="accordion-collapse collapse" data-bs-parent="#helpGuideAccordion"><div class="accordion-body"><p>หน้านี้เป็นแหล่งอ้างอิงสำหรับผู้ใช้งานทุกคน โดยสรุปวัตถุประสงค์และฟังก์ชันหลักของแต่ละหน้าในระบบ</p><p class="mb-0">หากมีการเพิ่มฟีเจอร์ใหม่ คู่มือควรได้รับการปรับปรุงควบคู่กัน เพื่อให้รูปแบบการใช้งานเป็นมาตรฐานเดียวกัน</p></div></div>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>
<style>
    .help-intro { border-color: #cddfee; background: linear-gradient(135deg, #f3f8ff, #fbfdff); }
    .help-kicker { display: inline-block; padding: .35rem .7rem; color: #1769c2; border-radius: 999px; background: #e4f0fd; font-size: .82rem; font-weight: 700; }
    .help-hero-icon { width: 92px; height: 92px; color: #1769c2; border: 1px solid #c8ddf2; border-radius: 1.2rem; background: #e8f2fd; font-size: 2.6rem; box-shadow: 0 10px 20px rgba(23, 105, 194, .12); }
    .help-route { display: flex; align-items: center; gap: .85rem; height: 100%; padding: 1rem; color: #334e68; border: 1px solid #dbe6f0; border-radius: .8rem; background: #fff; text-decoration: none; transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease; }
    .help-route:hover { color: #1d5e9f; border-color: #abcbe9; box-shadow: 0 8px 18px rgba(23, 105, 194, .1); transform: translateY(-2px); }
    .help-route strong, .help-route small { display: block; }.help-route small { margin-top: .15rem; color: #718096; font-size: .82rem; }.route-icon { font-size: 1.55rem; }
    .help-accordion .accordion-item { overflow: hidden; margin-bottom: .75rem; border: 1px solid #dbe6f0; border-radius: .8rem; }.help-accordion .accordion-button { gap: .75rem; color: #263f57; background: #fff; box-shadow: none; }.help-accordion .accordion-button:not(.collapsed) { color: #1769c2; background: #f1f7fe; }.guide-number { display: inline-flex; flex: 0 0 30px; align-items: center; justify-content: center; width: 30px; height: 30px; color: #1769c2; border-radius: 50%; background: #e4f0fd; font-size: .85rem; font-weight: 700; }.accordion-button strong, .accordion-button small { display: block; }.accordion-button small { margin-top: .12rem; color: #718096; font-size: .8rem; font-weight: 400; }.help-accordion .accordion-body { color: #52677f; line-height: 1.7; background: #fbfdff; }.help-accordion .accordion-body p:last-child, .help-accordion .accordion-body ul:last-child { margin-bottom: 0; }.help-accordion code { color: #1769c2; }
</style>
<style>
    .guide-detail { margin-top: 1.1rem; padding: 1rem 1.1rem; border: 1px solid #d7e7f6; border-left: 4px solid #3b82c4; border-radius: .65rem; background: #f5faff; }
    .guide-detail h3 { margin: 0 0 .65rem; color: #1b4f7f; font-size: 1rem; font-weight: 700; }
    .guide-detail h4 { margin: .8rem 0 .35rem; color: #365a7c; font-size: .92rem; font-weight: 700; }
    .guide-detail ol, .guide-detail ul { margin-bottom: .5rem; padding-left: 1.2rem; }
    .guide-detail li { margin-bottom: .28rem; }
    .guide-note { padding: .65rem .8rem; color: #536b82; border-radius: .5rem; background: #eaf4ff; font-size: .9rem; }
</style>
<script>
    document.querySelectorAll('.sidebar .nav-link').forEach((link) => {
        if (link.textContent.trim() === 'คู่มือ') link.classList.add('active');
    });

    const detailedGuide = {
        guideDashboard: `
            <div class="guide-detail"><h3>วิธีใช้ Dashboard อย่างละเอียด</h3><h4>1. อ่าน KPI Card</h4><ol><li><strong>งานทั้งหมด</strong> คือจำนวนงานที่ยังไม่ถูกลบในระบบ</li><li><strong>รอดำเนินการ</strong>, <strong>กำลังดำเนินการ</strong> และ <strong>เสร็จสิ้น</strong> แสดงจำนวนงานแยกตามสถานะปัจจุบัน</li><li>ตัวเลขจะอัปเดตเมื่อมีการบันทึกหรือแก้ไขงาน แล้วเปิด Dashboard ใหม่หรือรีเฟรชหน้า</li></ol><h4>2. ดูแนวโน้มและงานที่พบบ่อย</h4><ol><li>กราฟแท่งแสดงจำนวนงานตามวันที่ เลือกดูแบบวัน, สัปดาห์, เดือน หรือปีได้</li><li>กล่อง <strong>งานที่พบบ่อย</strong> จัดอันดับจากชื่องานที่ถูกบันทึกซ้ำจริง สูงสุด 5 อันดับต่อทีม</li><li>ADMIN และ SUPER สลับดู IT / AV ได้ ส่วน USER เห็นเฉพาะทีมของตนเอง</li><li>กดรายการใดเพื่อเปิด Task List ที่ใส่ตัวกรองทีมและชื่องานนั้นไว้แล้ว</li></ol><h4>3. ตรวจสอบงานล่าสุด</h4><ol><li>ตารางงานล่าสุดแสดงลำดับ, วันที่สร้าง, ชื่องาน, ทีม, สถานะ และผู้รับผิดชอบ</li><li>กดที่ชื่องานเพื่อเปิด Popup รายละเอียดงาน</li><li>จาก Popup สามารถไปหน้าแก้ไขงานได้ หากบัญชีนั้นมีสิทธิ์แก้ไข</li></ol><div class="guide-note">ใช้ Dashboard ดูภาพรวมและงานซ้ำก่อน แล้วเปิด Task List เมื่อต้องการดูรายการเชิงลึก</div></div>`,
        guideTaskInput: `
            <div class="guide-detail"><h3>วิธีบันทึกงานอย่างละเอียด</h3><h4>1. ข้อมูลงาน</h4><ol><li>กรอก <strong>ชื่องาน</strong> ให้สื่อความหมาย เช่น “ติดตั้งอุปกรณ์ห้องประชุม”</li><li><strong>ทีม</strong> จะอ้างอิงจากบัญชีผู้ใช้งาน โดย SUPER และ ADMIN เลือกทีมได้ ส่วน USER ใช้ทีมของตนเอง</li><li>เลือกสถานที่และกรอกชื่อผู้รับผิดชอบ หากไม่ระบุผู้รับผิดชอบ ระบบจะใช้ชื่อจากบัญชีผู้บันทึก</li><li>USER เห็นสถานะเป็นข้อมูลอ่านอย่างเดียว ส่วน ADMIN และ SUPER สามารถเลือกสถานะได้</li></ol><h4>2. Workflow งาน IT/AV สำหรับ USER</h4><ol><li>งาน IT ต้องกรอก <strong>ปัญหาที่พบ</strong> ก่อนบันทึกทุกครั้ง</li><li>งาน IT จะเป็น <strong>กำลังดำเนินการ</strong> จนกว่าจะกรอก <strong>วิธีแก้ไขปัญหา</strong> จากนั้นระบบเปลี่ยนเป็น <strong>เสร็จสิ้น</strong></li><li>งาน AV จะเป็น <strong>กำลังดำเนินการ</strong> หลังบันทึก และเปลี่ยนเป็น <strong>เสร็จสิ้น</strong> เมื่อกรอก <strong>การดำเนินงาน</strong> หรือเวลาสิ้นสุด</li><li>เมื่อระบบกำหนดงานเป็นเสร็จสิ้นและยังไม่มีเวลาสิ้นสุด ระบบจะเติมเวลาปัจจุบันให้อัตโนมัติ</li><li>งานที่ถูกยกเลิกโดยผู้ดูแลจะยังคงสถานะยกเลิก</li></ol><h4>3. ข้อมูลเพิ่มเติม</h4><ol><li>รายละเอียดงาน การดำเนินงาน ประเภทปัญหา และหมายเหตุ เพิ่มหรือแก้ไขจากหน้า Task List</li><li>Popup แก้ไขสามารถเลื่อนดูข้อมูลทั้งหมดได้ทั้งคอมพิวเตอร์และมือถือ</li><li>แนบรูปภาพประกอบได้สูงสุดตามจำนวนและขนาดที่หน้าฟอร์มระบุ</li></ol><div class="guide-note">ADMIN และ SUPER สามารถปรับสถานะด้วย Select ได้เมื่อจำเป็น ส่วน USER ไม่สามารถแก้สถานะโดยตรง</div></div>`,
        guideReport: `
            <div class="guide-detail"><h3>วิธีใช้หน้า Task List อย่างละเอียด</h3><h4>1. ค้นหาและกรองข้อมูล</h4><ol><li>พิมพ์คำค้นจากชื่องานในช่องค้นหาด้านบนของตาราง</li><li>กดไอคอนตัวกรองเพื่อเลือกช่วงวันที่, ทีม, สถานะ หรือประเภทปัญหา</li><li>กดล้างตัวกรองเมื่อต้องการกลับไปดูรายการทั้งหมด</li></ol><h4>2. อ่านตารางรายการงาน</h4><ol><li>ตารางแสดงลำดับ, วันที่สร้าง, ชื่องาน, ทีม, สถานะ และผู้รับผิดชอบ</li><li>ปรับจำนวนรายการต่อหน้า และใช้ Pagination เพื่อเปลี่ยนหน้าได้</li><li>ข้อมูลผู้รับผิดชอบใช้ชื่อที่ระบุในงาน หรือแสดงทีมสำหรับงานที่ไม่ได้ระบุชื่อ</li></ol><h4>3. ดูรายละเอียดและประวัติงาน</h4><ol><li>กด <strong>ดูรายละเอียด</strong> เพื่อเปิด Popup ที่แสดงข้อมูลทั้งหมดของงาน</li><li>ส่วน <strong>ประวัติการเปลี่ยนแปลง</strong> แสดงการสร้าง แก้ไข เปลี่ยนสถานะ และลบงาน พร้อมชื่อผู้ดำเนินการและเวลา</li><li>งานเดิมที่สร้างก่อนเพิ่มระบบประวัติจะเริ่มมีรายการเมื่อมีการแก้ไขครั้งถัดไป</li><li>USER แก้ไขและลบทุกงานภายในทีมของตนเองได้ แม้งานนั้นสร้างโดยสมาชิกคนอื่นในทีม</li><li>USER ไม่สามารถเปิดหรือส่งคำขอแก้ไขและลบงานข้ามทีมได้</li><li>SUPER และ ADMIN จัดการงานได้ทุกทีม</li><li>การลบเป็นการลบแบบซ่อนข้อมูลจากรายการและสถิติ เพื่อคงประวัติข้อมูลในระบบ</li></ol><div class="guide-note">ประวัติงานอ่านได้เฉพาะผู้ที่มีสิทธิ์เห็นงานนั้น และสิทธิ์ของ USER อ้างอิงจากทีม</div></div>`,
        guideConfig: `
            <div class="guide-detail"><h3>วิธีใช้ Account Settings และ System Config</h3><h4>1. จัดการบัญชีของตนเอง</h4><ol><li>USER, SUPER และ ADMIN เข้า Account Settings ได้ทุก Role</li><li>แก้ไขชื่อผู้ใช้งาน ชื่อ-นามสกุล หรือรูปโปรไฟล์ได้ โดยทีมและสิทธิ์เป็นข้อมูลอ่านอย่างเดียว</li><li>การเปลี่ยนรหัสผ่านใช้รหัสผ่านเดิม รหัสผ่านใหม่ตามเงื่อนไข และการยืนยันรหัสผ่าน</li><li>หลังเปลี่ยนรหัสผ่าน Remember Me เดิมจะถูกยกเลิกเพื่อความปลอดภัย</li></ol><h4>2. การเพิ่มผู้ใช้งาน</h4><ol><li>ระบบไม่มีหน้า Create Account หรือระบบรออนุมัติ</li><li>ADMIN สร้างบัญชีจาก System Config และกำหนดชื่อผู้ใช้ ชื่อ-นามสกุล รหัสผ่านเริ่มต้น ทีม บทบาท และสถานะ</li><li>บัญชีที่ปิดใช้งานจะไม่สามารถเข้าสู่ระบบหรือใช้ Session เดิมต่อได้</li></ol><h4>3. สิทธิ์ของ ADMIN</h4><ol><li>เข้า System Config เพื่อแก้ไขชื่อ ทีม บทบาท และสถานะบัญชี</li><li>รีเซ็ตรหัสผ่านและเปิดหรือปิดบัญชีผู้ใช้งาน</li><li>ลบผู้ใช้งานได้เฉพาะบัญชีที่ยังไม่มีประวัติงาน และไม่สามารถลบบัญชีของตนเองได้</li><li>ตรวจสอบเวอร์ชัน จำนวนผู้ใช้ล่าสุด และจำนวนงานในส่วนข้อมูลระบบ</li></ol><div class="guide-note">SUPER จัดการงานข้ามทีมได้ แต่ System Config และ User Management สงวนไว้สำหรับ ADMIN เท่านั้น</div></div>`,
        guideHelp: `
            <div class="guide-detail"><h3>วิธีใช้หน้าคู่มือ</h3><ol><li>เลือกบทจากหัวข้อ Accordion เพื่อเปิดดูเฉพาะหน้าที่ต้องการ</li><li>ใช้เส้นทางการใช้งานด้านบนเพื่อไปยัง Dashboard, บันทึกงาน, รายงาน หรือ Config ได้ทันที</li><li>เนื้อหาในคู่มือนี้อธิบายตามพฤติกรรมจริงของระบบ เพื่อใช้เป็นแนวทางทำงานร่วมกันของทีม</li></ol><div class="guide-note">หากมีการปรับฟีเจอร์หรือขั้นตอนทำงานใหม่ ควรปรับเนื้อหาในหน้านี้ให้ตรงกันเสมอ</div></div>`
    };
    Object.entries(detailedGuide).forEach(([sectionId, content]) => {
        const body = document.querySelector(`#${sectionId} .accordion-body`);
        if (body) body.insertAdjacentHTML('beforeend', content);
    });
</script>
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>
