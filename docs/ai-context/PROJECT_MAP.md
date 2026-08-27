# PROJECT MAP

## 1. ชั้นของระบบ (ตามโค้ดจริง)

```
Browser
  │
  ├─ *.php (root)      หน้า public / member      ─┐
  ├─ admin/*.php       หน้า staff / admin         ├─→ Service ─→ Repository ─→ PDO ─→ MySQL
  ├─ api/*.php         JSON / HTML partial        ─┘                │
  └─ cron/*.php        CLI เท่านั้น                                  └─ ทุก SQL อยู่ชั้นนี้เท่านั้น
```

ทุกไฟล์เริ่มด้วย `require_once bootstrap.php` → โหลด `includes/config.php` → `includes/db.php` → `includes/functions.php` → ลง autoloader → `startSession()` ทำงานอัตโนมัติท้าย `functions.php`

**Autoloader** (`bootstrap.php:73`) map เฉพาะ 3 namespace:
`App\Services\` → `app/Services/`, `App\Repositories\` → `app/Repositories/`, `App\Helpers\` → `app/Helpers/` (โฟลเดอร์ Helpers **ยังไม่มีจริง** — เตรียมไว้)

## 2. โฟลเดอร์

| โฟลเดอร์ | หน้าที่ | แก้ได้ไหม |
|----------|--------|-----------|
| `/` (root) | หน้า public + member + `bootstrap.php` + `install.php` | ✅ หน้าเว็บแก้ได้ / ❌ `bootstrap.php` |
| `admin/` | หน้า staff+admin ทั้งหมด (`header.php` มี `requireStaff()`) | ✅ |
| `api/` | 5 endpoint (JSON 4 + HTML partial 1) | ⚠️ ระวัง auth |
| `app/Services/` | Business logic ทั้งหมด (8 ไฟล์) | ⭐ แก้กติกาที่นี่ |
| `app/Repositories/` | SQL ทั้งหมด (9 ไฟล์) | ⭐ แก้ query ที่นี่ |
| `includes/` | config, db, functions, header/footer, partial (`book_grid.php`, `pagination.php`) | ⚠️ ระวัง |
| `database/` | `schema.sql`, `sample_data.sql`, migration script (บล็อกจากเว็บทั้งโฟลเดอร์ + migration ต้องรันผ่าน CLI) | — |
| `cron/` | 2 งานตามเวลา (CLI-guarded) | ✅ |
| `docs/` | เอกสาร 11 ไฟล์ + `samples/` CSV | ✅ |
| `tests/` | 30+ test scripts + `fixtures/` (CSV + `seed_test_data.php` ชุดข้อมูล L1) + `logs/` — บล็อกจาก web ทั้งโฟลเดอร์ | ✅ |
| `uploads/covers/` | Apache ต้องเขียน/ลบได้ (อัปโหลดรูปปก) — 755 + ให้สิทธิ์ user ของ Apache เฉพาะตัว ไม่ต้องใช้ 777 | — |
| `logs/` | เขียนโดย `cron/*.php` ผ่าน CLI เท่านั้น — 755 พอ web server ไม่ต้องเขียน | — |

## 3. Service Layer (8)

| Service | ความรับผิดชอบ | Transaction? |
|---------|---------------|--------------|
| `BorrowService` | ยืม / คืน / คิดค่าปรับ / รับชำระ | ✅ 3 method (createBorrow, returnBook, payFine) |
| `ReservationService` | จอง / ยกเลิก / อนุมัติ / expire | ✅ 4 method |
| `BookService` | CRUD หนังสือ + คำนวณ available ตอนแก้ quantity | ✅ updateBook, deleteBook |
| `MemberService` | CRUD สมาชิก + import | ✅ deleteMember |
| `AuthService` | login / register / profile / เปลี่ยน-รีเซ็ตรหัสผ่าน | ✅ resetPassword |
| `DashboardService` | รวมสถิติหน้า admin (อ่านอย่างเดียว) | — |
| `ReportService` | รายงาน (อ่านอย่างเดียว) | — |
| `HomeService` | หน้าแรก public + AJAX search (+ lazy-expire) — **ที่เดียวที่นิยาม "public เห็นอะไรได้"** (`visible_only`) | — |

> `AuthService::register()` **delegate** ไป `MemberService::createMember()` — การสร้างสมาชิกมีทางเดียว (single source of truth)

## 4. Repository Layer (9)

`BookRepository` (19 method) · `BorrowRepository` (25) · `ReservationRepository` (16) · `UserRepository` (15) · `ReportRepository` (14) · `CategoryRepository` (11) · `PaymentRepository` (6) · `PasswordResetRepository` (6) · `SettingsRepository` (5)

**Method ที่ล็อกแถว (`FOR UPDATE`) — ห้ามเอาออก:**

| Method | ไฟล์ | ป้องกันอะไร |
|--------|------|-------------|
| `BookRepository::findByIdForUpdate()` | `app/Repositories/BookRepository.php:631` | 2 คนยืม/จองเล่มสุดท้ายพร้อมกัน |
| `BorrowRepository::findByIdForUpdate()` | `:317` | คืนซ้ำ (บวก `WHERE status='borrowing'`) |
| `BorrowRepository::findByIdForUpdateAnyStatus()` | `:352` | ชำระค่าปรับซ้ำ |
| `BorrowRepository::countActiveBorrowsForUpdate()` | `:492` | นับโควตาแบบล็อก |
| `UserRepository::lockById()` | `app/Repositories/UserRepository.php:430` | ยืม+จองพร้อมกันทำให้เกินโควตา |
| `ReservationRepository::findPendingForUpdate()` | `app/Repositories/ReservationRepository.php:493` | อนุมัติ/ยกเลิกซ้ำ |
| `ReservationRepository::findExpiredForUpdate()` | `:523` | cron ชนกับ lazy-expire |

## 5. Map: Page/API → Service → Repository

### หน้า Public / Member

| ไฟล์ | สิทธิ์ | CSRF | เรียกอะไร | ตารางที่แตะ |
|------|--------|------|-----------|-------------|
| `index.php` | public | — | `HomeService` | books, categories, users |
| `book.php` | public (ซ่อนถ้า `is_visible=0`) | — | `BookService`, `BorrowRepository`, `ReservationService` | books, borrows, reservations |
| `login.php` | public | ✅ | `AuthService::login()` | users, rate_limits |
| `register.php` | public | ✅ | `AuthService::register()` → `MemberService` | users, rate_limits |
| `forgot_password.php` | public | ✅ | `AuthService::requestPasswordReset()` | password_resets |
| `reset_password.php` | public + token | ✅ | `AuthService::resetPassword()` | password_resets, users |
| `logout.php` | login | ✅ | — | — |
| `my_borrows.php` | login | — (read-only) | `BorrowRepository` (scope `session.user_id`) | borrows |
| `my_reservations.php` | login | — (POST ไป api) | `ReservationRepository` | reservations |
| `profile.php` | login | ✅ | `AuthService`, `BorrowRepository`, `ReservationRepository` | users, borrows, reservations, payments |

### API

| Endpoint | Method | สิทธิ์ | CSRF | Rate limit | Idempotency | เรียก |
|----------|--------|--------|------|-----------|-------------|-------|
| `api/reserve_book.php` | POST | login | ✅ | 10 ครั้ง/5 นาที ต่อ **user** | ✅ 5 วิ | `ReservationService::createReservation()` |
| `api/cancel_reservation.php` | POST | login + owner | ✅ | — | ✅ | `ReservationService::cancelReservation($id, $userId)` |
| `api/search_books.php` | GET | public | — | 60 ครั้ง/5 นาที ต่อ IP | — | `HomeService::getBooks()` (กรอง `is_visible` ให้ — ห้ามเรียก repo ตรง) |
| `api/add_member.php` | POST | staff | ✅ | — | — | `MemberService::createMember()` (คืนรหัสผ่านที่สุ่มให้ไปแสดงบนหน้าจอ) |
| `api/member_history.php` | GET | staff | — | — | — | `BorrowRepository::findByUserId()` |

### หน้า Admin (ทุกหน้าผ่าน `admin/header.php` → `requireStaff()`)

| ไฟล์ | สิทธิ์ | CSRF | เรียก | หมายเหตุ |
|------|--------|------|-------|----------|
| `admin/index.php` | staff | — | `DashboardService`, `ReservationService` | 7 stat card + กราฟ Chart.js |
| `admin/books.php` | staff | ✅ | `BookService` (list/delete) | idempotency `delete_book_*` |
| `admin/book_form.php` | staff | ✅ | `BookService` create/update + อัปโหลดปก | finfo MIME check |
| `admin/book_labels.php` | staff | — | `BookRepository::findAllForLabels()` | JsBarcode (CODE128 จาก ISBN) |
| `admin/categories.php` | staff | ✅ | `CategoryRepository` | — |
| `admin/members.php` | staff | ✅ | `MemberService` | idempotency `delete_member_*` |
| `admin/member_form.php` | staff | ✅ | `MemberService` | role whitelist `member|staff` |
| `admin/member_card.php` | staff | — | `UserRepository` | QR + CODE128 จาก `user.id` |
| `admin/borrow_form.php` | staff | ✅ (2 จุด) | `BorrowService::createBorrow()` | AJAX `action=scan` สำหรับ barcode |
| `admin/borrows.php` | staff | ✅ | `BorrowService::returnBook()` | idempotency `return_*` |
| `admin/payments.php` | staff | ✅ | `BorrowService::payFine()` | idempotency `pay_fine_*` |
| `admin/reservations.php` | staff | ✅ | `ReservationService` approve/cancel | idempotency `reservation_*` |
| `admin/import_books.php` | staff | ✅ | `BookRepository`, `CategoryRepository` | TX ครอบทั้งไฟล์ |
| `admin/import_members.php` | staff | ✅ | `MemberService::importMember()` | — |
| `admin/reports.php` | **admin** | — | `ReportRepository` + `report_helper.php` | export CSV (มี BOM) |
| `admin/export_pdf.php` | **admin** | — | `ReportRepository` + `report_helper.php` | HTML print view ไม่ใช่ PDF lib |
| `admin/settings.php` | **admin** | ✅ | `getSetting/updateSetting` | มีแค่ 3 key |

### Cron (CLI เท่านั้น — มี guard `php_sapi_name() !== 'cli'`)

| ไฟล์ | ทำอะไร | เรียก |
|------|--------|-------|
| `cron/expire_reservations.php` | pending หมดอายุ → expired + คืน stock | `ReservationService::expireOverdueReservations()` |
| `cron/cleanup_tokens.php` | ลบ token reset ที่หมดอายุ | `PasswordResetRepository::deleteExpired()` |

> ทั้งคู่เขียน log ที่ `logs/cron.log` และ **ไม่ทำงานเองหลังอัปโหลดโค้ด** — ต้องตั้ง crontab/cPanel เพิ่ม (ดู `docs/DEPLOYMENT.md`)
> ถึงไม่ตั้ง cron ระบบก็ยังไม่พัง เพราะมี **lazy expire** ใน `ReservationRepository::markExpiredReservations()` ที่ถูกเรียกทุกครั้งที่เปิดหน้าแรก/หน้าจอง
