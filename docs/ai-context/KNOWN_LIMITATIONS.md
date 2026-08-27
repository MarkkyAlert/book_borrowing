# KNOWN LIMITATIONS

> ข้อจำกัดที่ **ยืนยันจากโค้ดจริง** — ใช้ตอบลูกค้าแบบไม่ขายเกินจริง
> ดู `docs/LIMITATIONS.md` ประกอบ (ฉบับนั้นเน้นเชิงธุรกิจ ฉบับนี้เน้นเชิงเทคนิคที่ตรวจแล้ว)

## 1. สถาปัตยกรรม / สเกล

| ข้อจำกัด | รายละเอียด |
|----------|-----------|
| Monolith เครื่องเดียว | ไม่มี failover / load balancer / cache layer |
| ค้นหาใช้ `LIKE '%คำ%'` | ใช้ index ไม่ได้ — ช้าเมื่อหนังสือหลักหมื่นเล่ม (ยังไม่มี FULLTEXT) |
| ไม่มี pagination บนหลายหน้า | `admin/books.php`, `admin/members.php`, `index.php` โหลดทั้งชุด (มี paginate เฉพาะ `my_borrows`) |
| ไม่ใช่ real-time | ไม่มี WebSocket/SSE — หลายเครื่องเห็นตรงกันเพราะใช้ **DB กลางชุดเดียว** ต้อง reload เอง |
| Session เก็บใน filesystem | scale หลาย server ต้องย้ายไป Redis/DB เอง |
| ไม่มี multi-tenant / หลายสาขา | เพิ่มทีหลัง = เปลี่ยน data model ครั้งใหญ่ |

## 2. ขึ้นกับอินเทอร์เน็ต (สำคัญกับห้องสมุด intranet)

หน้าเว็บโหลด asset จาก CDN ทั้งหมด — **ถ้าเครื่องไม่ต่อเน็ต หน้าจะเพี้ยนและบางฟีเจอร์ใช้ไม่ได้**:

| ไลบรารี | ใช้ที่ไหน | ถ้าโหลดไม่ได้ |
|---------|-----------|---------------|
| `cdn.tailwindcss.com` | ทุกหน้า public/member | หน้าเว็บไม่มี style เลย |
| Bootstrap 5 + Bootstrap Icons | หน้า admin, install | style/ไอคอนหาย |
| `fonts.googleapis.com` (Sarabun) | ทุกหน้า | fallback เป็นฟอนต์ระบบ |
| Chart.js | Dashboard | กราฟไม่ขึ้น |
| Select2 | ฟอร์มยืม | dropdown ค้นหาไม่ได้ |
| flatpickr | เลือกวันที่ | ใช้ input ธรรมดาแทน |
| **JsBarcode** | ฉลากหนังสือ + บัตรสมาชิก | **พิมพ์ barcode ไม่ได้** |
| **QRCode.js** | บัตรสมาชิก | **QR ไม่ขึ้น** |

> `cdn.tailwindcss.com` เป็น Play CDN ที่ผู้พัฒนา Tailwind ระบุว่าไม่เหมาะกับ production
> **ถ้าลูกค้าใช้งานออฟไลน์: ต้อง self-host ไฟล์เหล่านี้ก่อน** (งานเล็ก แต่ต้องทำ)

## 3. ฟีเจอร์ที่คนมักเข้าใจผิดว่ามี

| เข้าใจว่า | ความจริง |
|-----------|----------|
| "ส่งอีเมลแจ้งเตือน / รีเซ็ตรหัสผ่านทางอีเมล" | สร้าง token ได้ แต่ **ไม่ส่งอีเมล** — ต้องเพิ่ม mail service เอง |
| "Export PDF" | เป็นหน้า HTML สำหรับ print → ผู้ใช้กด Save as PDF ในกล่อง print เอง (ไม่มี PDF library) |
| "ตั้งค่าระบบในหน้า Settings" | หน้า Settings มีแค่ **3 ค่า** (ชื่อหน่วยงาน + สีบัตร 2 สี) — กฎการยืม/ค่าปรับ/โควตาอยู่ใน `.env` |
| "ต่ออายุการยืม (renew)" | ไม่มี |
| "ค่าปรับเดินทุกวันอัตโนมัติ" | คิดตอน **คืน** เท่านั้น — ที่ยังไม่คืน `fine_amount` = 0 |
| "จ่ายค่าปรับบางส่วนได้" | ไม่ได้ — 1 borrow จ่ายได้ครั้งเดียวเต็มจำนวน (UNIQUE constraint) |
| "รองรับ scanner ทุกยี่ห้อ" | รองรับ scanner ที่ทำงานแบบ **HID/keyboard emulation** (ยิงค่าเหมือนพิมพ์ + Enter) เท่านั้น |
| "สมาชิกยืมเองได้" | ไม่ได้ — สมาชิก "จอง" แล้ว staff อนุมัติ; การยืมทำโดย staff เท่านั้น |
| "ระงับสมาชิกได้" | ไม่มีคอลัมน์ `is_active` — ทำได้แค่ลบ (ซึ่งลบไม่ได้ถ้ามีประวัติ) |

## 4. ข้อจำกัดเชิงข้อมูล

- **ลบหนังสือ/สมาชิกที่มีประวัติไม่ได้** (ตั้งใจ — FK RESTRICT + guard ใน Service) วิธีที่ถูกคือซ่อนด้วย `is_visible`
- **ไม่มี soft delete สำหรับสมาชิก** — มีแค่หนังสือที่ซ่อนได้
- **ไม่มี audit trail** — รู้แค่ `payments.recorded_by` ว่าใครรับเงิน ไม่รู้ว่าใครแก้ข้อมูลหนังสือ/สมาชิก
- **ไม่มีประวัติการเปลี่ยน stock** — รู้แค่ค่าปัจจุบัน
- **`reservations` ไม่มี UNIQUE กัน pending ซ้ำ** — พึ่ง application logic + row lock
- **ค่าปรับเก็บเป็น `DECIMAL(10,2)` แต่ `FINE_PER_DAY` เป็น int** — ค่าปรับเศษสตางค์ทำไม่ได้จาก config

## 5. ข้อจำกัดของ Idempotency / Concurrency

- Idempotency key เก็บใน **session** (อายุ 5 นาที) — กัน double-submit ได้เฉพาะ session เดียวกัน
- คนละเครื่อง/คนละ session ยิงพร้อมกัน → พึ่ง row lock + UNIQUE constraint (ซึ่งทดสอบแล้วว่าทำงาน)
- `checkRateLimit()` เป็น **fail-open** ถ้า DB ล่ม

## 6. ข้อจำกัดของชุดทดสอบ

- ต้องรันด้วย CLI + Apache เปิดอยู่: `php tests/run_all_tests.php <admin_password>`
- มี TEARDOWN ล้างข้อมูลทดสอบให้อัตโนมัติแล้ว (คืน stock → ลบ reservation/borrow/user/book/category ของรอบนั้น) — ทดสอบแล้วว่าจำนวนแถวและ `available` กลับมาเท่าเดิมทุกค่า
- ถึงอย่างนั้นก็ **ยังไม่ควรรันบน production** เพราะระหว่างรันมีการเขียนข้อมูลจริงลง DB
- SC-07 อ่านค่า `RATE_LIMIT_MAX_ATTEMPTS` จาก config แล้ว — ลูกค้าปรับค่าเองเทสต์ไม่พัง

## 7. ข้อจำกัดของสภาพแวดล้อม

- ต้องการ PHP **8.1+** (ใช้ `match()`, `str_starts_with()`, enum-ish patterns) — ทดสอบบน 8.2.4
- CHECK constraint ทำงานจริงบน MariaDB 10.2.1+ / MySQL 8.0.16+ เท่านั้น
- `uploads/.htaccess` ใช้ `php_flag` → ใช้ได้เฉพาะ **mod_php** (PHP-FPM/LiteSpeed ต้องแก้วิธีอื่น)
- บน macOS/Linux Apache รันคนละ user กับเจ้าของไฟล์ → ต้องเปิดสิทธิ์เขียนให้ `uploads/covers/` และ `logs/`
- Shared hosting ต้องเปิด `AllowOverride` ไม่งั้น `.htaccess` ไม่ทำงาน = `.env` หลุด
