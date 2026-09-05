# IT / AV Task Management System — Portfolio / Demo Template

ระบบจัดการงาน (Task Management System) ต้นแบบสำหรับทีมงานปฏิบัติการ พัฒนาด้วย PHP แบบ Server-rendered + MySQL
ออกแบบมาให้เหมาะกับทีม **IT / AV** โดยตรง และปรับใช้กับทีมลักษณะเดียวกันได้ทันที เช่น **Maintenance, Facilities, Housekeeping Operations** — เปลี่ยนรายการทีม สถานที่ และหมวดปัญหาได้จากหน้า Config โดยไม่ต้องแก้โค้ด

> **หมายเหตุ:** โปรเจกต์นี้ปรับจากระบบที่ใช้งานจริงในองค์กรหนึ่ง มาเป็น Portfolio/Demo Template — ชื่อบริษัท สถานที่ บัญชีผู้ใช้ และข้อมูลงานทั้งหมดเป็นข้อมูลตัวอย่างที่สมมติขึ้นใหม่

---

## Screenshot

| หน้าจอ | คำอธิบาย |
|---|---|
| `docs/screenshots/login.png` | *(placeholder)* หน้า Login — การ์ดกระจกกึ่งโปร่งบนพื้นน้ำเงินเข้ม, ช่องจำการเข้าสู่ระบบ 30 วัน |
| `docs/screenshots/dashboard.png` | *(placeholder)* Dashboard — KPI Card 4 ใบ, กราฟแนวโน้มงานรายสัปดาห์, สรุปสถานะตามทีม |
| `docs/screenshots/report.png` | *(placeholder)* Report — ตารางงานพร้อม search, filter หลายเงื่อนไข, ปุ่มรายละเอียด/แก้ไข/ลบ/ซ่อน |
| `docs/screenshots/task-input.png` | *(placeholder)* Task Input — ฟอร์มแบ่ง section, เลือกสถานที่ + "สถานที่อื่น", แนบรูปได้ |
| `docs/screenshots/config.png` | *(placeholder)* System Config — จัดการผู้ใช้, สถานที่ปฏิบัติงาน, ข้อมูลระบบ |

*(โฟลเดอร์ `docs/screenshots/` ยังไม่มีภาพจริง — แคปหน้าจอระบบแล้ววางตามตำแหน่งไฟล์ในตารางนี้)*

---

## Tech Stack

- **PHP 8.2** (Server-rendered, ไม่พึ่ง framework — โครงสร้างเรียบง่ายอ่านง่าย)
- **MySQL / MariaDB** (mysqli prepared statements ทั้งหมด)
- **Bootstrap 5.3** + **Bootstrap Icons**
- **Flatpickr** (datetime picker ภาษาไทย ปี พ.ศ.)
- **Chart.js** (กราฟ Dashboard)
- **Composer** (dependencies ภายนอก)

## Feature เด่น

- **Dashboard + KPI Cards** — สรุปงานทั้งหมด/กำลังดำเนินการ/เสร็จสิ้น/รอดำเนินการ พร้อมกราฟแนวโน้มย้อนหลังหลายสัปดาห์ และกรองตามทีม/สถานะ/ช่วงวันที่
- **Role / Team-based Permission** — USER เห็นเฉพาะงานทีมตัวเอง, SUPER จัดการได้ทุกทีม, ADMIN จัดการผู้ใช้และความมองเห็นของงาน (`is_visible`) พร้อมล็อคสิทธิ์ทั้ง UI และฝั่ง server
- **Report ค้นหา/กรองครบ** — ค้นหาแบบ full-text, กรองทีม/สถานะ/หมวด/ช่วงวันที่, แบ่งหน้าฝั่ง MySQL, modal รายละเอียดแบบ rich พร้อม Lightbox ดูรูป (ซูม/เลื่อน/ปิด)
- **Task Detail + ประวัติการเปลี่ยนแปลง** — activity log บันทึกทุก event พร้อมค่าก่อน→หลังของ field ที่เปลี่ยน กดขยายดูได้ในหน้ารายละเอียด
- **แนบรูปภาพ** — อัปโหลดตอนสร้างงาน และเพิ่ม/ลบรูปได้ในหน้าแก้ไข (validation ฝั่ง server, จำกัด 5 MB/รูป)
- **Choice Memory** — จำปัญหาที่พบบ่อยของแต่ละทีม (team_problem_options) เติมอัตโนมัติในฟอร์ม
- **Config แบบ Dynamic** — จัดการรายการ **สถานที่** (`task_locations`) และ **ผู้ใช้งาน** จากหน้า System Config เฉพาะ ADMIN, หมวดปัญหาอ่านจากตาราง `task_categories`
- **ความปลอดภัย** — CSRF token ทุกฟอร์ม, bcrypt password, login lockout, session idle timeout, remember-me แบบ selector/validator, upload security (deny PHP execution), soft-delete + audit log

## การติดตั้ง (Setup)

1. ติดตั้ง prerequisites: PHP 8.1+, MySQL/MariaDB, Composer
2. `composer install`
3. สร้างฐานข้อมูล:
   ```sql
   CREATE DATABASE your_db_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
4. Import schema พื้นฐาน:
   ```bash
   mysql -u <user> -p your_db_name < config/schema_baseline.sql
   ```
5. Copy `config/db.local.example.php` → `config/db.local.php` แล้วแก้ค่า host/dbname/user/password
6. (เลือกได้) Import migration SQL ที่เหลือใน `config/` หากต้องการโครงสร้างแบบ incremental — `schema_baseline.sql` รวมโครงสร้างสุดท้ายไว้แล้ว
7. Import **seed data สมมติ** สำหรับ demo:
   ```bash
   mysql -u <user> -p your_db_name < config/seed_demo_data.sql
   ```
8. เปิด `http://localhost/it-av-task-system/` แล้วล็อกอินด้วยบัญชี demo

## Demo Login Credentials

| บัญชี | รหัสผ่าน | ทีม | สิทธิ์ |
|---|---|---|---|
| `admin_demo` | `Demo@1234` | IT | ADMIN — จัดการผู้ใช้/สถานที่/ซ่อน-เปิดงาน |
| `super_demo` | `Demo@1234` | IT | SUPER — จัดการงานทุกทีม |
| `user_it` | `Demo@1234` | IT | USER — เห็นงานทีม IT |
| `user_av` | `Demo@1234` | AV | USER — เห็นงานทีม AV |

*(รหัสผ่านใช้ได้เฉพาะติดตั้งใหม่จาก seed นี้ — ห้ามใช้บนระบบจริง)*

## เอกสารเพิ่มเติม

| เอกสาร | เนื้อหา |
|---|---|
| [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md) | โครงสร้างระบบ, โมดูล, ขั้นตอนข้อมูล, glossary |
| [UX_UI_GUIDE.md](UX_UI_GUIDE.md) | ภาษาการออกแบบ, layout, หน้าจอรายหน้า |
| [DATABASE_REFERENCE.md](DATABASE_REFERENCE.md) | Schema, data dictionary, ความสัมพันธ์ตาราง |
| [SECURITY_AND_PERMISSIONS.md](SECURITY_AND_PERMISSIONS.md) | สิทธิ์ตาม Role/Team, กลไกความปลอดภัย, checklist |
