# BUSINESS RULE MATRIX

> ทุกกฎด้านล่างอ่านจากโค้ดจริง ไม่ใช่จากเอกสาร — คอลัมน์ "ที่มา" คือจุดที่ต้องไปแก้

## 1. การยืม (Borrow)

| # | กฎ | ค่า/พฤติกรรม | ที่มาในโค้ด |
|---|-----|--------------|-------------|
| B-01 | ใครยืมได้ | `member` + `staff` เท่านั้น — **admin ยืมไม่ได้** | `UserRepository::findMemberById()` มี `role IN ('member','staff')` |
| B-02 | ใครทำรายการยืมได้ | staff/admin เท่านั้น (ผ่านหน้า admin) — สมาชิกยืมเองไม่ได้ ต้อง "จอง" แล้วให้ staff อนุมัติ | `admin/borrow_form.php:20` `requireStaff()` |
| B-03 | โควตาต่อคน | `MAX_BORROW_BOOKS` (default 3) นับ **ยืมค้าง + จอง pending** รวมกัน | `BorrowService.php:147-157` |
| B-04 | จำนวนวันยืม | default `DEFAULT_BORROW_DAYS` (7) — staff ปรับได้ต่อรายการ แต่ต้อง **1–30 วัน** | `BorrowService.php:122` |
| B-05 | ยืมเล่มเดิมซ้ำ | ห้าม ถ้ายังไม่คืน | `BorrowService.php:444` → `BorrowRepository::isAlreadyBorrowing()` |
| B-06 | หนังสือหมด | `available <= 0` → ยืมไม่ได้ (เช็คใต้ row lock + `WHERE available > 0` ตอน decrement) | `BorrowService.php:438` + `:450` |
| B-07 | ยืมหลายเล่มพร้อมกัน | **All-or-nothing** — เล่มใดพลาด rollback ทั้งชุด (ไม่มี partial success) | `BorrowService.php:165-176` |
| B-08 | due_date | `วันนี้ + borrowDays` (เก็บเป็น DATE ไม่มีเวลา) | `BorrowService.php:133` |

## 2. การคืน (Return)

| # | กฎ | พฤติกรรม | ที่มา |
|---|-----|----------|-------|
| R-01 | คืนได้เมื่อไหร่ | เฉพาะรายการ `status='borrowing'` — คืนซ้ำไม่ได้ (query lock กรอง status ในตัว) | `BorrowRepository::findByIdForUpdate()` |
| R-02 | ผลของการคืน | `status→returned`, `return_date=วันนี้`, `fine_amount=ค่าปรับที่คำนวณ`, `books.available +1` — ทั้งหมดใน TX เดียว | `BorrowService.php:235-237` |
| R-03 | คืนสาย = ค่าปรับ | `จำนวนวันเกิน × FINE_PER_DAY` (default 10 บาท/วัน) | `BorrowService::calculateFine()` :272-290 |
| R-04 | คืนก่อน/ตรงกำหนด | ค่าปรับ = 0 | `BorrowService.php:290` |
| R-05 | นับวันเกินยังไง | `DateTime::diff()->days` = **จำนวนวันเต็ม** (คืนช้าไม่ถึง 1 วันไม่โดนปรับ) | `BorrowService.php:282` |
| R-06 | รับเงินตอนคืนได้เลย | `payNow=true` → สร้าง payment ใน TX เดียวกัน | `BorrowService.php:242` |

⚠️ **ค่าปรับ "ตกผลึก" ตอนคืนเท่านั้น** — รายการที่เกินกำหนดแต่ยังไม่คืน `fine_amount` ยังเป็น 0 และไม่โผล่ในยอดค้างชำระ หน้า Dashboard แสดงเป็น "รายการเกินกำหนด" แยกต่างหาก

## 3. ค่าปรับ / การชำระ (Payment)

| # | กฎ | พฤติกรรม | ที่มา |
|---|-----|----------|-------|
| P-01 | 1 borrow = 1 payment | บังคับด้วย `UNIQUE(borrow_id)` ใน DB + เช็คใน service ใต้ lock | `schema.sql:127`, `BorrowService.php:389-397` |
| P-02 | จ่ายบางส่วน | **ทำไม่ได้** — จ่ายเต็มจำนวนครั้งเดียว | `PaymentRepository::create()` |
| P-03 | ไม่มีค่าปรับ | `fine_amount <= 0` → ปฏิเสธการรับชำระ | `BorrowService.php:385` |
| P-04 | ใครรับเงินได้ | staff/admin (`recorded_by` = session user) | `admin/payments.php:49` |
| P-05 | ยกเลิก/คืนเงิน | **ไม่มี** — ลบ payment ได้แค่ผ่าน DB โดยตรง | — |
| P-06 | ยอดค้างชำระ | `borrows.fine_amount > 0 AND ไม่มีแถวใน payments` | `BorrowRepository::getUnpaidFinesList()` :850 |

## 4. การจอง (Reservation)

| # | กฎ | พฤติกรรม | ที่มา |
|---|-----|----------|-------|
| V-01 | ใครจองได้ | ผู้ใช้ที่ login (ผ่าน `api/reserve_book.php`) | `api/reserve_book.php:8` |
| V-02 | **จองหัก stock ทันที** | `available -1` ตั้งแต่กดจอง ไม่ใช่ตอนอนุมัติ | `ReservationService.php:163` |
| V-03 | อายุการจอง | **2 วัน** (hard-code เป็น default param `$expireDays = 2`, ไม่มีใน `.env`) | `ReservationService.php:98` |
| V-04 | จองหนังสือที่ซ่อน | ไม่ได้ (`is_visible=0` → ปฏิเสธ) | `ReservationService.php:117` |
| V-05 | จองซ้ำเล่มเดิม | ไม่ได้ ถ้ามี pending อยู่แล้ว | `ReservationService.php:128` |
| V-06 | จองเล่มที่ยืมอยู่ | ไม่ได้ | `ReservationService.php:137` |
| V-07 | โควตา | นับ `ยืมค้าง + pending` ≥ `MAX_BORROW_BOOKS` → จองไม่ได้ | `ReservationService.php:152` |
| V-08 | State transition | `pending → fulfilled / cancelled / expired` (เปลี่ยนกลับไม่ได้) | `ReservationService` |
| V-09 | อนุมัติ (fulfill) | สร้าง `borrows` ใหม่ + ผูก `borrow_id` — **ไม่หัก stock ซ้ำ** เพราะหักตอนจองแล้ว | `ReservationService.php:291-301` |
| V-10 | ยกเลิก / หมดอายุ | คืน stock `available +1` เสมอ | `ReservationService.php:218` + `:343` |
| V-11 | ใครยกเลิกได้ | member = เฉพาะของตัวเอง (ส่ง `userId` เข้า service), staff/admin = ของใครก็ได้ (ไม่ส่ง `userId`) | `api/cancel_reservation.php:52` vs `admin/reservations.php:57` |
| V-12 | หมดอายุอัตโนมัติ | 2 ทาง: **lazy expire** ทุกครั้งที่โหลดหน้าแรก/รายการหนังสือ + **cron** | `ReservationRepository::markExpiredReservations()`, `cron/expire_reservations.php` |

## 5. หนังสือ (Book)

| # | กฎ | พฤติกรรม | ที่มา |
|---|-----|----------|-------|
| K-01 | ลบหนังสือไม่ได้ถ้า... | (1) กำลังถูกยืม (2) มีประวัติการยืม (3) มี pending reservation | `BookService::deleteBook()` :249-261 |
| K-02 | แก้ `quantity` | `available` ปรับตาม diff อัตโนมัติ: `available_ใหม่ = available_เดิม + (qty_ใหม่ − qty_เดิม)` | `BookService.php:182-186` |
| K-03 | ลด quantity ต่ำกว่าที่ออกอยู่ | ห้าม — throw error พร้อมบอกจำนวนที่ออกอยู่ | `BookService.php:188-190` |
| K-04 | `quantity = 0` | อนุญาต (ใช้กับหนังสือหาย/ชำรุด) — commit `4d64088` | `BookService.php` |
| K-05 | ซ่อนหนังสือ | ใช้ `is_visible = 0` **ไม่ใช่** `quantity = 0` (ตรงกับ Context §8) | `admin/book_form.php:82` |
| K-06 | ISBN | UNIQUE — เว้นว่างได้ (NULL ซ้ำได้) | `schema.sql:63` |
| K-07 | รูปปก | อัปโหลด jpeg/png/gif/webp ≤ **2MB**, ตรวจ MIME จาก **เนื้อไฟล์** (finfo), ตั้งชื่อไฟล์ใหม่จาก MIME | `admin/book_form.php:107-137` |
| K-08 | Import CSV | merge ตาม **ชื่อ+ผู้แต่ง** (เพิ่ม quantity), ISBN ซ้ำ = skip, หมวดหมู่ไม่มี = สร้างอัตโนมัติ, ทั้งไฟล์อยู่ใน TX เดียว | `admin/import_books.php:60-105` |

## 6. สมาชิก (Member)

| # | กฎ | พฤติกรรม | ที่มา |
|---|-----|----------|-------|
| M-01 | ลบสมาชิกไม่ได้ถ้า... | (1) มีประวัติการยืม (2) มี pending reservation | `MemberService::deleteMember()` :231-241 |
| M-02 | ลบ admin | **ไม่ได้เด็ดขาด** — `DELETE ... WHERE role IN ('member','staff')` | `UserRepository::deleteMember()` :617 |
| M-03 | เปลี่ยน role | admin/staff ตั้งได้เฉพาะ `member` หรือ `staff` — เลื่อนเป็น admin ผ่าน UI ไม่ได้ | `MemberService.php:198` |
| M-04 | สมัครเอง | ได้ role `member` เสมอ (hard-code) | `MemberService::createMember()` :126 |
| M-05 | รหัสผ่าน | ขั้นต่ำ `MIN_PASSWORD_LENGTH` (6) · bcrypt · ถ้าไม่กรอกตอน admin สร้าง → สุ่ม 8 ตัวแล้วแสดงครั้งเดียว | `functions.php:460`, `MemberService.php:141` |
| M-06 | เปลี่ยน email เอง | **ไม่ได้** — `updateProfile()` เขียนทับด้วย email เดิมจาก DB เสมอ | `AuthService::updateProfile()` |
| M-07 | เปลี่ยนรหัสผ่าน | ต้องยืนยันรหัสเดิม + ห้ามซ้ำรหัสเดิม | `AuthService::changePassword()` |
| M-08 | Import สมาชิก | รหัสผ่าน default `123456` ถ้าไฟล์ไม่ระบุ | `MemberService::importMember()` :342 |

## 7. Auth / Session

| # | กฎ | ค่า | ที่มา |
|---|-----|-----|-------|
| A-01 | Session timeout | ไม่มีกิจกรรมเกิน `SESSION_LIFETIME` (3600 วิ) → ล้าง session | `functions.php:617` |
| A-02 | Login สำเร็จ | `session_regenerate_id(true)` กัน session fixation | `login.php:68` |
| A-03 | Redirect หลัง login | admin/staff → `admin/`, member → `index.php` | `login.php:78-82` |
| A-04 | Login ผิด | ไม่บอกว่า "ไม่พบ email" หรือ "รหัสผิด" (กัน user enumeration) | `AuthService::login()` :86 |
| A-05 | ลืมรหัสผ่าน | token 64 hex, อายุ **1 ชม.**, ใช้ได้ครั้งเดียว, ขอได้ 3 ครั้ง/ชม./email | `AuthService.php:273-282` + `:313-328` |
| A-06 | ส่งอีเมลจริง | **ไม่มี** — ระบบแค่สร้างลิงก์;  โชว์ลิงก์บนหน้าจอเฉพาะเมื่อ `APP_DEBUG=true` **และ** เรียกจาก 127.0.0.1/::1 | `forgot_password.php` |

## 8. Rate Limit ที่ตั้งไว้จริง

| Action | เพดาน | ผูกกับ | ที่มา |
|--------|-------|--------|-------|
| Login | `RATE_LIMIT_MAX_ATTEMPTS` ต่อ `RATE_LIMIT_WINDOW_MINUTES` | `md5(email)` + IP | `login.php:55` |
| Register | เท่ากัน | IP | `register.php:46` |
| Forgot password | เท่ากัน (+ 3/ชม. ต่อ email ในชั้น service) | IP | `forgot_password.php:42` |
| เปลี่ยนรหัสผ่าน | เท่ากัน | **IP อย่างเดียว** (ไม่ผูก user) | `profile.php:97` |
| ค้นหาหนังสือ | 60 ครั้ง / 5 นาที | IP | `api/search_books.php:29` |
| จองหนังสือ | 10 ครั้ง / 5 นาที | **user_id** (ตั้งใจไม่ผูก IP เพื่อกัน bypass ด้วยการเปลี่ยน IP) | `api/reserve_book.php:58` |

> ค่า default ใน `config.php` = **5 ครั้ง / 15 นาที** และ `.env.example` ตั้งค่าตรงกันแล้ว (ก่อนหน้านี้ตั้งหลวมกว่าเป็น 10/10 — ดู FINDINGS F-03)
