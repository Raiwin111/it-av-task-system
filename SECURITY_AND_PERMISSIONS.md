# Security and Permissions
> **หมายเหตุ:** เอกสารนี้ปรับจากระบบใช้งานจริงเพื่อใช้เป็น Portfolio/Demo Template — ชื่อบริษัท สถานที่ และบัญชีผู้ใช้ทั้งหมดเป็นข้อมูลตัวอย่าง


เอกสารนี้อธิบายสถานะปัจจุบันของ Authentication, Account Management และสิทธิ์การใช้งานใน IT / AV Task Management System

อัปเดตล่าสุด: 14 สิงหาคม 2026

## 1. แนวทางบัญชีผู้ใช้งาน

ระบบเป็นเว็บภายในองค์กรและไม่มี Public Registration

- หน้า Login ไม่มีลิงก์ Create Account
- `auth/register.php` ปิดใช้งานและตอบกลับ HTTP 404
- ADMIN สร้างบัญชีใหม่จากหน้า Config
- ตอนสร้างบัญชี ADMIN กำหนด Username, ชื่อ-นามสกุล, รหัสผ่านเริ่มต้น, Team, Role และสถานะ
- ไม่มีขั้นตอนรออนุมัติหรือ Approval queue

คอลัมน์ `users.is_approved` ยังคงอยู่เพื่อรองรับฐานข้อมูลเดิม แต่ระบบสิทธิ์ปัจจุบันไม่ใช้ค่านี้ตัดสินการเข้าถึงงาน บัญชีที่ ADMIN สร้างหรือแก้ไขจะบันทึกค่าเป็น `1`

## 2. Account State

### `is_enabled`

| ค่า | ผลการทำงาน |
|---:|---|
| `1` | บัญชีเปิดใช้งานและ Login ได้หากไม่ถูก Lock |
| `0` | Login ไม่ได้ และ Session/Remember Me เดิมไม่สามารถใช้ต่อได้ |

### `lock_until`

- ระบบ Lock บัญชีชั่วคราวเมื่อ Login ผิดตามจำนวนที่กำหนด
- ระหว่าง Lock การ Login จะถูกปฏิเสธ
- `auth_check.php` ตรวจสถานะ Lock ซ้ำบนหน้าที่ต้อง Login

### `is_approved` (Legacy)

- คงไว้เพื่อความเข้ากันได้กับ Schema และข้อมูลเดิม
- ไม่ใช่เงื่อนไข Permission ของงาน
- ไม่มี Pending Account หรือ View-only approval mode

## 3. Role และ Team Scope

ระบบมี 3 Role

| ความสามารถ | USER | SUPER | ADMIN |
|---|:---:|:---:|:---:|
| Login เมื่อบัญชีเปิดใช้งาน | ✓ | ✓ | ✓ |
| ดู Dashboard | ✓ | ✓ | ✓ |
| ดู/แก้ไข/ลบงานในทีมตนเอง | ✓ | ✓ | ✓ |
| ดู/แก้ไข/ลบงานข้ามทีม | — | ✓ | ✓ |
| เปลี่ยนรหัสผ่านตนเองใน Config | ✓ | ✓ | ✓ |
| ปรับสถานะงานโดยตรง | — | ✓ | ✓ |
| สร้างและจัดการบัญชีผู้ใช้ | — | — | ✓ |
| ดูข้อมูลระบบใน Config | — | — | ✓ |

### USER

- มองเห็นและจัดการงานเฉพาะ Team ของบัญชี
- สมาชิกทีมเดียวกันแก้ไขและลบงานของทีมได้ ไม่จำกัดผู้สร้าง
- สถานะเป็นข้อมูลอ่านอย่างเดียวและเปลี่ยนตาม Workflow อัตโนมัติ
- เข้า Config เพื่อเปลี่ยนรหัสผ่านตนเองได้

### SUPER

- มองเห็นและจัดการงานทุก Team
- เลือก Team และปรับสถานะงานได้เมื่อจำเป็น
- เข้า Config เพื่อเปลี่ยนรหัสผ่านตนเองได้
- ไม่มีสิทธิ์สร้าง แก้ไข รีเซ็ตรหัสผ่าน เปิด/ปิด หรือลบบัญชีผู้อื่น

### ADMIN

- มีสิทธิ์จัดการงานทุก Team
- เลือกและปรับสถานะงานได้
- สร้างและจัดการบัญชีจาก Config
- ระบบป้องกันไม่ให้ ADMIN ปิดใช้งาน ลดสิทธิ์ หรือลบบัญชีของตนเองขณะใช้งาน

## 4. Task Authorization

สิทธิ์ถูกรวมไว้ใน `auth/authorization.php`

ฟังก์ชันหลัก:

- `current_role()`
- `current_department()`
- `can_manage_users()`
- `can_manage_all_tasks()`
- `can_access_task_department()`
- `can_view_task()`
- `can_edit_task()`
- `can_delete_task()`

หลักการตัดสินสิทธิ์:

```text
ADMIN หรือ SUPER
    เข้าถึงงานได้ทุกทีม

USER
    เข้าถึงได้เมื่องานอยู่ทีมเดียวกับบัญชี
```

หน้า Dashboard, Task Input และ Report ใช้หลัก Team scope เดียวกัน การเรียก URL หรือส่ง Request โดยตรงยังต้องผ่าน Permission ฝั่ง Server

## 5. Workflow สถานะงาน

### USER

- สถานะในหน้าเพิ่มและแก้ไขงานเป็นข้อมูลอ่านอย่างเดียว
- งานใหม่เริ่มจาก `รอดำเนินการ` และเมื่อบันทึกเข้าสู่รายการจะเป็น `กำลังดำเนินการ`
- งาน IT เปลี่ยนเป็น `เสร็จสิ้น` เมื่อมีวิธีแก้ไขปัญหา
- งาน AV เปลี่ยนเป็น `เสร็จสิ้น` เมื่อมีข้อมูลการดำเนินงานหรือเวลาสิ้นสุด

### SUPER และ ADMIN

- สามารถเลือกสถานะได้เมื่อจำเป็น
- Workflow อัตโนมัติยังคงช่วยกำหนดสถานะจากข้อมูลของงาน

## 6. Login Security

Login รองรับมาตรการต่อไปนี้:

- ตรวจรหัสผ่านด้วย `password_verify()`
- เก็บรหัสผ่านด้วย `password_hash(..., PASSWORD_DEFAULT)`
- Regenerate Session ID หลัง Login สำเร็จ
- CSRF token สำหรับ Login
- Account lockout
- IP rate limit
- Login log
- Remember Me แบบ token ที่เพิกถอนได้
- ตรวจ `is_enabled` และ `lock_until` ซ้ำบน Protected request

ระบบไม่เปิดเผยว่าชื่อบัญชีหรือรหัสผ่านส่วนใดผิดในข้อความ Login

## 7. Session และ Remember Me

- Session cookie ใช้ `HttpOnly` และ `SameSite=Lax`
- ตั้งค่า `Secure` เมื่อ Request ใช้ HTTPS
- Session มี Idle timeout 30 นาที
- Protected page โหลดข้อมูล Username, Team, Role, Profile image และ Account state จากฐานข้อมูลซ้ำ
- เมื่อปิดบัญชีหรือ Account ถูก Lock ระบบยกเลิก Session และ Remember Me
- เมื่อเปลี่ยนหรือรีเซ็ตรหัสผ่าน Remember Me token เดิมจะถูกลบ
- Logout รับเฉพาะ POST และตรวจ CSRF

## 8. Password Management

### ทุก Role

หน้า Config มีช่อง:

1. รหัสผ่านเดิม
2. รหัสผ่านใหม่
3. ยืนยันรหัสผ่านใหม่

ข้อกำหนด:

- ต้องตรวจรหัสผ่านเดิมสำเร็จ
- รหัสผ่านใหม่ต้องตรงกับช่องยืนยัน
- ความยาวขั้นต่ำ 8 ตัวอักษร

### ADMIN

- กำหนดรหัสผ่านเริ่มต้นตอนสร้างบัญชี
- รีเซ็ตรหัสผ่านบัญชีอื่นได้
- การรีเซ็ตจะเพิกถอน Remember Me token ของบัญชีนั้น

## 9. Config Permission

ทุก Role เปิด `/config/` ได้ แต่ UI และ POST action แยกตามสิทธิ์

- USER/SUPER เห็นเฉพาะส่วนเปลี่ยนรหัสผ่านตนเอง
- ADMIN เห็นส่วนเปลี่ยนรหัสผ่านตนเอง, User Management และข้อมูลระบบ
- POST action จัดการผู้ใช้ตรวจ ADMIN ฝั่ง Server ไม่พึ่งการซ่อนปุ่มเพียงอย่างเดียว
- ทุก action ตรวจ CSRF token

ADMIN สามารถ:

- สร้างบัญชี
- แก้ Username และชื่อ-นามสกุล
- กำหนด IT/AV Team
- กำหนด USER/SUPER/ADMIN Role
- รีเซ็ตรหัสผ่าน
- เปิดหรือปิดบัญชี
- ลบบัญชีที่ไม่มีประวัติงานและไม่ใช่บัญชีตนเอง

## 10. CSRF Protection

จุดสำคัญที่มี CSRF protection:

- Login
- Logout
- Task create/edit/delete/status actions
- Profile actions
- Config password change
- Config user create/edit/reset/status/delete

การตรวจ Permission และ CSRF ทำฝั่ง Server แม้ผู้ใช้ส่ง Request โดยไม่ผ่านหน้า UI

## 11. Upload Security

รูปภาพงานและรูปโปรไฟล์มีการตรวจ:

- ประเภท MIME ที่อนุญาต
- ขนาดไฟล์
- จำนวนไฟล์ตามหน้าฟอร์ม
- ชื่อไฟล์ที่ระบบสร้างใหม่
- ปิด Directory listing ของโฟลเดอร์ Upload

Upload และไฟล์สำรองถูกป้องกันไม่ให้แสดงรายการผ่าน HTTP

## 12. Database Security

- Query ที่รับข้อมูลจากผู้ใช้ใช้ Prepared statements
- Credential เครื่องจริงโหลดจาก `config/db.local.php`
- มี `config/db.local.example.php` เป็นตัวอย่างที่ไม่บรรจุรหัสลับจริง
- Database wrapper ถูกป้องกันจากการเปิดผ่าน HTTP
- Migration แยกเป็นไฟล์และไม่ควรแก้ข้อมูลเดิมโดยไม่มี Backup และการอนุมัติ

## 13. Audit และ Soft Delete

- การสร้าง แก้ไข เปลี่ยนสถานะ และลบงานเชื่อมกับ Task activity history
- การลบงานเป็น Soft Delete เพื่อรักษาประวัติและความสัมพันธ์ของข้อมูล
- บัญชีที่มีประวัติงานไม่สามารถลบจาก Config ได้

## 14. HTTP Surface

| Endpoint | สถานะปัจจุบัน |
|---|---|
| `/auth/login.php` | Public Login |
| `/auth/register.php` | ปิดใช้งานและตอบ HTTP 404 |
| `/auth/logout.php` | POST + CSRF เท่านั้น |
| `/dashboard/` | ต้อง Login |
| `/task_input/` | ต้อง Login และตรวจ Team/Role |
| `/report/` | ต้อง Login และตรวจ Team/Role |
| `/config/` | ต้อง Login; User Management เฉพาะ ADMIN |
| `/profile/` | ต้อง Login |
| `/help/` | ต้อง Login |
| `/create_admin.php` | ไม่มีไฟล์และตอบ HTTP 404 |

Maintenance tools, test utilities และ database backups ถูกป้องกันจาก HTTP

## 15. Verification

ชุด `tests/smoke.php` ตรวจอย่างน้อย:

- ADMIN หลักของระบบ
- USER Team scope
- SUPER Cross-team scope โดยไม่มี User Management
- Config ตาม Role
- Public Registration ถูกปิด
- Protected routes
- CSRF/Logout surface
- Upload, backup และ utility protection
- Dashboard, Task Input และ Report workflow

ผลตรวจล่าสุด: `55 checks; 0 failures`

## 16. ข้อกำหนดก่อนนำขึ้นใช้งานจริง

- ใช้ HTTPS ในสภาพแวดล้อมจริง
- จำกัดสิทธิ์ไฟล์ `config/db.local.php` และโฟลเดอร์ Upload/Backup
- สำรองฐานข้อมูลและทดสอบการกู้คืนตามรอบ
- ทดสอบ Login และ Workflow ด้วยบัญชีจริงของแต่ละ Role ก่อนเปิดใช้
- ตรวจ Apache/PHP error log และ Login log หลัง Deploy
- ทำ Git checkpoint เฉพาะหลังตรวจไฟล์และได้รับอนุมัติ

