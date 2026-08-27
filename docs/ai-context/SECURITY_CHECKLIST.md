# SECURITY CHECKLIST

> ✅ = ยืนยันจากโค้ด · 🧪 = ทดสอบจริงบนเครื่องนี้แล้ว · ⚠️ = มีอยู่แต่มีข้อจำกัด · ❌ = ไม่มี

## 1. SQL Injection

| รายการ | สถานะ | หลักฐาน |
|--------|-------|---------|
| Prepared statement ทุก query | ✅ | ทุก Repository ใช้ `prepare()/execute()` — ไม่มี string concat ของ user input |
| ปิด emulate prepares | ✅ | `includes/db.php:62` `PDO::ATTR_EMULATE_PREPARES => false` (native prepared statements) |
| `ORDER BY` / `WHERE` แบบ dynamic | ✅ | ใช้ whitelist ผ่าน `match()` — `BookRepository.php:190,209` |
| `LIMIT ?` | ✅ | ทำงานได้เพราะปิด emulate prepares แล้ว |

## 2. XSS

| รายการ | สถานะ | หลักฐาน |
|--------|-------|---------|
| Escape output | ✅ 🧪 | `e()` = `htmlspecialchars(ENT_QUOTES, UTF-8)` — ใช้ 152 จุด; probe `?search=<script>alert(1)</script>` → ออกมาเป็น `&lt;script&gt;` |
| จุดที่ echo ตัวแปรดิบ | ✅ | ตรวจแล้ว ทุกจุดเป็นตัวเลข/boolean/ค่าจาก array ที่ hard-code (เช่น tab label) ไม่มี user input |
| CSV Formula Injection | ✅ 🧪 | `csvSafeValue()` ใน `includes/report_helper.php` เติม `'` นำหน้าค่าที่ขึ้นต้นด้วย `= + - @ \t \r` ก่อน `fputcsv()` — ทดสอบด้วยชื่อหนังสือ `=cmd\|' /C calc'!A0` แล้วออกมาเป็น `'=cmd...` (ดู F-16) |
| การจัดรูปแบบตัวเลขในรายงาน | ✅ 🧪 | ตัดสินจากชื่อคอลัมน์ (`REPORT_COUNT_COLUMNS`/`REPORT_MONEY_COLUMNS`) ไม่ใช้ `is_numeric()` — กันเบอร์โทรถูกแปลงเป็นตัวเลข (ดู F-15) |
| `setFlash()` แบบ HTML (`$isHtml=true`) | ⚠️ | ใช้ 2 จุด: `admin/import_books.php:141`, `admin/import_members.php:113` — ข้อความสรุปผล import ถูก render เป็น HTML ดิบ จุดเดียวที่มีค่าจากผู้ใช้แทรกได้คือ `ISBN` จากไฟล์ CSV — **ใส่ `e()` ครอบแล้ว** (`import_books.php`) · ข้อความอื่นทั้งหมดเป็นข้อความคงที่ · กติกา: **ห้ามเพิ่ม user input ลง `$skippedDetails` โดยไม่ `e()` ก่อน** |

## 3. CSRF

| รายการ | สถานะ | หลักฐาน |
|--------|-------|---------|
| Token per-session, 256-bit | ✅ | `functions.php:568` `bin2hex(random_bytes(32))` |
| เทียบแบบ timing-safe | ✅ | `hash_equals()` — `functions.php:590` |
| ครอบทุก POST ที่เปลี่ยนข้อมูล | ✅ | 21 ไฟล์เรียก `validateCSRFToken()` — รวม logout, AJAX scan, และ API |
| Cookie `SameSite=Lax` | ✅ | `functions.php:606` (ชั้นป้องกันเสริม) |

## 4. Authentication / Authorization

| รายการ | สถานะ | หลักฐาน |
|--------|-------|---------|
| bcrypt | ✅ | `hashPassword()` → `password_hash(PASSWORD_DEFAULT)` |
| ไม่บอกว่า email มีในระบบไหม | ✅ | `AuthService::login()` คืน `null` เหมือนกันทั้ง 2 กรณี |
| `session_regenerate_id(true)` หลัง login | ✅ | `login.php:68` |
| Cookie `HttpOnly` + `Secure`(HTTPS) + `SameSite` | ✅ | `functions.php:600-609` |
| Inactivity timeout | ✅ | `SESSION_LIFETIME` — `functions.php:617` |
| Guard แยกหน้าเว็บ/API | ✅ | `requireLogin/requireStaff/requireAdmin` + `requireStaffApi/requireAdminApi` (คืน JSON 403 แทน redirect) |
| หน้า member ดูข้อมูลคนอื่นได้ไหม | ✅ | `my_borrows/my_reservations/profile` ใช้ `$_SESSION['user_id']` เท่านั้น ไม่รับ id จาก GET/POST |
| ownership check ตอนยกเลิกการจอง | ✅ | `api/cancel_reservation.php` ส่ง `$_SESSION['user_id']` → query มี `AND user_id = ?` |
| ป้องกันเลื่อนสิทธิ์เป็น admin | ✅ | whitelist `['member','staff']` — `MemberService.php:184` |
| ป้องกันแก้ email ตัวเอง | ✅ | `AuthService::updateProfile()` เขียนทับด้วย email จาก DB |
| ป้องกันลบ admin | ✅ | `DELETE ... WHERE role IN ('member','staff')` |

## 5. Brute Force / Abuse

| รายการ | สถานะ | หมายเหตุ |
|--------|-------|----------|
| Rate limit เก็บใน DB | ✅ 🧪 | ทดสอบ login ผิดซ้ำ → ถูกบล็อกที่ครั้งที่ 11 (ตาม `.env`) |
| ครอบ login / register / forgot / เปลี่ยนรหัส / search / reserve | ✅ | ดูตารางใน BUSINESS_RULES §8 |
| DB ล่ม → ยังให้ผ่าน (fail-open) | ⚠️ | ตั้งใจ (ไม่ล็อกเอาต์ทุกคน) แต่แปลว่า rate limit ไม่ใช่ hard guarantee |
| ล้างแถวเก่า | ✅ | probabilistic ~1% ของ request ลบที่เก่ากว่า 1 วัน — `bootstrap.php:59` |
| rate limit เปลี่ยนรหัสผ่านผูกกับ IP ล้วน | ⚠️ | บนไอพีร่วม (ห้องสมุด/NAT) ผู้ใช้คนหนึ่งกินโควตาของทุกคน |

## 6. File Upload

> ทดสอบด้วย **ไฟล์จริง** ครบทุกเคสแล้ว — `php tests/test_upload_security.php` (14 เคส, อยู่ใน Suite 4 ของ `run_all_tests.php`)

| รายการ | สถานะ | หลักฐาน |
|--------|-------|---------|
| ตรวจ MIME จากเนื้อไฟล์ (ไม่เชื่อ `$_FILES['type']`) | ✅ 🧪 | `finfo_file()` — `admin/book_form.php:111` |
| ไฟล์ PHP เปลี่ยนนามสกุลเป็น `.jpg` | ✅ 🧪 | finfo เห็น `text/x-php` → ปฏิเสธ (UP-01) |
| นามสกุลซ้อน `.php.jpg` | ✅ 🧪 | ปฏิเสธ (UP-02) |
| HTML เปลี่ยนนามสกุลเป็น `.png` | ✅ 🧪 | finfo เห็น `text/html` → ปฏิเสธ (UP-03) |
| SVG (เสี่ยง XSS, ไม่อยู่ใน allowlist) | ✅ 🧪 | ปฏิเสธ (UP-04) |
| ไฟล์ว่าง 0 byte | ✅ 🧪 | finfo เห็น `application/x-empty` → ปฏิเสธ (UP-05) |
| จำกัดขนาด 2 MB | ✅ 🧪 | ปฏิเสธไฟล์ 2.1 MB (UP-06) |
| ตั้งชื่อไฟล์ใหม่จาก MIME (ไม่ใช้ชื่อเดิม) | ✅ 🧪 | `cover_<time>_<uniqid>.<ext>` (UP-08) |
| **Polyglot** (PNG จริง + PHP ต่อท้าย) | ⚠️ 🧪 | **ผ่าน filter** เพราะ finfo เห็น PNG header — กันด้วยด่านถัดไปแทน (UP-09) |
| ปิดการรัน PHP ในโฟลเดอร์อัปโหลด | ✅ 🧪 | polyglot เรียกผ่าน HTTP → ได้ไบต์ดิบตรงกับไฟล์บนดิสก์ทุกไบต์ ไม่ถูก execute (UP-10, UP-11) |
| `.php` / `.phtml` ที่หลุดเข้าไปในโฟลเดอร์ | ✅ 🧪 | `uploads/.htaccess` → **403** ทั้งคู่ (UP-12, UP-13) |
| `.php.png` (ลงท้าย `.png`) | ✅ 🧪 | เสิร์ฟเป็นข้อความดิบ ไม่ถูก execute (UP-14) |

## 7. การเข้าถึงไฟล์ (ทดสอบจริงบนเครื่องนี้ทุกบรรทัด 🧪)

| URL | ผลลัพธ์ |
|-----|---------|
| `/.env` | 403 |
| `/app/Services/BookService.php` | 403 |
| `/includes/config.php` | 403 |
| `/tests/check_db.php` | 403 |
| `/database/schema.sql` | 403 |
| `/README.md` | 403 |
| `/.installed` | 403 |
| `/logs/`, `/uploads/covers/` (directory listing) | 403 |
| `/database/add_is_visible.php` | 403 (แก้แล้ว: CLI guard + `database/.htaccess` — ดู F-02) |

> ⚠️ `uploads/.htaccess` ใช้ `php_flag` ซึ่งใช้ได้เฉพาะ **mod_php** — ถ้า deploy บน PHP-FPM/LiteSpeed จะเกิด Internal Server Error หรือถูกเมิน ต้องเปลี่ยนวิธีปิด PHP แทน

## 8. Data Integrity (ชั้น DB)

| กลไก | ที่ใช้ |
|------|--------|
| `CHECK (available >= 0)` + `CHECK (quantity >= available)` | กัน stock ติดลบ/เกิน |
| `UNIQUE(payments.borrow_id)` | กันชำระซ้ำ (ด่านสุดท้ายแม้ app ผิดพลาด) |
| `UNIQUE(users.email)`, `UNIQUE(books.isbn)`, `UNIQUE(categories.name)` | กันข้อมูลซ้ำ |
| FK `ON DELETE RESTRICT` (borrows/reservations) | กันลบหนังสือ/สมาชิกที่มีประวัติ |
| Transaction + `FOR UPDATE` | ทุก write flow สำคัญ (ดู PROJECT_MAP §4) |

**ทดสอบด้วยการยิงพร้อมกันจริงแล้ว** — `php tests/test_concurrency_http.php` (เจ้าหน้าที่ 2 คน คนละ session ยิงด้วย `curl_multi`)

| สถานการณ์ | ผล 🧪 |
|-----------|-------|
| อนุมัติการจองใบเดียวกันพร้อมกัน | ได้ borrow เดียว · stock ไม่ถูกหักซ้ำ ✅ |
| คืนหนังสือรายการเดียวกันพร้อมกัน | stock คืน +1 ครั้งเดียว ✅ |
| รับชำระค่าปรับรายการเดียวกันพร้อมกัน | payment แถวเดียว (UNIQUE + row lock) ✅ |
| ยืมเล่มสุดท้ายพร้อมกัน (คนละสมาชิก) | สำเร็จคนเดียว · `available` ไม่เคยติดลบ (12/12 รอบ) ✅ |
| ↳ ข้อความที่คนพลาดได้รับ | ⚠️ ~25% เจอ deadlock ดิบของ MySQL หลุดขึ้นจอ — ดู **F-20** |

> idempotency key เก็บใน session จึงกันข้ามเครื่องไม่ได้ — ที่กันไว้จริงคือ row lock + UNIQUE constraint ในชั้น DB ซึ่งทดสอบแล้วว่าทำงาน

## 9. Idempotency

| Flow | Key | ที่มา |
|------|-----|-------|
| ยืม | `borrow_{userId}_{md5(bookIds)}` (60 วิ) | `admin/borrow_form.php:107` |
| คืน | `return_{borrowId}` | `admin/borrows.php:46` |
| ชำระค่าปรับ | `pay_fine_{borrowId}` | `admin/payments.php:47` |
| จอง | `reserve_{userId}_{bookId}` (5 วิ) | `api/reserve_book.php:67` |
| ยกเลิกจอง | `cancel_reservation_{id}` | `api/cancel_reservation.php:39` |
| อนุมัติ/ยกเลิกจอง (admin) | `reservation_{action}_{id}` | `admin/reservations.php:44` |
| ลบหนังสือ / ลบสมาชิก | `delete_book_{id}` / `delete_member_{id}` | `admin/books.php:41`, `admin/members.php:38` |

⚠️ **ข้อจำกัด:** เก็บใน `$_SESSION` → กันได้แค่ double-submit ของ session เดียว อายุ 5 นาที (`cleanupIdempotencyKeys`) — request จากคนละ session/เครื่องยังต้องพึ่ง row lock + UNIQUE constraint (ซึ่งมีอยู่)

## 10. สิ่งที่ยังไม่มี (รู้ไว้ อย่าเคลม)

❌ Audit log ระดับระบบ · ❌ 2FA · ❌ Password history / expiry · ❌ Account lockout ถาวร · ❌ CSP / security headers (`X-Frame-Options`, `X-Content-Type-Options`) · ❌ HTTPS บังคับในโค้ด (ต้องตั้งที่ web server) · ❌ ตรวจ virus ไฟล์อัปโหลด

## 10.5 สิทธิ์โฟลเดอร์ที่เขียนได้ 🧪

| โฟลเดอร์ | ใครต้องเขียน | สิทธิ์ที่ตั้งไว้บนเครื่องนี้ |
|----------|--------------|---------------------------|
| `uploads/covers/` | Apache (อัปโหลด/ลบรูปปก) | `755` + ACL ให้ user `daemon` เฉพาะตัว |
| `logs/` | `cron/*.php` ผ่าน CLI เท่านั้น | `755` (owner เขียน) |
| `uploads/` | ไม่มีใครเขียนโดยตรง | `755` |

**ทำไมไม่ใช้ 777:** โฟลเดอร์ `uploads/covers/` ถูก web server เสิร์ฟออกไปตรง ๆ
777 = ทุก process/ทุก user บนเครื่องเขียนไฟล์ลงไปได้ ซึ่งรวมถึงโค้ดอื่นที่ถูกเจาะ
(`uploads/.htaccess` ปิดการรัน PHP ไว้แล้ว แต่ไม่ควรพึ่งด่านเดียว)

**ทดสอบแล้วว่ายังทำงานครบหลังลดสิทธิ์:** อัปโหลดรูปปกผ่านฟอร์มจริง · เสิร์ฟรูปผ่าน HTTP (200)
· แทนที่รูปแล้วลบรูปเก่า · ลบหนังสือแล้วลบไฟล์ปก · daemon ลบไฟล์ที่ user อื่นเป็นเจ้าของได้ (ACL `delete_child`)
· cron เขียน `logs/cron.log` ได้

⚠️ สิทธิ์ไฟล์ไม่ได้ถูกเก็บใน git — ติดตั้งเครื่องใหม่ต้องตั้งเองตาม `docs/INSTALL.md` ขั้นที่ 4

## 11. เช็คลิสต์ก่อนขึ้น Production

- [ ] `.env` → `APP_DEBUG=false`
- [ ] `.env` → `RATE_LIMIT_MAX_ATTEMPTS=5`, `RATE_LIMIT_WINDOW_MINUTES=15` (ตอนนี้ `.env.example` ตั้งค่านี้ให้แล้ว — ตรวจว่า `.env` ที่ใช้จริงไม่ได้ตั้งหลวมกว่านี้)
- [ ] ตั้ง `DB_PASS` จริง — ห้ามใช้ root ไม่มีรหัส
- [ ] เปลี่ยนรหัส admin เริ่มต้น
- [ ] ลบ `install.php`
- [ ] ตรวจว่า `database/` และ `tests/` เข้าถึงจากเว็บไม่ได้ (มี `.htaccess` ให้แล้ว — ถ้า host ปิด `AllowOverride` ต้องลบโฟลเดอร์ทิ้ง)
- [ ] ตรวจว่า `.htaccess` ทำงาน (host ต้องเปิด `AllowOverride`)
- [ ] ตรวจว่า `uploads/covers/` และ `logs/` **ไม่ใช่ 777** — ให้สิทธิ์เฉพาะ user ของ web server (ดู §10.5)
- [ ] ตั้ง cron 2 ตัว
- [ ] บังคับ HTTPS + เพิ่ม security headers ที่ web server
