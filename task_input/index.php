<?php require_once __DIR__ . "/../includes/app_header.php"; ?>
<div class="app-shell d-flex">
    <?php require_once __DIR__ . "/../includes/app_sidebar.php"; ?>
    <main class="main-content flex-grow-1 p-4 p-lg-5">
        <div class="mb-4"><h1 class="page-heading h3 fw-bold mb-1">บันทึกงาน</h1><p class="page-subtitle mb-0">บันทึกข้อมูลเหตุขัดข้องของงาน IT / AV และแนวทางการแก้ไข</p></div>

        <!-- UI only: these fields are not connected to a database or submission process. -->
        <form>
            <section class="card form-card mb-4">
                <div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-clipboard-plus"></i></span><h2 class="section-title mb-0">ข้อมูลการแจ้งงาน</h2></div>
                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-md-4"><label for="department" class="form-label">แผนก</label><input type="text" class="form-control bg-light" id="department" value="<?php echo htmlspecialchars($_SESSION["department"] ?? "", ENT_QUOTES, "UTF-8"); ?>" readonly><div class="form-text">กำหนดอัตโนมัติจากบัญชีผู้ใช้งาน</div></div>
                        <div class="col-md-4"><label for="createdAt" class="form-label">วันที่และเวลาที่สร้าง</label><input type="text" class="form-control bg-light" id="createdAt" readonly><div class="form-text">กำหนดอัตโนมัติจากเวลาปัจจุบัน</div></div>
                        <div class="col-md-6"><label for="taskTitle" class="form-label">หัวข้องาน <span class="required-mark">*</span></label><input type="text" class="form-control" id="taskTitle" placeholder="เช่น Projector no signal"></div>
                        <div class="col-md-6"><label for="problemCategory" class="form-label">ประเภทปัญหา <span class="required-mark">*</span></label><select class="form-select" id="problemCategory"><option selected disabled>เลือกประเภทปัญหา</option><option>Network</option><option>Computer</option><option>Printer</option><option>Projector</option><option>TV</option><option>Sound System</option><option>Meeting Room</option><option>Software</option><option value="Other">Other</option></select></div>
                        <div class="col-12 d-none" id="otherCategoryGroup"><label for="otherCategory" class="form-label">ระบุประเภทปัญหาอื่น <span class="required-mark">*</span></label><input type="text" class="form-control" id="otherCategory" placeholder="ระบุประเภทปัญหา"></div>
                    </div>
                </div>
            </section>

            <section class="card form-card mb-4">
                <div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-file-earmark-text"></i></span><h2 class="section-title mb-0">รายละเอียดปัญหา</h2></div>
                <div class="card-body p-4"><div class="row g-4"><div class="col-12"><label for="problemDetail" class="form-label">รายละเอียดปัญหา <span class="required-mark">*</span></label><textarea class="form-control" id="problemDetail" rows="5" placeholder="อธิบายปัญหาที่พบหรือความต้องการ"></textarea></div><div class="col-12"><label for="solution" class="form-label">วิธีแก้ไข / การดำเนินการ</label><textarea class="form-control" id="solution" rows="5" placeholder="ระบุวิธีแก้ไขหรือแนวทางการดำเนินงาน"></textarea></div></div></div>
            </section>

            <section class="card form-card mb-4">
                <div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-clock-history"></i></span><h2 class="section-title mb-0">เวลาและสถานะงาน</h2></div>
                <div class="card-body p-4"><div class="row g-4"><div class="col-md-4"><label for="taskStatus" class="form-label">สถานะงาน</label><select class="form-select" id="taskStatus"><option selected>รอดำเนินการ</option><option>กำลังดำเนินการ</option><option>เสร็จสิ้น</option></select></div><div class="col-md-4"><label for="incidentTime" class="form-label">เวลาที่เกิดเหตุ</label><div class="input-group"><span class="input-group-text bg-light border-end-0"><i class="bi bi-calendar3"></i></span><input type="text" class="form-control border-start-0" id="incidentTime" placeholder="วว/ดด/ปปปป เวลา ชช:นน น."></div><div class="form-text">ตัวอย่าง 18/07/2569 เวลา 08:35 น.</div></div><div class="col-md-4"><label for="finishTime" class="form-label">เวลาที่เสร็จ</label><div class="input-group"><span class="input-group-text bg-light border-end-0"><i class="bi bi-calendar-check"></i></span><input type="text" class="form-control border-start-0" id="finishTime" placeholder="วว/ดด/ปปปป เวลา ชช:นน น."></div><div class="form-text">ตัวอย่าง 18/07/2569 เวลา 10:15 น.</div></div></div></div>
            </section>

            <section class="card form-card mb-4">
                <div class="card-header d-flex align-items-center gap-2"><span class="section-icon d-inline-flex align-items-center justify-content-center"><i class="bi bi-journal-text"></i></span><h2 class="section-title mb-0">หมายเหตุ</h2></div>
                <div class="card-body p-4"><label for="remark" class="form-label">หมายเหตุเพิ่มเติม</label><textarea class="form-control" id="remark" rows="4" placeholder="ระบุข้อมูลเพิ่มเติม (ถ้ามี)"></textarea></div>
            </section>

            <div class="form-actions d-flex flex-column flex-sm-row justify-content-end gap-2 pt-4"><button type="button" class="btn btn-outline-secondary px-4"><i class="bi bi-arrow-counterclockwise me-1"></i>ล้างข้อมูล</button><button type="button" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i>บันทึกข้อมูล</button></div>
        </form>
    </main>
</div>
<script>
    // UI display only: format the current browser time for Thai users.
    const createdAt = document.getElementById('createdAt');
    const now = new Date();
    const thaiDate = new Intl.DateTimeFormat('th-TH-u-ca-buddhist', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(now);
    const thaiTime = new Intl.DateTimeFormat('th-TH', { hour: '2-digit', minute: '2-digit', hour12: false }).format(now);
    createdAt.value = `${thaiDate} เวลา ${thaiTime} น.`;

    const problemCategory = document.getElementById('problemCategory');
    const otherCategoryGroup = document.getElementById('otherCategoryGroup');
    problemCategory.addEventListener('change', () => otherCategoryGroup.classList.toggle('d-none', problemCategory.value !== 'Other'));
</script>
<?php require_once __DIR__ . "/../includes/app_footer.php"; ?>
