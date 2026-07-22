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
                    <div class="col-md-6 col-xl-3"><a class="help-route" href="../report/"><span class="route-icon text-warning"><i class="bi bi-bar-chart-line"></i></span><span><strong>รายงาน</strong><small>ค้นหา ดูรายละเอียด และแก้ไขงาน</small></span></a></div>
                    <div class="col-md-6 col-xl-3"><a class="help-route" href="../config/"><span class="route-icon text-info"><i class="bi bi-gear"></i></span><span><strong>Config</strong><small>ดูข้อมูลระบบและจัดการผู้ใช้</small></span></a></div>
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
                        <div id="guideDashboard" class="accordion-collapse collapse show" data-bs-parent="#helpGuideAccordion"><div class="accordion-body"><p>หน้า Dashboard ใช้ติดตามภาพรวมงานทั้งหมดในระบบอย่างรวดเร็ว</p><ul><li>ดู KPI: งานทั้งหมด, รอดำเนินการ, กำลังดำเนินการ และเสร็จสิ้น</li><li>ดูกราฟสัดส่วนสถานะงาน และสถิติจำนวนงานย้อนหลัง</li><li>เปลี่ยนมุมมองสถิติย้อนหลังเป็น วัน, สัปดาห์, เดือน หรือปี</li><li>ดูรายการงานล่าสุด กดชื่องานเพื่อเปิดรายละเอียด และไปแก้ไขงานได้ตามสิทธิ์</li></ul></div></div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="guideTaskInputHeading"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#guideTaskInput"><span class="guide-number">2</span><span><strong>บันทึกงาน</strong><small>สร้างและบันทึกข้อมูลการปฏิบัติงาน</small></span></button></h2>
                        <div id="guideTaskInput" class="accordion-collapse collapse" data-bs-parent="#helpGuideAccordion"><div class="accordion-body"><p>ใช้สำหรับเพิ่มงาน IT / AV ใหม่เข้าสู่ระบบ</p><ul><li>กรอกชื่องาน, ทีม, สถานที่ และชื่อผู้รับผิดชอบ</li><li>หากไม่ระบุผู้รับผิดชอบ ระบบจะแสดงทีมเป็นผู้รับผิดชอบ</li><li>บันทึกรายละเอียดงาน, การดำเนินงาน, ปัญหาที่พบ, ประเภทปัญหา, วิธีแก้ไข และหมายเหตุ</li><li>กำหนดวันเริ่ม–สิ้นสุด และเวลาเริ่ม–สิ้นสุดแยกกัน</li><li>ช่องข้อมูลที่ไม่บังคับซึ่งเว้นว่างจะแสดงเป็น <code>-</code></li></ul></div></div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="guideReportHeading"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#guideReport"><span class="guide-number">3</span><span><strong>รายงาน</strong><small>ค้นหา ติดตาม และตรวจสอบงาน</small></span></button></h2>
                        <div id="guideReport" class="accordion-collapse collapse" data-bs-parent="#helpGuideAccordion"><div class="accordion-body"><p>หน้ารายงานรวบรวมรายการงานทั้งหมดที่ยังไม่ถูกลบ</p><ul><li>ค้นหาจากชื่องาน และใช้ตัวกรอง วัน, ทีม, สถานะ และประเภทปัญหา</li><li>กำหนดจำนวนรายการต่อหน้า และเปลี่ยนหน้ารายการได้</li><li>กด <strong>ดูรายละเอียด</strong> เพื่อเปิดข้อมูลแบบ Popup</li><li>ผู้ที่มีสิทธิ์สามารถแก้ไขหรือลบงานได้ โดยระบบตรวจสอบสิทธิ์ก่อนทุกครั้ง</li></ul></div></div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header" id="guideConfigHeading"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#guideConfig"><span class="guide-number">4</span><span><strong>Config</strong><small>ผู้ใช้งานและข้อมูลระบบ</small></span></button></h2>
                        <div id="guideConfig" class="accordion-collapse collapse" data-bs-parent="#helpGuideAccordion"><div class="accordion-body"><p>หน้า Config แสดงข้อมูลระบบและรายการผู้ใช้งาน</p><ul><li>ทุกสิทธิ์สามารถดูข้อมูลผู้ใช้และข้อมูลระบบได้</li><li>เฉพาะ ADMIN สามารถเพิ่ม แก้ไข รีเซ็ตรหัสผ่าน เปิด/ปิด หรือ ลบผู้ใช้งาน</li><li>บัญชีที่มีประวัติงานไม่สามารถลบได้ เพื่อรักษาความถูกต้องของข้อมูลรายงาน</li><li>ดูสถิติผู้ใช้ จำนวนงาน สถานะงาน และวันอัปเดตล่าสุดของระบบ</li></ul></div></div>
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
            <div class="guide-detail"><h3>วิธีใช้ Dashboard อย่างละเอียด</h3><h4>1. อ่าน KPI Card</h4><ol><li><strong>งานทั้งหมด</strong> คือจำนวนงานที่ยังไม่ถูกลบในระบบ</li><li><strong>รอดำเนินการ</strong>, <strong>กำลังดำเนินการ</strong> และ <strong>เสร็จสิ้น</strong> แสดงจำนวนงานแยกตามสถานะปัจจุบัน</li><li>ตัวเลขจะอัปเดตเมื่อมีการบันทึกหรือแก้ไขงาน แล้วเปิด Dashboard ใหม่หรือรีเฟรชหน้า</li></ol><h4>2. ดูกราฟสรุป</h4><ol><li>กราฟวงกลมแสดงสัดส่วนของงานตามสถานะ เพื่อดูภาพรวมได้ทันที</li><li>กราฟแท่งแสดงจำนวนงานตามวันที่สร้าง เลือกดูแบบวัน, สัปดาห์, เดือน หรือปีได้</li><li>สถิติย้อนหลังเริ่มจากงานที่เก่าที่สุดในระบบ และแสดงช่วงวันที่ของข้อมูลที่กำลังเลือก</li></ol><h4>3. ตรวจสอบงานล่าสุด</h4><ol><li>ตารางงานล่าสุดแสดงลำดับ, วันที่สร้าง, ชื่องาน, ทีม, สถานะ และผู้รับผิดชอบ</li><li>กดที่ชื่องานเพื่อเปิด Popup รายละเอียดงาน</li><li>จาก Popup สามารถไปหน้าแก้ไขงานได้ หากบัญชีนั้นมีสิทธิ์แก้ไข</li></ol><div class="guide-note">เคล็ดลับ: ใช้ Dashboard เพื่อตรวจสอบภาพรวมก่อน แล้วกด “ดูทั้งหมด” เพื่อไปค้นหางานเชิงลึกในหน้า Report</div></div>`,
        guideTaskInput: `
            <div class="guide-detail"><h3>วิธีบันทึกงานอย่างละเอียด</h3><h4>1. ข้อมูลงาน</h4><ol><li>กรอก <strong>ชื่องาน</strong> ให้สื่อความหมาย เช่น “ติดตั้งอุปกรณ์ห้องประชุม”</li><li><strong>ทีม</strong> จะอ้างอิงจากบัญชีผู้ใช้งาน สำหรับ SUPER และ ADMIN สามารถเลือกทีมได้ตามสิทธิ์</li><li>เลือกสถานที่จากรายการ หรือเลือก “อื่นๆ” แล้วระบุสถานที่เพิ่มเติม</li><li>กรอกชื่อผู้รับผิดชอบ หากเว้นว่าง ระบบจะแสดงทีมเป็นผู้รับผิดชอบในรายงาน</li><li>เลือกสถานะงานเป็น รอดำเนินการ, กำลังดำเนินการ หรือ เสร็จสิ้น</li></ol><h4>2. รายละเอียดงาน</h4><ol><li>บันทึกรายละเอียดงานและการดำเนินงาน เพื่ออธิบายบริบทของงาน</li><li>กรอกปัญหาที่พบ เลือกประเภทปัญหา และบันทึกวิธีแก้ไขปัญหาเมื่อมีข้อมูล</li><li>ใช้หมายเหตุสำหรับข้อมูลเสริม เช่น การติดตามผลหรือข้อจำกัดของงาน</li><li>ช่องข้อมูลที่ไม่บังคับ หากไม่กรอก ระบบจะบันทึกเป็น <code>-</code></li></ol><h4>3. ระยะเวลาการดำเนินงาน</h4><ol><li>เลือกวันเริ่มดำเนินการและวันที่สิ้นสุดแยกจากกัน</li><li>ระบุเวลาเริ่มงานและเวลาสิ้นสุดงานแบบเวลาอย่างเดียว</li><li>สามารถเลือกจาก Flatpickr หรือพิมพ์ค่าเองตามรูปแบบที่แสดง</li></ol><div class="guide-note">ก่อนบันทึก ตรวจสอบชื่องาน ทีม สถานะ วัน และเวลาให้ครบ เพราะเป็นข้อมูลสำคัญสำหรับรายงานและสถิติ</div></div>`,
        guideReport: `
            <div class="guide-detail"><h3>วิธีใช้หน้า Report อย่างละเอียด</h3><h4>1. ค้นหาและกรองข้อมูล</h4><ol><li>พิมพ์คำค้นจากชื่องานในช่องค้นหาด้านบนของตาราง</li><li>กดไอคอนตัวกรองเพื่อเลือกช่วงวันที่, ทีม, สถานะ หรือประเภทปัญหา</li><li>กดล้างตัวกรองเมื่อต้องการกลับไปดูรายการทั้งหมด</li></ol><h4>2. อ่านตารางรายการรายงาน</h4><ol><li>ตารางแสดงลำดับ, วันที่สร้าง, ชื่องาน, ทีม, สถานะ และผู้รับผิดชอบ</li><li>ปรับจำนวนรายการต่อหน้า และใช้ Pagination เพื่อเปลี่ยนหน้าได้</li><li>ข้อมูลผู้รับผิดชอบใช้ชื่อที่ระบุในงาน หรือแสดงทีมสำหรับงานที่ไม่ได้ระบุชื่อ</li></ol><h4>3. ดูรายละเอียดและจัดการงาน</h4><ol><li>กด <strong>ดูรายละเอียด</strong> เพื่อเปิด Popup ที่แสดงข้อมูลทั้งหมดของงาน</li><li>ปุ่มแก้ไขจะแสดงตามสิทธิ์ของผู้ใช้งาน โดย USER แก้ไขได้เฉพาะงานของตนเอง</li><li>การลบเป็นการลบแบบซ่อนข้อมูลจากรายการและสถิติ เพื่อคงประวัติข้อมูลในระบบ</li></ol><div class="guide-note">ใช้หน้า Report เมื่อต้องการค้นหางานตามช่วงเวลา ทีม หรือสถานะอย่างละเอียด</div></div>`,
        guideConfig: `
            <div class="guide-detail"><h3>วิธีใช้หน้า Config อย่างละเอียด</h3><h4>1. การดูข้อมูลผู้ใช้งาน</h4><ol><li>ผู้ใช้งานทุกสิทธิ์สามารถเปิดดูรายชื่อผู้ใช้ ทีม บทบาท สถานะ และวันที่สร้างได้</li><li>สถานะ “กำลังใช้งาน” แสดงสำหรับบัญชีที่กำลังล็อกอินอยู่ในขณะนั้น</li><li>สถานะ “ปิดใช้งาน” หมายถึงบัญชีนั้นไม่สามารถเข้าสู่ระบบได้</li></ol><h4>2. สิทธิ์ของ ADMIN</h4><ol><li>เพิ่มผู้ใช้งาน พร้อมกำหนดชื่อผู้ใช้ รหัสผ่าน ทีม และบทบาท</li><li>แก้ไขข้อมูลผู้ใช้ รีเซ็ตรหัสผ่าน และเปิด/ปิดบัญชีผู้ใช้งาน</li><li>ลบผู้ใช้งานได้เฉพาะบัญชีที่ยังไม่มีประวัติงาน และไม่สามารถลบบัญชีของตนเองได้</li></ol><h4>3. ข้อมูลระบบ</h4><ol><li>ตรวจสอบเวอร์ชันระบบ จำนวนผู้ใช้ จำนวนงาน และจำนวนงานแยกตามสถานะ</li><li>ตรวจสอบสถานะการเชื่อมต่อฐานข้อมูลและวันที่อัปเดตงานล่าสุด</li></ol><div class="guide-note">USER และ SUPER ใช้หน้า Config เพื่อดูข้อมูลได้ ส่วนการเปลี่ยนแปลงข้อมูลสงวนไว้สำหรับ ADMIN</div></div>`,
        guideHelp: `
            <div class="guide-detail"><h3>วิธีใช้หน้าคู่มือ</h3><ol><li>เลือกบทจากหัวข้อ Accordion เพื่อเปิดดูเฉพาะหน้าที่ต้องการ</li><li>ใช้เส้นทางการใช้งานด้านบนเพื่อไปยัง Dashboard, บันทึกงาน, รายงาน หรือ Config ได้ทันที</li><li>เนื้อหาในคู่มือนี้อธิบายตามพฤติกรรมจริงของระบบ เพื่อใช้เป็นแนวทางทำงานร่วมกันของทีม</li></ol><div class="guide-note">หากมีการปรับฟีเจอร์หรือขั้นตอนทำงานใหม่ ควรปรับเนื้อหาในหน้านี้ให้ตรงกันเสมอ</div></div>`
    };
    Object.entries(detailedGuide).forEach(([sectionId, content]) => {
        const body = document.querySelector(`#${sectionId} .accordion-body`);
        if (body) body.insertAdjacentHTML('beforeend', content);
    });
</script>
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>
