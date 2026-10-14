# หน้าที่ของแต่ละหน้าในระบบ (Hospital Patient Registration & Queue Management)

ภาพรวม: แต่ละไฟล์ `.php` = 1 หน้าจอ/1 ฟังก์ชันทำงาน (Single-page responsibility)
สิทธิ์: **พนักงานทั่วไป** = เจ้าหน้าที่ลงทะเบียน, **Admin** = ผู้ดูแลระบบ

---

## 🔐 หน้าระบบ (Authentication)

### 1. `login.php` — หน้าเข้าสู่ระบบ
- ฟอร์มกรอก username/password ตรวจสอบกับตาราง `staff` (เฉพาะบัญชี `is_active = 1`)
- ยืนยันรหัสด้วย `password_verify()` (bcrypt) → เก็บ staff_id, ชื่อ, role ลง Session
- Redirect ไป `index.php` ถ้าเข้าสู่ระบบแล้ว / แสดง Flash message ทั้งสำเร็จและผิดพลาด
- แสดงบัญชีทดสอบไว้ที่ด้านล่างฟอร์ม (admin/admin123, staff1/staff123)
- **ไม่ต้อง login** | ตาราง: `staff`

### 2. `logout.php` — ออกจากระบบ
- ล้าง Session ทั้งหมด (`session_destroy`) → redirect ไป `login.php` พร้อม Flash "ออกจากระบบแล้ว"

---

## 🏥 หน้าหลักและเวชระเบียนผู้ป่วย

### 3. `index.php` — แดชบอร์ดศูนย์ควบคุมคิว (หน้าแรกหลัง Login)
- **สถิติวันนี้:** ผู้ป่วยมารับการตรวจ / รอรับบริการ / กำลังตรวจ / เสร็จสิ้น / ผู้ป่วยทั้งหมดในระบบ
- **กราฟ 3 แบบ:** แท่งคิวสะสม 7 วัน, คิวแยกตามชั่วโมง 07:00–18:00 (หาชั่วโมงเร่งด่วน), Donut สัดส่วนสถานะคิววันนี้
- **ตารางคิววันนี้** (กรองตามแผนกได้ `?dept_id=`) พร้อมปุ่ม操作ด่วน: เรียกคิว (มีเสียงไทย) / เข้าตรวจ / เสร็จสิ้น / พิมพ์บัตร
- **แผงภาระงานรายแผนก** (รอ/ตรวจ/เสร็จ ต่อแผนก) — คลิกเพื่อกรองคิว
- POST action: `call` → `called`, `serve` → `serving`, `complete` → `completed` (+อัปเดต `visits`)
- **ต้อง Login** | ตาราง: `queues`, `visits`, `patients`, `departments`

### 4. `patients.php` — ทะเบียนรายชื่อผู้ป่วย (List & Search)
- ค้นหาด้วย HN / เลข ปชช. / ชื่อ-นามสกุล / เบอร์โทร (LIKE) + กรองเพศ
- ตารางแสดง HN, เลข ปชช., ชื่อ, เพศ, วันเกิด+อายุ, เบอร์, วันลงทะเบียน
- ปุ่มลิงก์: ออกคิว (`visits.php?patient_id=`) / ดูข้อมูล (`patient_detail`) / แก้ไข (`patient_edit`)
- **ต้อง Login** | ตาราง: `patients`

### 5. `patient_add.php` — ลงทะเบียนผู้ป่วยใหม่ (One-Stop)
- สร้าง HN อัตโนมัติล่วงหน้า (`generate_hn()` เช่น HN-260001) แก้ไขได้
- Validate: HN ห้ามซ้ำ, เลข ปชช. 13 หลัก + ห้ามซ้ำ, ชื่อ-นามสกุล ต้องมี
- **จุดเด่น:** ติ๊ก "ออกบัตรคิวทันที" → สร้าง `visits` + `queues` ใน Transaction เดียว แล้วพาไปหน้าพิมพ์บัตรคิว
- ถ้าไม่ติ๊ก → บันทึกอย่างเดียวแล้วไป `patient_detail.php`
- **ต้อง Login** | ตาราง: `patients`, `visits`, `queues`

### 6. `patient_edit.php` — แก้ไขข้อมูลผู้ป่วย (`?id=`)
- ฟอร์มแก้ HN, เลข ปชช., ชื่อ, เพศ, วันเกิด, เบอร์, ที่อยู่
- Validate ซ้ำเช่นเดียวกับหน้าเพิ่ม (แต่ห้ามนับ record ตัวเอง)
- บันทึกแล้ว redirect กลับ `patient_detail.php`
- **ต้อง Login** | ตาราง: `patients`

### 7. `patient_detail.php` — เวชระเบียน/ประวัติผู้ป่วย (`?id=`)
- ซ้าย: ข้อมูลส่วนตัว (avatar, HN, ปชช., เพศ, อายุ, ที่อยู่, วันลงทะเบียน)
- ขวา: ตารางประวัติการมารับบริการทั้งหมด (วันที่, แผนก, เลขคิว, สถานะคิว, เจ้าหน้าที่) + ปุ่มพิมพ์บัตรคิวย้อนหลัง
- ปุ่มลิงก์: แก้ไข / ออกคิวตรวจใหม่
- **ต้อง Login** | ตาราง: `patients`, `visits`, `queues`, `departments`, `staff`

---

## 🎫 ระบบคิว (Queue)

### 8. `visits.php` — ออกบัตรคิวเข้ารับการตรวจ
- เลือกผู้ป่วย (dropdown 50 รายล่าสุด หรือลิงก์มาจากหน้าอื่น `?patient_id=` ซึ่งจะล็อกผู้ป่วยไว้)
- เลือกแผนก/คลินิก + ห้องตรวจ (dropdown ห้องกรองตามแผนกผ่าน JS, ไม่เลือก = จัดห้องแรกอัตโนมัติ) → กด "ออกบัตรคิวและพิมพ์"
- สร้าง `visits` (status=registered) + `queues` (เลขคิว เช่น MED-001, status=waiting) ใน Transaction
- สำเร็จ → redirect ไป `print_queue.php?queue_id=`
- **ต้อง Login** | ตาราง: `patients`, `departments`, `rooms`, `staff`, `visits`, `queues`

### 9. `print_queue.php` — หน้าพิมพ์บัตรคิวความร้อน (`?queue_id=`)
- แสดงบัตรคิวรูปแบบ Thermal Slip (ชื่อแผนก, เลขคิวตัวใหญ่, กล่องจุดหมายห้องตรวจ+แพทย์, ชื่อผู้ป่วย, HN, ลำดับ, วันเวลา, ผู้ออกคิว)
- ปุ่ม "สั่งพิมพ์ทันที" (`window.print()`) และรองรับ `?auto=1` พิมพ์อัตโนมัติ
- หน้า standalone (ไม่ใช้ sidebar) และมี CSS ซ่อนปุ่มตอนพิมพ์ (no-print)
- **ต้อง Login** | ตาราง: `queues`, `visits`, `patients`, `departments`, `rooms`, `staff`

### 10. `queues.php` — โต๊ะเรียกและจัดการคิวตรวจ (สำหรับหมอ/พยาบาล)
- ตัวกรอง: แผนก (`?dept_id=` หรือ `?dept=MED`), ห้องตรวจ (`?room_id=`), สถานะคิว → auto-submit (ถ้า login เป็นแพทย์จะกรองแผนก/ห้องตัวเองอัตโนมัติ)
- แสดง Summary: รอ / เรียกแล้ว / กำลังตรวจ / เสร็จ / ส่งรับยาแล้ว (นับจากผลที่กรอง)
- การ์ดคิวแต่ละใบมีปุ่มตามสถานะ:
  - `waiting` → **เรียกคิว** (ขานเสียงไทย `speakQueue()` ระบุห้องตรวจ) / เข้าตรวจ
  - `called` → เข้าตรวจ / เรียกซ้ำ / **ส่งไปรับยา** (ออกคิว RX)
  - `serving` → เสร็จสิ้นและส่งรับยา / ไม่รับยา
  - `completed` (ยังไม่ส่งยา) → ส่งไปรับยาเพิ่มเติม
  - ทุกใบ: พิมพ์บัตรซ้ำ, ยกเลิกคิว (มี confirm) — เมื่อ complete/cancel จะอัปเดต `visits` ด้วย
- POST action: `call` / `serve` / `complete` / `complete_and_pharmacy` / `send_pharmacy` / `cancel` → redirect กลับหน้าเดิมพร้อม filter เดิม
- **ต้อง Login** | ตาราง: `queues`, `visits`, `patients`, `departments`, `rooms`, `pharmacy_queues`

### 11. `queue_display.php` — จอทีวีแสดงคิวสำหรับผู้ป่วย (TV Public Display)
- ธีมสว่าง (PIDS) ไม่ใช้ sidebar, 2 แผง: ซ้าย "กำลังตรวจ/รับบริการ" + ประวัติคิวห้องอื่น, ขวา "ลำดับคิวรอตรวจ" (ชื่อแผนกใน select ย่อให้สั้นพอดีจอ)
- โหมดเฉพาะ: `?dept_id=` กรองแผนกตรวจ, `?scope=pharmacy` แสดงเฉพาะคิวรับยา
- ฟีเจอร์: เลือกมุมมองที่จะแสดง, สวิตช์เปิด/ปิดเสียงขาน, ปุ่มเต็มจอ, นาฬิกาดิจิทัล real-time (โค้ดร่วมใน `app.js:initPidsControls()`)
- ดึงข้อมูล AJAX จาก `api/get_queues.php` ทุก 3 วินาที และ **ขานเสียงอัตโนมัติ** ทั้งคิวตรวจ (`speakQueue`) และคิวรับยา (`speakPharmacyQueue`) เมื่อมีคิวใหม่ถูกเรียก (เทียบ key `number_called_at`)
- **ไม่บังคับ login** (จอสาธารณะ) | ตาราง: `departments`

### 12. `api/get_queues.php` — JSON API สำหรับ Real-time
- Endpoint: `GET /api/get_queues.php?dept_id=` คืน JSON:
  - `calling`: คิวสถานะ called/serving 6 รายล่าสุด (ซ่อนชื่อ-นามสกุลเต็ม เฉพาะ 3 ตัวอักษรแรก) + ห้องตรวจ
  - `waiting`: คิวรอ 10 รายแรก (เรียงลำดับคิว) + ห้องตรวจ
  - `last_called`: คิวที่ถูกเรียกล่าสุด (ใช้ trigger เสียงบนจอ TV)
  - `pharmacy_calling` / `pharmacy_waiting` / `pharmacy_last_called`: คิวรับยาสถานะ called / waiting+preparing / ล่าสุด
- ใช้โดย `queue_display.php` และ `pharmacy_display.php` | ตาราง: `queues`, `visits`, `patients`, `departments`, `rooms`, `pharmacy_queues`

## 💊 ระบบคิวรับยา (Pharmacy)

### 13. `pharmacy.php` — จุดจ่ายยาและจัดการคิวรับยา
- ตัวกรอง: สถานะคิวรับยา (`?status=`) และช่องจ่ายยา (`?counter=`) → auto-submit + สรุปจำนวนแยกสถานะ
- การ์ดคิวแต่ละใบมีปุ่มตามสถานะ (`waiting` → เริ่มจัดยา/เรียกรับยา, `preparing` → จัดเสร็จแล้วเรียกรับยา, `called` → จ่ายยาเสร็จสิ้น/เรียกซ้ำ)
- การเรียกขานเสียงไทย `speakPharmacyQueue()` ระบุช่องจ่ายยา; จ่ายยาเสร็จ → อัปเดต `visits` เป็น completed
- ทุกใบ: พิมพ์สลิปรับยา, ยกเลิกคิว (มี confirm)
- **ต้อง Login** | ตาราง: `pharmacy_queues`, `visits`, `patients`, `departments`, `rooms`, `queues`

### 14. `print_pharmacy.php` — พิมพ์สลิปรับยา (`?id=`)
- สลิปรูปแบบเดียวกับบัตรคิวตรวจแต่ใช้สีประจำห้องยา (เขียว) แสดงช่องจ่ายยา + ต้นทางแผนก/ห้องตรวจ
- รองรับ `?auto=1` พิมพ์อัตโนมัติ, หน้า standalone + no-print
- **ต้อง Login** | ตาราง: `pharmacy_queues`, `visits`, `patients`, `departments`, `rooms`, `queues`

### 15. `pharmacy_display.php` — จอทีวีคิวรับยาห้องยา (TV Public Display)
- ธีมสว่าง (PIDS) 2 แผง: ซ้าย "กำลังเรียกรับยา" (ตัวใหญ่สีเขียวประจำห้องยา + ป้ายช่องจ่ายยา) + ประวัติ, ขวา "ลำดับคิวรอรับยา" แยกป้าย "กำลังจัดยา/รอจัดยา"
- ฟีเจอร์เดียวกับจอตรวจ: สวิตช์เสียง, เต็มจอ, นาฬิกา (`initPidsControls()`), polling 3 วินาที + ขานเสียง `speakPharmacyQueue()` อัตโนมัติ
- **ไม่บังคับ login** (จอสาธารณะ)

---

## ⚙️ จัดการระบบ (Master Data)

### 16. `departments.php` — จัดการแผนกตรวจ/ห้องตรวจ (`?tab=rooms|departments`)
- แท็บห้องตรวจ: เพิ่ม/แก้ไขห้อง (เลขห้อง, ชื่อ, สังกัดแผนก, แพทย์ประจำห้อง), เปิด/ปิดใช้งาน (`?toggle_room=`)
- แท็บแผนก: เพิ่มแผนกใหม่ (รหัสตัวพิมพ์ใหญ่ห้ามซ้ำ — ใช้นำหน้าเลขคิว เช่น MED-001, ชื่อ, รายละเอียด), เปิด/ปิดใช้งาน (`?toggle_id=`)
- ปุ่มลิงก์ดูคิวของแผนกนั้น (`queues.php?dept=CODE`)
- **ต้อง Login** (ทุกบทบาทเห็นได้) | ตาราง: `departments`, `rooms`, `staff`

### 17. `staff.php` — จัดการแพทย์และเจ้าหน้าที่ (Admin เท่านั้น)
- เพิ่ม/แก้ไขบุคลากร: username ห้ามซ้ำ (แก้ไม่ได้หลังสร้าง), รหัสผ่าน `password_hash`, บทบาท (แพทย์ / เจ้าหน้าที่ลงทะเบียน / เภสัชกร / ผู้ดูแลระบบ), สังกัดแผนก+ห้องตรวจประจำ (dropdown ห้องกรองตามแผนกผ่าน JS)
- ตารางรายชื่อ + ปุ่ม ระงับ/เปิดใช้ (`?toggle_id=`) — **ห้ามระงับบัญชีตัวเอง**
- ปุ่มเมนูนี้จะแสดงใน Sidebar เฉพาะตอน login เป็น Admin และหน้าเช็ค `require_admin()` ซ้ำ
- **ต้องเป็น Admin** | ตาราง: `staff`, `departments`, `rooms`

---

## 🧩 ส่วนประกอบประกอบ (Support Files)

| ไฟล์ | หน้าที่ |
|---|---|
| `config/database.php` | เชื่อมต่อ MySQL ด้วย PDO (ตั้งค่า host/db/user/pass) + `session_start()` |
| `includes/header.php` | Layout หลัก: Sidebar เมนู, Topbar (วันที่ไทย), Flash message; ซ่อนเมนู Admin เมื่อไม่ใช่ Admin |
| `includes/footer.php` | ส่วนท้าย HTML + น่าจะรวม JS พื้นฐาน |
| `includes/functions.php` | ฟังก์ชันช่วย: `require_login()`, `require_admin()`, `generate_hn()`, `generate_queue_number()`, `generate_pharmacy_queue_number()`, `get_queue_status_badge()`, `get_pharmacy_status_badge()`, `thai_datetime()`, `set_flash()` |
| `assets/css/style.css` | ธีมทั้งระบบ (design tokens: clinical palette, triage, pharmacy RX) + ธีม TV display (PIDS) |
| `assets/js/app.js` | `speakQueue()` / `speakPharmacyQueue()` — เสียงเรียกคิวภาษาไทย (Web Speech API) + `initPidsControls()` (นาฬิกา/สวิตช์เสียง/เต็มจอ ใช้ร่วมกันทุกจอ TV) |
| `schema.sql` | สร้าง DB `hospital_queue_db` + 7 ตาราง (patients, departments, rooms, staff, visits, queues, pharmacy_queues) + seed ข้อมูล |
| `Dockerfile` / `docker-compose.yml` | PHP 8.2 + Apache + MySQL + phpMyAdmin รันด้วย `docker compose up -d` |

---

## 🔁 แผนผังการไหลของผู้ใช้ (User Flow)

```
login.php
   └─→ index.php (แดชบอร์ด)
         ├─→ patient_add.php ──(ติ๊กออกคิว)──→ print_queue.php
         ├─→ patients.php ──→ patient_detail.php ──→ patient_edit.php
         │                  └─→ visits.php ──→ print_queue.php
         ├─→ queues.php (เรียก/เข้าตรวจ/เสร็จ/ยกเลิก/ส่งรับยา + เสียงไทย)
         │       └─→ pharmacy.php (จัดยา/เรียกรับยา/จ่ายยา + เสียงไทย) ──→ print_pharmacy.php
         ├─→ queue_display.php (จอ TV รวม ← AJAX ← api/get_queues.php)
         ├─→ pharmacy_display.php (จอ TV ห้องยา ← AJAX ← api/get_queues.php)
         ├─→ departments.php (จัดการแผนก + ห้องตรวจ)
         └─→ staff.php (Admin เท่านั้น)
```

## 📊 สถานะคิว (Queue Status Flow)

```
waiting ──(เรียกคิว)──→ called ──(เข้าตรวจ)──→ serving ──(เสร็จสิ้น)──→ completed
   │                      │                        │
   └──────────────────────┴───(ยกเลิก)────────────┴──→ cancelled

คิวรับยา (pharmacy_queues): waiting ──(เริ่มจัดยา)──→ preparing ──(เรียกรับยา)──→ called ──(จ่ายยา)──→ completed
(ออกคิว RX เมื่อแพทย์กด "เสร็จสิ้นและส่งรับยา" ที่ queues.php; จ่ายยาเสร็จ → visits = completed)
```
