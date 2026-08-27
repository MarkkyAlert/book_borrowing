# FINDINGS — จุดที่ Context/Comment ไม่ตรงกับ Source Code

> ตามคำสั่ง Context ข้อ 6 และ 11: "ถ้าพบข้อสรุปใดไม่ตรงกับโค้ด ให้แจ้งก่อน"
> ระดับ: 🔴 ควรแก้ · 🟡 ควรพิจารณา · 🔵 แค่ให้รู้ว่า Context ไม่ตรง
>
> **สถานะ:** F-01…F-05, F-08 และ F-12…F-14 **แก้แล้ว** (ผ่านชุดทดสอบ 94/94)
> F-15…F-18 เจอจากการทดสอบทุก flow ผ่าน Chrome จริง — **แก้ครบแล้วทั้งหมด**
> F-19 เจอตอนไล่ทดสอบ 5 หน้าที่เหลือ — **ยังไม่แก้** (รูปแบบการแสดงผล ไม่กระทบการทำงาน)
> F-20 เจอตอนทดสอบเจ้าหน้าที่ 2 คนกดพร้อมกัน — **แก้แล้ว** (retry อัตโนมัติ + ข้อความไทย)
> F-21 เจอตอนวัด performance — **ตกลงว่าจะทำ แต่พักไว้ก่อน** (ไม่ใช่บั๊ก ระบบยังใช้งานได้ในขนาดปัจจุบัน)
> F-06, F-07, F-09, F-10, F-11 เป็นข้อมูลเชิงบริบท — ไม่ใช่บั๊ก ต้องตัดสินใจเชิงผลิตภัณฑ์ก่อนถึงจะแก้ได้
>
> F-12…F-14 เจอตอนตรวจว่าข้อมูลตัวอย่างครอบคลุมพอสำหรับทดสอบทุก flow หรือไม่ — ทั้งสามอยู่ใน `database/sample_data.sql`

---

## ✅ F-01 — [แก้แล้ว] `api/search_books.php` ไม่กรอง `is_visible` (หนังสือที่ซ่อนไว้โผล่ในผลค้นหาสาธารณะ)

**ปัญหา:** หนังสือที่ตั้ง "ซ่อนจากผู้ใช้ทั่วไป" ไม่แสดงตอนโหลดหน้าแรก แต่ **โผล่ทันทีเมื่อผู้ใช้พิมพ์ค้นหา** (หน้าแรกยิง AJAX ไป `api/search_books.php` แล้วแทนที่ทั้ง grid)

**Root cause:** `index.php` เรียกผ่าน `HomeService::getBooks()` ซึ่งใส่ `visible_only = true` (`app/Services/HomeService.php:94`) แต่ `api/search_books.php:58` เรียก `BookRepository::findAll($filters)` ตรง ๆ โดยไม่ใส่ `visible_only` — ฟีเจอร์ `is_visible` เพิ่งเพิ่มใน commit `1453c01` และตกหล่นเส้นทางนี้

**ยืนยันแล้ว (ทดสอบจริงบนเครื่องนี้):**
```
ตั้ง "Atomic Habits" is_visible = 0
GET /index.php                     → ไม่พบ (0 ครั้ง)   ✅ ถูก
GET /api/search_books.php?search=Atomic → พบ (2 ครั้ง)  ❌ รั่ว
```

**สิ่งที่แก้:** `api/search_books.php` เปลี่ยนจากเรียก `BookRepository::findAll()` ตรง ๆ มาเป็นเรียก `HomeService::getBooks()` ซึ่งเป็น**ที่เดียว**ที่นิยามว่า "ผู้ใช้ทั่วไปเห็นอะไรได้"

> เลือกวิธีนี้แทนการเติม `$filters['visible_only'] = true;` ในไฟล์ API เพราะกติกาใน Context ระบุว่าห้าม duplicate business logic ลง API — ถ้าเติม flag ซ้ำ กฎการมองเห็นจะกระจายอยู่ 2 ที่และมีโอกาสหลุดอีกในอนาคต ตอนนี้ `index.php` กับ AJAX search เดินผ่าน service ตัวเดียวกัน ผลลัพธ์จึงไม่มีทางต่างกัน

**Database:** ไม่เปลี่ยน · **ข้อมูลเก่า:** ไม่กระทบ · **Report/Export:** ไม่กระทบ (รายงานใช้ `ReportRepository` คนละเส้นทาง และต้องเห็นทุกเล่มอยู่แล้ว)
**ผลข้างเคียงที่ยอมรับ:** `HomeService::getBooks()` ดึง categories มาด้วย (query เพิ่ม 1 ครั้ง) และเรียก lazy-expire ของ reservation ต่อ 1 request ค้นหา — endpoint นี้จำกัด 60 ครั้ง/5 นาทีอยู่แล้ว และ lazy-expire ทำให้เลข stock ในผลค้นหาแม่นขึ้น
**ทดสอบแล้ว:** ซ่อน "Atomic Habits" → `index.php` = 0, `api/search_books.php?search=Atomic` = 0, `?status=available` = 0 · เปิดกลับ → เจอตามปกติ · regression: ค้นหาด้วยคำ/หมวดหมู่/สถานะ/ไม่พบผลลัพธ์ ครบทุกเคส
**ยังต้องระวัง:** endpoint นี้เป็น public — ถ้าอนาคตจะให้ staff ค้นหาหนังสือที่ซ่อนผ่านช่องเดียวกัน ต้องแยก service/flag ตามสิทธิ์ ห้ามถอด `visible_only` ออกจาก `HomeService`

---

## ✅ F-02 — [แก้แล้ว] `database/add_is_visible.php` เรียกผ่านเว็บได้โดยไม่ต้อง login

**ปัญหา:** `GET /database/add_is_visible.php` → **200** และรัน `ALTER TABLE books ADD COLUMN ...` ทันที (ไม่มี auth, ไม่มี CLI guard)

**เทียบกับ:** `cron/*.php` มี guard `if (php_sapi_name() !== 'cli' && !defined('CRON_ALLOWED')) exit('Access denied');` แต่ migration script ไม่มี และโฟลเดอร์ `database/` ไม่มี `.htaccess` ของตัวเอง (root `.htaccess` บล็อกแค่ `.sql` ไม่ครอบ `.php`)

**ผลกระทบจริงตอนนี้:** ต่ำ — สคริปต์ idempotent (มีคอลัมน์แล้วจะข้าม) และไม่รับ input แต่มันคือ **endpoint แก้ schema ที่เปิดสาธารณะ** ซึ่งเป็นแบบแผนที่อันตรายถ้ามี migration ตัวใหม่มาวางในโฟลเดอร์เดียวกัน

**สิ่งที่แก้ (ทำทั้ง 2 ชั้น — defense in depth):**
1. `database/add_is_visible.php` เพิ่ม CLI guard แบบเดียวกับ `cron/*.php` → เรียกผ่าน browser ได้ 403
2. เพิ่มไฟล์ `database/.htaccess` → `Require all denied` (กันทั้งโฟลเดอร์ เผื่อ migration ตัวใหม่ลืมใส่ guard)

**ทดสอบแล้ว:** `GET /database/add_is_visible.php` → **403** · `php database/add_is_visible.php` (CLI) → ยังทำงานปกติ ("Column 'is_visible' already exists. Skipping.")
**กติกาสำหรับ migration ไฟล์ถัดไป:** ต้องมี CLI guard ทุกไฟล์ อย่าพึ่ง `.htaccess` อย่างเดียว (บาง hosting ปิด `AllowOverride`)

---

## ✅ F-03 — [แก้แล้ว] `.env.example` ตั้ง Rate Limit หลวมกว่าค่า default และทำให้ QA suite fail

| ที่มา | MAX_ATTEMPTS | WINDOW_MINUTES |
|-------|--------------|----------------|
| `includes/config.php:81-82` (default ในโค้ด) | 5 | 15 |
| comment ใน `.env.example` | เขียนว่า "Default: 5" / "Default: 15" | |
| **ค่าที่ `.env.example` ตั้งจริง** | **10** | **10** |

ผลคือใครก็ตามที่ทำตามคู่มือ (`cp .env.example .env`) จะได้ระบบที่ยอมให้เดารหัสผ่านได้ 10 ครั้ง/10 นาที ทั้งที่โค้ดตั้งใจให้ 5 ครั้ง/15 นาที และ comment ก็บอกว่า 5

**ยืนยันแล้ว:** ทดสอบ login ผิดซ้ำ → ถูกบล็อกที่ครั้งที่ **11** (ไม่ใช่ 6) · `tests/qa_test_runner.php:496` ยิง 6 ครั้งแล้วคาดว่าต้องโดนบล็อก → **SC-07 fail** (ทำให้ผลรวมเป็น 93/94 แทนที่จะเป็น 94/94)

**สิ่งที่แก้ (ทำทั้งสองทาง):**
1. `.env.example` → `RATE_LIMIT_MAX_ATTEMPTS=5`, `RATE_LIMIT_WINDOW_MINUTES=15` ให้ตรงกับ default ในโค้ดและตรงกับ comment ของตัวเอง (+ เพิ่มคำเตือนว่าไม่ควรเกิน 10) · `.env` ของเครื่องนี้ปรับตามแล้ว
2. `tests/qa_test_runner.php` อ่าน `RATE_LIMIT_MAX_ATTEMPTS` จาก `includes/config.php` แทนการ hard-code 6 → ลูกค้าปรับค่าแล้วเทสต์ไม่พัง

**แถม:** SC-07 เดิมยิงรหัสผิดใส่ `admin@library.com` จริง ๆ ซึ่งพอ rate limit ทำงานได้แล้วจะ **ล็อกบัญชี admin ทิ้งไว้ 15 นาทีหลังรันเทสต์** — เปลี่ยนไปใช้อีเมลปลอม `qa_ratelimit_{timestamp}@test.com` แทน (rate limit key เป็น `login_md5(email)` จึงทดสอบกลไกเดียวกันได้โดยไม่แตะบัญชีจริง)

**ผลลัพธ์:** ชุดทดสอบ **94/94 ผ่าน (100%)** จากเดิม 93/94 · ยืนยันแล้วว่า login admin ยังใช้ได้ทันทีหลังรันเทสต์เสร็จ
**ผลกระทบต่อผู้ใช้:** ลูกค้าที่ใช้ `.env` เดิมอยู่แล้วไม่กระทบ (ไฟล์ `.env` ไม่ถูก commit) — มีผลเฉพาะการติดตั้งใหม่ที่ copy จาก `.env.example`

---

## ✅ F-04 — [แก้แล้ว] Test suite ทิ้งข้อมูลค้างในฐานข้อมูล

หลังรัน `php tests/run_all_tests.php` บน DB ที่เพิ่งติดตั้งใหม่ พบตกค้าง: QA user 2 คน, QA book 1 เล่ม, และ **reservation สถานะ pending 1 รายการที่กิน stock ของหนังสือ id=1 ค้างไว้** (available 3→2)

(ผมล้างข้อมูลชุดนี้ออกและคืน stock กลับเป็น 3 แล้ว — DB ตอนนี้เท่ากับสภาพหลังติดตั้งใหม่)

**สิ่งที่แก้:** เพิ่มขั้นตอน TEARDOWN ท้าย `tests/qa_test_runner.php` ที่:
1. **คืน stock ก่อน** จาก reservation ที่ยัง `pending` และ borrow ที่ยัง `borrowing` ของ QA user (ใช้ `WHERE available < quantity` แบบเดียวกับ `ReservationService` — ไม่ทำให้ available เกิน quantity)
2. ลบตามลำดับ FK: reservations → borrows (payments ตาม CASCADE) → users
3. ลบหนังสือ/หมวดหมู่ของรอบทดสอบ โดยอ้าง**ชื่อที่มี timestamp ของรอบนั้น** เท่านั้น — ไม่แตะข้อมูลจริง
4. ล้าง rate_limits ของอีเมลที่ใช้ทดสอบ

ครอบด้วย transaction + try/catch: teardown พังไม่ทำให้ผลเทสต์พัง แต่จะพิมพ์เตือนให้ล้างเอง

**ทดสอบแล้ว:** รันชุดเต็มบน DB ที่นับแถวไว้ก่อน → หลังรันจบ `users/books/borrows/reservations/categories` และ `available` ของหนังสือทั้ง 5 เล่ม **กลับมาเท่าเดิมทุกค่า** (teardown รายงาน: ลบ user 2 คน, หนังสือ 1 เล่ม, คืน stock 1 เล่ม)
**ยังคงเตือนไว้:** ถึงจะล้างให้แล้ว ก็ยัง **ไม่ควรรันชุดทดสอบบน production** เพราะระหว่างรันมีการเขียนข้อมูลจริงลง DB

---

## ✅ F-05 — [แก้แล้ว] `api/add_member.php` สร้างสมาชิกด้วยรหัสผ่านสุ่ม แต่ไม่คืนรหัสให้ใครเห็น

`MemberService::createMember()` จะสุ่มรหัส 8 ตัวเมื่อไม่ได้ส่ง `password` มา และ **คืนค่า plain password กลับมาครั้งเดียว** เพื่อให้แสดงแก่ admin (`app/Services/MemberService.php:137-142`)

แต่ `api/add_member.php` (ไม่ส่ง key `password`) ไม่ได้ส่ง `password` และ response ก็ **ไม่มี field password** → สมาชิกที่ถูกเพิ่มด้วยปุ่ม "เพิ่มสมาชิกด่วน" จะมีรหัสที่ไม่มีใครรู้ ต้องไปใช้ "ลืมรหัสผ่าน" (ซึ่งระบบไม่ส่งอีเมล) หรือให้ admin รีเซ็ตให้ที่ `admin/member_form.php`

**สิ่งที่แก้ — เลือกทาง (ก):** คืน `member.password` กลับมาใน response แล้วแสดงบนหน้าจอ

เลือกทางนี้เพราะปลอดภัยกว่าการตั้งรหัสตายตัวแบบ `123456` (ทางเลือก ข) — สมาชิกแต่ละคนได้รหัสสุ่มคนละตัว และ endpoint นี้ผ่าน `requireStaffApi()` อยู่แล้ว จึงเห็นได้เฉพาะเจ้าหน้าที่

- `api/add_member.php` → เพิ่ม `'password' => $result['password']` ใน response
- `admin/borrow_form.php` → toast ตอนเพิ่มสมาชิกสำเร็จ **ไม่ปิดเองอีกต่อไป** เมื่อมีรหัสผ่านให้จด แสดงรหัสตัวใหญ่แบบ monospace + ปุ่มปิด (ของเดิมปิดเองใน 3 วินาที ซึ่งสั้นเกินกว่าจะจดรหัสทัน) · ใส่ค่าด้วย `textContent` ไม่ใช่ `innerHTML` เพื่อกัน XSS จากชื่อสมาชิก

**ทดสอบแล้ว:** เรียก API ในฐานะ admin → ได้ `"password": "6374cmze"` กลับมา → นำรหัสนั้นไป login → **302 (เข้าได้จริง)** → ลบข้อมูลทดสอบออกแล้ว
**หมายเหตุ:** `admin/member_form.php` บังคับกรอกรหัสผ่านตอนสร้างอยู่แล้ว จึงไม่มีเส้นทางสุ่มรหัส — endpoint นี้เป็นที่เดียวที่ระบบสุ่มรหัสให้

---

## 🔵 F-06 — Context §4 บอกว่ามี "Business Setting" ในหน้า Settings — จริง ๆ ไม่มี

ตาราง `settings` ใช้จริงแค่ **3 key**: `org_name`, `card_color_primary`, `card_color_secondary` (`admin/settings.php`)

กฎธุรกิจทั้งหมด (จำนวนวันยืม, โควตา, ค่าปรับ, rate limit, session) เป็น **PHP constant ที่อ่านจาก `.env` ตอน boot** ไม่ได้อยู่ในตาราง settings → ลูกค้าเปลี่ยนเองผ่านหน้าเว็บไม่ได้ ต้องแก้ไฟล์ `.env`

ถ้าต้องการให้ปรับผ่านหน้าเว็บ: ต้องย้ายค่าเหล่านี้ไปตาราง `settings` แล้วแก้ทุกจุดที่อ้าง constant (`BorrowService`, `ReservationService`, `config.php`) — เป็นงานที่มีผลกระทบกว้าง ต้องตัดสินใจก่อน

---

## 🔵 F-07 — "Export PDF" ไม่ใช่ PDF (Context §4 สั่งให้ตรวจ — ตรวจแล้ว)

`admin/export_pdf.php` เป็นหน้า HTML print-friendly + ปุ่ม `window.print()` ไม่มี PDF library ใด ๆ ในโปรเจกต์
CSV เป็นไฟล์ CSV จริง (มี UTF-8 BOM ให้ Excel อ่านภาษาไทยได้ — `admin/reports.php:89`)

**เวลาพูดกับลูกค้า:** ใช้คำว่า "พิมพ์รายงาน / บันทึกเป็น PDF ผ่านกล่องพิมพ์ของเบราว์เซอร์" ไม่ใช่ "ระบบสร้างไฟล์ PDF"

---

## ✅ F-08 — [แก้แล้ว] Comment ใน `book.php` ไม่ตรงกับโค้ด (staff ดูหนังสือที่ซ่อนไม่ได้)

`book.php:41-42` เขียนคอมเมนต์ว่า *"Admin/Staff ยังเข้าดูหน้ารายละเอียดได้ (เพื่อตรวจสอบ)"*
แต่โค้ดบรรทัดถัดมาใช้ `!isAdmin()` ซึ่ง **ไม่รวม staff** → staff กดจากหน้า `admin/books.php` เข้าไปดูหนังสือที่ซ่อนไว้จะถูกเด้งกลับหน้าแรกพร้อมข้อความ "ไม่พบหนังสือ"

**สิ่งที่แก้:** เปลี่ยนเป็น `if (empty($book['is_visible']) && !isStaff())`

เลือกให้โค้ดตามคอมเมนต์ (ไม่ใช่แก้คอมเมนต์ตามโค้ด) เพราะ **ส่วนอื่นของระบบให้สิทธิ์ staff อยู่แล้ว** — `admin/books.php` แสดงหนังสือที่ซ่อนพร้อม badge "ซ่อน" ให้ staff เห็น และ staff กดแก้ไขผ่าน `admin/book_form.php` ได้ ของเดิมจึงขัดกันเอง: staff เห็นในลิสต์ แต่กดเข้าหน้ารายละเอียดแล้วโดนเด้งออกพร้อมข้อความ "ไม่พบหนังสือ"

**หมายเหตุ:** `isStaff()` ตรวจ `$_SESSION['role']` ในตัวอยู่แล้ว จึงไม่ต้องเรียก `isLoggedIn()` ซ้ำ · ผู้ใช้ทั่วไป/ไม่ล็อกอินยังเห็นไม่ได้เหมือนเดิม

---

## 🔵 F-09 — Context ไม่ได้ระบุว่าระบบพึ่ง CDN

หน้าเว็บโหลด Tailwind / Bootstrap / Chart.js / Select2 / flatpickr / **JsBarcode** / **QRCode.js** / Google Fonts จาก CDN ทั้งหมด
ถ้าห้องสมุดใช้งานในเครือข่ายปิด (ไม่มีอินเทอร์เน็ต) → **หน้าเว็บไม่มี style และพิมพ์บาร์โค้ด/QR ไม่ได้**
(ดูรายละเอียดใน KNOWN_LIMITATIONS §2 — ต้อง self-host ก่อนส่งมอบงานแบบออฟไลน์)

---

## 🔵 F-10 — Idempotency เป็นแบบ session (Context §10 สั่งให้ตรวจ — ตรวจแล้ว)

Key เก็บใน `$_SESSION['processed_actions']` อายุ 5 นาที (`includes/functions.php:718`) ครอบ 8 flow (ดู SECURITY_CHECKLIST §9)
กันได้เฉพาะ double-submit ใน session เดียวกัน — request ซ้ำจากคนละเครื่อง/คนละ session พึ่ง **row lock + UNIQUE constraint** ซึ่งมีจริงและทดสอบผ่าน (DB constraint test 11/11)

---

## 🔵 F-11 — Context §12 บอกว่ามีเอกสาร ~9 ชุด — จริง ๆ มี 11 ไฟล์

`docs/` มี: ARCHITECTURE, DEPLOYMENT, FAQ, **FAQ_FOR_SALER**, FLOW, INSTALL, LIMITATIONS, QA_CHECKLIST, STUDY_GUIDE, **SUPPORT**, WHERE_TO_EDIT
(commit `e5926f5` ลบเอกสารชุดเก่าออกหลายไฟล์ เช่น `API.md`, `DATABASE.md`, `CUSTOMIZATION.md` — ถ้ามีเอกสาร/ลิงก์ไหนยังอ้างถึงไฟล์เหล่านั้นอยู่ ถือว่าลิงก์เสีย)

---

## ✅ F-12 — [แก้แล้ว] รหัสผ่านของบัญชีตัวอย่างไม่ใช่ `123456` ตามที่เอกสารบอก

**ปัญหา:** เอกสาร 4 จุด (`docs/INSTALL.md:145`, `docs/DEPLOYMENT.md:136`, `docs/STUDY_GUIDE.md`, `docs/QA_CHECKLIST.md`) ระบุว่ารหัสผ่านของบัญชีตัวอย่างคือ `123456` แต่ bcrypt hash ที่ฝังอยู่ใน `sample_data.sql` ทั้ง 5 บัญชีคือ hash ของคำว่า **`password`**

ผลคือลูกค้าที่ import ข้อมูลตัวอย่างตามคู่มือแล้วลอง login ด้วย `123456` จะเข้าไม่ได้ทุกบัญชี ยกเว้น `admin@library.com` ที่บังเอิญเข้าได้เพราะไฟล์ไม่ทับรหัส admin (`DELETE FROM users WHERE id != 1` + `ON DUPLICATE KEY UPDATE` ที่ไม่แตะคอลัมน์ password) — ทำให้ปัญหาถูกมองข้าม เพราะคนทดสอบมักล็อกอินด้วย admin

**ยืนยันแล้ว:** `password_verify('123456', $hash)` → false · ลองรหัสยอดนิยม 10 ตัวเจอว่าตรงกับ `password` · login ผ่านหน้าเว็บจริงด้วย `123456`: `staff@library.com` และ `somchai@example.com` → 200 (ค้างหน้า login) ขณะที่ admin → 302

**สิ่งที่แก้:** เปลี่ยน hash ใน `sample_data.sql` ทั้ง 5 จุดเป็น hash จริงของ `123456`

เลือกแก้ข้อมูลให้ตรงเอกสาร ไม่ใช่แก้เอกสารตามข้อมูล เพราะ `123456` เป็น convention ของทั้งโปรเจกต์อยู่แล้ว — `MemberService::importMember()` ก็ใช้ `123456` เป็นรหัสเริ่มต้นของการ import สมาชิก การเปลี่ยนเอกสารเป็น `password` จะทำให้มี 2 มาตรฐานในระบบเดียว

**ทดสอบแล้ว:** login ผ่านหน้าเว็บจริงด้วย `123456` ครบทั้ง 5 บัญชี → **302 ทุกบัญชี** · ยืนยันว่ารหัส admin ที่ตั้งตอน install ยังไม่ถูกทับ (import ทับ DB ที่รหัส admin เป็นค่าอื่น แล้วรหัสเดิมยังใช้ได้)

---

## ✅ F-13 — [แก้แล้ว] `sample_data.sql` ฝังชื่อฐานข้อมูลไว้ในไฟล์

**ปัญหา:** ไฟล์มีบรรทัด `USE \`book_borrowing\`;` ซึ่ง override การเลือก database ของผู้ใช้

ลูกค้าที่ตั้ง `DB_NAME` ใน `.env` เป็นชื่ออื่น (เช่น shared hosting ที่บังคับ prefix อย่าง `user123_library`) แล้ว import ตามคู่มือ จะเจอ 1 ใน 2 กรณี: error ว่าไม่มี database `book_borrowing` หรือ — อันตรายกว่า — **ข้อมูลไปลง database ผิดตัวโดยไม่มีใครรู้**

**ยืนยันแล้ว:** สั่ง `mysql -u root bb_probe < database/sample_data.sql` → ข้อมูลไปลง `book_borrowing` แทน `bb_probe` (เจอตอนพยายาม import ลง DB ทดสอบแล้วข้อมูลไปโผล่ใน DB จริง)

**สิ่งที่แก้:**
- ลบบรรทัด `USE` ออก + เขียนหัวไฟล์อธิบายว่าต้องเลือก database เองก่อน (พร้อมคำสั่งทั้ง phpMyAdmin และ command line)
- `docs/INSTALL.md` เพิ่มวิธี import แบบ command line และเปลี่ยนข้อความจาก "เลือก database `book_borrowing`" เป็น "เลือก database ที่ติดตั้งไว้ (ถ้าตั้ง `DB_NAME` เป็นชื่ออื่นให้เลือกชื่อนั้น)"
- `database/schema.sql` **ไม่เปลี่ยนพฤติกรรม** (หน้าที่ของมันคือสร้าง database ให้) แต่เพิ่มคำเตือนที่หัวไฟล์ว่าชื่อ DB ถูก hard-code ไว้ 2 บรรทัด ต้องแก้เองถ้า `DB_NAME` ต่าง

**ทดสอบแล้ว:** สร้าง DB ชื่อ `lib_custom` → import schema + sample_data → ได้ 10 books / 13 borrows / 5 reservations ครบ และ `book_borrowing` ไม่ถูกแตะ · เส้นทางเดิม (ชื่อ default) ยังทำงานเหมือนเดิม

---

## ✅ F-14 — [แก้แล้ว] `sample_data.sql` ไม่หัก stock ของการจองที่ยัง pending

**ปัญหา:** ไฟล์ปรับ stock ด้วยการไล่ลบทีละ id แบบ hard-code 6 บรรทัด (`UPDATE books SET available = available - 1 WHERE id = N`) ซึ่งครอบเฉพาะ borrows ที่ยังไม่คืน 6 รายการ — **ลืมหักของ pending reservation 2 รายการ**

ขัดกับกฎ V-02 ของระบบเอง: การจองหัก stock ทันทีตั้งแต่กดจอง ไม่ใช่ตอนอนุมัติ

| id | หนังสือ | qty | available (เดิม) | ยืมค้าง | จอง | ที่ถูกต้อง |
|----|---------|-----|------------------|---------|-----|-----------|
| 1 | เกมล่าสังหาร | 3 | 2 | 1 | 1 | **1** |
| 4 | วัยรุ่นพันล้าน | 4 | 3 | 1 | 1 | **2** |

**ทำไมถึงสำคัญ:** ใครก็ตามที่ใช้ข้อมูลชุดนี้ทดสอบ flow เกี่ยวกับ stock จะได้ผลผิดตั้งแต่จุดเริ่มต้น และถ้ายกเลิกการจอง 2 รายการนั้น ระบบจะ `available + 1` ทับเข้าไปอีก ทำให้ตัวเลขเพี้ยนหนักกว่าเดิม

**สิ่งที่แก้:** แทนที่ UPDATE แบบ hard-code 6 บรรทัด ด้วย UPDATE เดียวที่คำนวณจากข้อมูลจริง และ **ย้ายไปไว้ท้ายไฟล์** (ของเดิมอยู่ก่อนบล็อก reservations จึงมองไม่เห็นการจองอยู่แล้ว):

```sql
UPDATE `books` b
SET b.`available` = b.`quantity`
    - (SELECT COUNT(*) FROM `borrows` br
        WHERE br.`book_id` = b.`id` AND br.`status` = 'borrowing')
    - (SELECT COUNT(*) FROM `reservations` r
        WHERE r.`book_id` = b.`id` AND r.`status` = 'pending');
```

ข้อดีคือ **แก้ไม่ได้อีก**: ใครเพิ่ม/แก้แถว borrows หรือ reservations ในไฟล์ stock จะถูกต้องเองเสมอ ไม่ต้องไล่แก้เลข id ให้ตรงกันด้วยมือ

**ทดสอบแล้ว:** import ใหม่ → invariant `available = quantity − ยืมค้าง − จอง pending` ถูกต้อง **10/10 เล่ม** (เดิมผิด 2 เล่ม) · ไม่มีเล่มไหนละเมิด CHECK constraint (`available < 0` หรือ `available > quantity`) · ทดสอบซ้ำบน DB ชื่ออื่นก็ได้ผลเดียวกัน

---

## ✅ F-15 — [แก้แล้ว] `export_pdf.php` แปลงเบอร์โทรเป็นตัวเลขมีคอมมา ทำให้เบอร์ผิด

**ปัญหา:** รายงาน PDF ที่มีคอลัมน์เบอร์โทรแสดงผลผิด — เบอร์ `0891234567` กลายเป็น **`891,234,567`** (เลข 0 นำหน้าหาย + มีคอมมาคั่น)

**Root cause:** `admin/export_pdf.php:210` ใช้เงื่อนไขกว้างเกินไป
```php
<?php elseif (is_numeric($value)): ?>
    <?= number_format($value) ?>
```
เบอร์โทรที่เก็บเป็น string `"0891234567"` ผ่าน `is_numeric()` → ถูก `number_format()` จัดรูปแบบเหมือนจำนวนเงิน

**ยืนยันแล้ว (ผ่านเบราว์เซอร์จริง):**

| หน้า | เบอร์ที่แสดง |
|------|--------------|
| `admin/reports.php` (บนจอ) | `0810000008` ✅ |
| `admin/export_pdf.php?report=overdue` | `810,000,008` ❌ |
| `admin/export_pdf.php?report=unpaid` | `891,234,567` ❌ |
| CSV export | ถูกต้อง (fputcsv เขียนค่าดิบ) ✅ |

**ทำไมสำคัญ:** รายงาน 2 ตัวที่โดนคือ "หนังสือค้างส่ง" และ "สมาชิกค้างชำระ" — เป็นรายงานที่บรรณารักษ์พิมพ์ออกมาเพื่อ**โทรตามคน** พอดี เบอร์ผิดทั้งใบ = ใช้งานไม่ได้จริง

**สิ่งที่แก้:** เลิกตัดสินจาก "ชนิดข้อมูล" เปลี่ยนเป็นตัดสินจาก **"ชื่อคอลัมน์"** โดยประกาศไว้ที่เดียวใน `includes/report_helper.php`:

```php
const REPORT_COUNT_COLUMNS = ['borrow_count','currently_borrowed','active_loans','transaction_count','days_overdue'];
const REPORT_MONEY_COLUMNS = ['total_amount','fine','fine_amount'];
function formatReportValue(string $key, mixed $value): string
```

`admin/export_pdf.php` เรียก `formatReportValue()` แทนบล็อก `is_numeric()` เดิม — คอลัมน์ที่ไม่อยู่ในลิสต์ถือเป็นข้อความล้วนเสมอ (เบอร์โทร/ISBN/ชื่อ/วันที่จึงไม่ถูกแปลง)

วางค่าคงที่ไว้ใน `report_helper.php` เพราะเป็น Single Source of Truth ของรายงานอยู่แล้ว — เพิ่มรายงานใหม่ที่มีคอลัมน์ตัวเลขก็เติมชื่อคอลัมน์ที่เดียว

**ทดสอบแล้ว (ผ่านเบราว์เซอร์จริง + curl ทุกรายงาน):**

| คอลัมน์ | ก่อนแก้ | หลังแก้ |
|---------|---------|---------|
| เบอร์โทร (ค้างส่ง/ค้างชำระ) | `810,000,008` | **`0810000008`** ✅ |
| เกินกำหนด (วัน) | `60` | `60` ✅ |
| ยอดเงิน `total_amount` | `130.00` | `130.00` ✅ |
| ค่าปรับ `fine_amount` | `300` | `300.00` ✅ (ตรงกับคอลัมน์เงินอื่น + ชิดขวา) |
| จำนวนการยืม | `9` | `9` ✅ |

**หมายเหตุ:** CSV ไม่เคยมีปัญหานี้ (fputcsv เขียนค่าดิบ) และหน้าจอ `admin/reports.php` ก็แสดงถูกอยู่แล้ว — แก้เฉพาะ `export_pdf.php` ที่เป็นต้นเหตุ

---

## ✅ F-16 — [แก้แล้ว] CSV export ไม่ป้องกัน Formula Injection

**ปัญหา:** ค่าที่ขึ้นต้นด้วย `=` `+` `-` `@` ถูกเขียนลง CSV ตรง ๆ — Excel/LibreOffice ตีความเป็นสูตรเมื่อเปิดไฟล์

**ยืนยันแล้ว:** ตั้งชื่อหนังสือเป็น `=cmd|' /C calc'!A0` แล้ว export → ได้บรรทัด
```
"=cmd|' /C calc'!A0","[TEST] หมวดที่มีหนังสือ",0,0
```
`fputcsv` ใส่ quote ให้เพราะมีช่องว่าง แต่ **quote ไม่ได้ป้องกัน formula injection** — Excel ยังตีความเป็นสูตรอยู่

**เส้นทางโจมตีจริง:** ผู้ใช้ที่เพิ่มหนังสือได้ (staff) หรือช่องทาง import CSV ใส่ชื่อหนังสือ/ผู้แต่งที่ขึ้นต้นด้วย `=` → admin กด Export CSV แล้วเปิดด้วย Excel → สูตรทำงานบนเครื่อง admin (Excel มี prompt เตือน แต่ผู้ใช้มักกดผ่าน)

**ความเสี่ยง:** ปานกลาง — ต้องมีสิทธิ์ staff อยู่แล้ว และต้องเปิดไฟล์ด้วย Excel

**สิ่งที่แก้:** เพิ่ม `csvSafeValue()` ใน `includes/report_helper.php` แล้วให้ `admin/reports.php` กรองทุกเซลล์ก่อนเขียน:

```php
fputcsv($output, array_map('csvSafeValue', $row));
```

กติกา: ถ้าอักขระตัวแรกเป็น `=` `+` `-` `@` `\t` `\r` → เติม `'` นำหน้า (Excel แสดงเป็นข้อความธรรมดาโดยไม่โชว์ `'`)

เลือกเติมทุกกรณีที่ขึ้นต้นด้วยอักขระอันตราย ไม่ยกเว้นค่าที่เป็นตัวเลข เพราะคอลัมน์ตัวเลขในรายงานชุดนี้ไม่มีค่าติดลบเลย (ค่าปรับ/จำนวนนับเป็นบวกเสมอ) จึงไม่มีผลข้างเคียง — และเบอร์โทรแบบสากล `+66...` ก็ควรเป็นข้อความอยู่แล้ว

**ทดสอบแล้ว:**

| ค่าในระบบ | ผลลัพธ์ใน CSV |
|-----------|---------------|
| `=cmd\|' /C calc'!A0` | `"'=cmd\|' /C calc'!A0"` ✅ ปลอดภัย |
| `+1234 บวกนำหน้า` | `"'+1234 บวกนำหน้า"` ✅ |
| ชื่อหนังสือปกติ | ไม่ถูกแตะ ✅ |
| ตัวเลข `9`, `300.00` | ไม่ถูกแตะ ✅ |
| เบอร์โทร `0891234567` | ไม่ถูกแตะ ✅ |

ตรวจครบทั้ง 6 รายงาน (books/members/revenue/overdue/borrows/unpaid) — BOM ยังอยู่ ภาษาไทยยังถูกต้อง

---

## ✅ F-17 — [แก้แล้ว] ปุ่มลบเปิดใช้งานได้ ทั้งที่ลบไม่มีวันสำเร็จ

หน้า `admin/books.php` และ `admin/members.php` disable ปุ่มลบเฉพาะกรณี "กำลังถูกยืมอยู่" เท่านั้น
แต่ Service ยังมี guard อีก 2 ข้อที่ UI ไม่ได้สะท้อน: **มีประวัติการยืม** และ **มี pending reservation**

**ยืนยันแล้ว:** กดลบ `[TEST] มีประวัติยืม (คืนหมดแล้ว)` (ปุ่มเปิดใช้งาน) → ยืนยัน → เจอ error `ไม่สามารถลบได้ หนังสือเล่มนี้มีประวัติการยืม`
เช่นเดียวกับสมาชิก: กดลบ `[TEST] สมาชิกประวัติยาว` → `ไม่สามารถลบได้ สมาชิกมีประวัติการยืม`

**ผลกระทบ:** ไม่ใช่ช่องโหว่ — server ป้องกันครบ แต่เป็น UX ที่ทำให้เจ้าหน้าที่กดแล้วเจอ error ทั้งที่ระบบรู้ล่วงหน้าอยู่แล้วว่าลบไม่ได้

**สิ่งที่แก้:** ย้ายกฎ "ลบได้ไหม" ไปไว้ที่ Service ให้อยู่ติดกับ guard ตัวจริง แล้วให้ View ถามเอา

- `BookService::getDeleteBlockReason(array $book): ?string` และ `MemberService::getDeleteBlockReason(array $member): ?string` คืนข้อความเหตุผล หรือ `null` ถ้าลบได้ — เงื่อนไขเรียงตรงกับ `deleteBook()` / `deleteMember()` ทุกข้อ
- `BookRepository::findAll()` และ `UserRepository::findMembers()` ดึง `total_borrows`, `active_borrows`, `pending_reservations` มาพร้อมกันด้วย subquery (ไม่เกิด N+1 ต่อแถว)
- `admin/books.php` / `admin/members.php` เรียก service แล้ว disable ปุ่มพร้อม tooltip ตามเหตุผลจริง

เลือกวางกฎไว้ที่ Service แทนการเขียน `if` ซ้ำใน View เพราะกติกาโปรเจกต์ระบุว่า business rule ต้องอยู่ชั้น Service — และถ้าวันหน้าเพิ่ม guard ใน `deleteBook()` จะได้แก้ที่เดียว (มีคอมเมนต์เตือนไว้ทั้งสองฝั่ง)

**ทดสอบแล้ว:** เทียบผลบนหน้าเว็บกับที่คำนวณจาก SQL ตรงกันทั้ง 19 เล่ม และครบทั้ง 3 เหตุผล

| สภาพ | tooltip ที่แสดง |
|------|-----------------|
| กำลังถูกยืมอยู่ | "ไม่สามารถลบได้ เนื่องจากกำลังถูกยืมอยู่" |
| คืนหมดแล้วแต่มีประวัติ | "ไม่สามารถลบได้ เนื่องจากมีประวัติการยืม" ← **เดิมปุ่มเปิดใช้งาน** |
| มีแต่การจองค้าง | "ไม่สามารถลบได้ เนื่องจากมีการจองที่รอดำเนินการ" ← **เดิมปุ่มเปิดใช้งาน** |
| ไม่มีอะไรผูก | ปุ่มลบใช้งานได้ |

ฝั่งสมาชิกก็เช่นกัน (กำลังยืม 9 คน / มีประวัติ 3 คน / มีการจอง 2 คน)

---

## ✅ F-18 — [แก้แล้ว] รูปแบบวันที่/role ในรายงานไม่ตรงกันระหว่างหน้าจอ CSV และ PDF

**ปัญหา:** flag `$forPdf` ถูกใช้คุมการจัดรูปแบบข้อมูล ทำให้ผลลัพธ์ต่างกันตามช่องทาง — และต่างกัน**คนละทิศ**ในแต่ละรายงาน

| รายงาน / คอลัมน์ | หน้าเว็บ (เดิม) | CSV (เดิม) | PDF (เดิม) |
|------------------|----------------|-----------|-----------|
| ค้างส่ง — วันที่ | `2026-06-21` | `2026-06-21` | `21/06/2026` |
| รายได้ — `payment_day` | `14/08/2026` | `2026-08-14` | `2026-08-14` |
| สมาชิก — `role` | `สมาชิก` | `member` | `สมาชิก` |

ซ้ำร้ายกฎการแปลงกระจายอยู่ 2 ที่: บางส่วนใน SQL (`DATE_FORMAT`) บางส่วนใน View (`formatDate()`, ternary แปลง role)

**สิ่งที่แก้:** ย้ายการจัดรูปแบบมาไว้ที่ SQL ที่เดียว และทำ**เสมอ** ไม่ผูกกับช่องทาง — ให้เหมือน `getBorrowsReport()` และ `getUnpaidFinesReport()` ที่ทำถูกอยู่แล้ว

- `getOverdueReport()` — จัดรูปแบบวันที่เสมอ, ตัดพารามิเตอร์ `$formatDate` ที่ไม่ต้องใช้แล้วออก
- `getDailyRevenueReport()` — `DATE_FORMAT` ให้ `payment_day`
- `getTopMembersReport()` — แปล role เป็นไทยเสมอ, ตัดพารามิเตอร์ `$translateRole` ออก
- `admin/reports.php` — ลบ special case ของ `role` และ `payment_day` ทิ้ง (ไม่งั้นจะแปลงซ้ำ)

`$forPdf` ยังอยู่ใน `getReportConfig()` แต่เหลือหน้าที่เดียวคือย่อหัวคอลัมน์ให้พอดีหน้ากระดาษ

**ทดสอบแล้ว — ทั้ง 3 ช่องทางตรงกันหมด:**

| รายงาน | หน้าเว็บ | CSV | PDF |
|--------|---------|-----|-----|
| ค้างส่ง | `21/06/2026` | `21/06/2026` | `21/06/2026` |
| รายได้ | `14/08/2026` | `14/08/2026` | `14/08/2026` |
| สมาชิก | `สมาชิก` | `สมาชิก` | `สมาชิก` |

---

## 🔵 F-19 — [ยังไม่แก้] Modal ประวัติการยืมแสดงวันที่เป็น ISO ไม่ใช่ d/m/Y

**ปัญหา:** ปุ่ม "ประวัติการยืม" ในหน้า `admin/members.php` เปิด modal ที่แสดงวันที่เป็น `2026-07-28`
ขณะที่ทั้งระบบใช้ `28/07/2026` เป็นมาตรฐาน (ดู `formatDate()` ใน `includes/functions.php`)

เป็นอาการเดียวกับ F-18 แต่คนละชั้น — F-18 เป็นฝั่ง PHP (แก้ที่ SQL ไปแล้ว) ส่วนอันนี้ modal
ถูก render ด้วย JavaScript ฝั่ง client จึงไม่ได้อยู่ในขอบเขตที่แก้

**Root cause:**
- `BorrowRepository::findByUserId()` ใช้ `SELECT b.*` → คอลัมน์ `borrow_date` / `due_date` / `return_date` เป็น DATE ดิบ
- `api/member_history.php` คืนแถวนั้นเป็น JSON ตรง ๆ → `"borrow_date": "2026-07-28"`
- `admin/members.php:321-323` เอาค่ามาแสดงตรง ๆ ผ่าน `escapeHtml(item.borrow_date)` ไม่ได้จัดรูปแบบ

**ผลกระทบ:** แค่รูปแบบไม่สม่ำเสมอ ข้อมูลถูกต้อง ไม่กระทบการทำงานหรือความปลอดภัย
(ค่าถูก escape ด้วย `escapeHtml()` อยู่แล้ว)

**⚠️ กับดักสำหรับคนที่จะแก้:** ห้ามไปแปลงวันที่ใน `api/member_history.php` หรือใน Repository
เพราะบรรทัด `admin/members.php:312` ใช้ค่านี้คำนวณ badge "เกินกำหนด":

```js
} else if (item.due_date && new Date(item.due_date) < new Date(new Date().toDateString())) {
```

`new Date("2026-07-28")` แปลงได้ แต่ `new Date("28/07/2026")` → **Invalid Date** → เงื่อนไขเป็น false เสมอ
→ รายการที่เกินกำหนดจะแสดงเป็น "กำลังยืม" เงียบ ๆ

**แนวทางแก้ที่ถูก:** ให้ API คืน ISO เหมือนเดิม แล้วจัดรูปแบบตอน render ในฝั่ง JS เท่านั้น
เช่นเพิ่ม helper เล็ก ๆ ใน `admin/members.php` ที่แปลง `YYYY-MM-DD` → `DD/MM/YYYY` แล้วใช้กับ 3 คอลัมน์นั้น
โดยยังส่งค่าดิบให้บรรทัดที่ 312 ใช้คำนวณต่อ

---

## ✅ F-20 — [แก้แล้ว] Deadlock ของ MySQL หลุดขึ้นหน้าจอเป็น error ดิบ ตอนแย่งหนังสือเล่มสุดท้าย

**ปัญหา:** เมื่อเจ้าหน้าที่ 2 คนกดยืม**หนังสือเล่มเดียวกันที่เหลือเล่มสุดท้าย**พร้อมกัน (คนละสมาชิก)
บางครั้งคนที่พลาดไม่ได้เห็นข้อความ "ไม่มีเล่มว่าง" แต่เห็นแบบนี้แทน:

```
SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction
```

**วัดความถี่แล้ว:** ยิงคู่แข่งกัน 12 รอบ → **เกิด 3 รอบ (25%)**

**ข่าวดี — ข้อมูลไม่เสียหายเลย:**

| ตรวจ | ผล 12/12 รอบ |
|------|--------------|
| จำนวน borrow ที่สร้าง | 1 รายการเท่านั้น ไม่เคยซ้ำซ้อน |
| `available` หลังจบ | 0 ทุกครั้ง ไม่เคยติดลบ |
| invariant ของ stock | ถูกต้องทุกรอบ |

Transaction ถูก rollback อย่างถูกต้อง — นี่คือ InnoDB ทำงานตามปกติ ไม่ใช่ข้อมูลพัง

**ปัญหาที่เหลือมี 3 ชั้น:**

1. **UX** — บรรณารักษ์เห็นข้อความภาษาอังกฤษของ database engine แทนที่จะเป็น "หนังสือหมด" แล้วไม่รู้ว่าต้องทำอะไรต่อ
2. **ข้อความภายในหลุดออกหน้าเว็บ** — ทั้งที่ `APP_DEBUG=false` แล้ว เพราะ `admin/borrow_form.php:129` รับ `$e->getMessage()` ของ `PDOException` มาแสดงตรง ๆ (ตัวจับ `catch (Exception $e)` ครอบ PDOException ด้วย)
3. **ไม่มี retry** — deadlock เป็นผลลัพธ์ปกติที่ MySQL คาดหวังให้ "ลองใหม่" แต่ระบบปล่อยให้ล้มเลย เจ้าหน้าที่ต้องกดเองใหม่

**Root cause (คร่าว ๆ):** `BorrowService::createBorrow()` ล็อกตามลำดับ user → borrows → book
เมื่อ 2 transaction เข้ามาด้วย user คนละคนแต่ book เดียวกัน ลำดับการได้ล็อกอาจสลับกันจน InnoDB ตัดสินให้ตัวหนึ่งเป็นเหยื่อ

**ทางเลือกในการแก้ (ต้องตัดสินใจก่อนลงมือ — แตะ flow การยืมซึ่งเป็นหัวใจของระบบ):**

| ทางเลือก | ได้อะไร | เสียอะไร |
|----------|---------|----------|
| ก. แปลง `PDOException` เป็นข้อความไทยกลาง ๆ ("ระบบไม่ว่าง กรุณาลองใหม่") | เล็ก ปลอดภัย ไม่แตะ transaction | ผู้ใช้ยังต้องกดเอง |
| ข. retry อัตโนมัติเมื่อเจอ error 1213/40001 (2-3 ครั้ง) | ผู้ใช้แทบไม่รู้สึก | ต้องระวังไม่ให้ retry ตอนที่บันทึกไปแล้วบางส่วน — ต้องแน่ใจว่า rollback สะอาดจริง |
| ค. ทำทั้ง ก + ข | ครบที่สุด | งานมากสุด |

---

### สิ่งที่แก้ — เลือกทาง ค (ทั้ง ก + ข)

**1. helper กลาง** `runWithDeadlockRetry()` + `isDeadlockException()` ใน `includes/functions.php`

- retry เฉพาะ error **1213 (deadlock)** และ **1205 (lock wait timeout)** ซึ่ง MySQL rollback ให้แล้วและคาดหวังให้ลองใหม่
- **ห้าม retry** `PDOException` อื่น (UNIQUE/FK) เพราะลองใหม่ก็ผลเดิม → แปลงเป็นข้อความไทยกลาง ๆ แล้ว log ของจริงไว้
- **ห้าม retry** `Exception` ทางธุรกิจ ("หนังสือหมด" / "เกินโควตา") — ต้องเด้งออกทันทีพร้อมข้อความเดิม
- หน่วงแบบเพิ่มขึ้น + สุ่ม (20ms → 40ms + jitter) กันชนซ้ำจังหวะเดิม
- เคลียร์ transaction ที่ค้างก่อนลองใหม่ (`inTransaction()` guard) กัน `beginTransaction` ซ้อน

**2. ครอบ 6 เมธอดที่มีคนใช้พร้อมกันจริง** — `BorrowService::createBorrow / returnBook / payFine`
และ `ReservationService::createReservation / cancelReservation / fulfillReservation`

**ตรวจก่อนครอบว่า retry ปลอดภัย:** ทั้ง 6 เมธอดมี `beginTransaction…commit…rollBack` ครบในตัว
และ**ไม่มี side effect นอก transaction** (ไม่เขียนไฟล์ ไม่ส่งเมล ไม่แตะ session) — การเรียกซ้ำจึงไม่ทำอะไรซ้ำ
ส่วน `markExpiredReservations()` ที่อยู่ก่อน transaction ของ `createReservation` มี flag `$expiredMarked`
กันไว้อยู่แล้ว รอบสองจึงเป็น no-op

> `BookService::deleteBook()` ยัง**ไม่ได้**ครอบ เพราะลบไฟล์ปกหลัง commit — เป็น side effect นอก transaction
> ถ้าจะครอบต้องย้ายลำดับก่อน (แต่เป็น flow ของ admin คนเดียว โอกาสชนต่ำ)

**ผลลัพธ์ที่วัดได้ (ยิงแข่งกัน 20 รอบ):**

| | ก่อนแก้ | หลังแก้ |
|---|---|---|
| SQLSTATE ดิบหลุดขึ้นจอ | ~25% (3/12) | **0/20** |
| stock ถูกต้อง | 12/12 | **20/20** |
| ยืมซ้ำซ้อน | 0 | **0** |
| ข้อความที่คนพลาดได้รับ | บางครั้งเป็น SQLSTATE | "ไม่มีเล่มว่าง" ตามเดิม |

รัน `tests/test_concurrency_http.php` ซ้ำ 5 รอบ → 12/12 ทุกรอบ (ก่อนแก้ล้มราว 1 ใน 4)

**ทดสอบตรรกะ helper แยกต่างหาก:** `tests/test_deadlock_retry.php` (6 เคส, Suite 2b ของ `run_all_tests.php`)
จำลอง PDOException แต่ละชนิดเพื่อคุมทุกสาขา — รวมกรณี "ลองครบแล้วยังไม่สำเร็จ" ที่ race จริงบังคับให้เกิดไม่ได้

---

## 📌 F-21 — [รอทำ · ตัดสินใจแล้วว่าจะทำ] เพิ่ม pagination ให้หน้าที่โหลดทั้งชุด

**สถานะ:** เจ้าของโปรเจกต์รับทราบและเห็นด้วยว่าควรทำ แต่**พักไว้ก่อน** ยังไม่ถึงคิว
บันทึกไว้ที่นี่เพื่อไม่ให้หลุด ไม่ใช่บั๊กที่ต้องรีบ — ที่ขนาดข้อมูลปัจจุบัน (29 เล่ม) ไม่มีผลใด ๆ

**ทำไมต้องทำ:** วัดจริงแล้วพบว่าคอขวดของระบบไม่ใช่ฐานข้อมูล แต่คือขนาดหน้าเว็บ
เพราะหน้าเหล่านี้ render ทุกแถวที่ query เจอ (ดูตัวเลขเต็มใน KNOWN_LIMITATIONS §1.1)

| หน้า | 529 เล่ม | 2,029 เล่ม |
|------|----------|-----------|
| `index.php` | 1.4 MB | 5.5 MB (Chrome ใช้ 3.1 วินาที · 32,542 DOM node) |
| `admin/books.php` | 1.6 MB | 6.2 MB |
| `admin/borrows.php` | 2.7 MB | 7.8 MB |
| `admin/members.php` | 0.9 MB | 2.5 MB |

**เกณฑ์ว่าเมื่อไหร่ต้องทำ:** เกิน ~1,000 เล่ม หรือลูกค้าเข้าใช้ผ่านอินเทอร์เน็ต/มือถือ
(5.5 MB บนเน็ต 10 Mbps = รอโหลดอีก ~4 วินาทีก่อนเริ่ม render)

**ทำอย่างไร — มีตัวอย่างในโปรเจกต์อยู่แล้ว:**

1. `BorrowRepository::findByUserIdPaginated()` คือแบบที่ทำถูกแล้ว — ลอกรูปแบบนี้ได้เลย
2. เพิ่ม `LIMIT/OFFSET` + query นับจำนวนรวม ใน:
   - `BookRepository::findAll()` → ใช้โดย `index.php`, `api/search_books.php`, `admin/books.php`
   - `BorrowRepository::findAll()` → `admin/borrows.php`
   - `UserRepository::findMembers()` → `admin/members.php`
3. เพิ่ม UI เลือกหน้าใน View ทั้ง 4 หน้า
4. `admin/reports.php` **ไม่ต้องแก้** — query มี `LIMIT 50` อยู่แล้ว ขนาดหน้าคงที่ 121 KB ตลอด

**ขอบเขต:** แก้ที่ Repository + View เท่านั้น **ไม่ต้องแตะ Service** เพราะไม่ใช่การเปลี่ยนกฎธุรกิจ
⚠️ ระวัง: `HomeService::getBooks()` ต้องส่ง filter การแบ่งหน้าผ่านไปด้วย และอย่าให้หลุด `visible_only` (ดู F-01)

**วัดซ้ำหลังทำเสร็จ:** `php tests/fixtures/seed_bulk_data.php --books=2000 --members=600` แล้วเทียบกับตัวเลขใน KNOWN_LIMITATIONS §1.1

---

## ✅ สิ่งที่ Context บอกไว้ และ **ตรงกับโค้ดจริง** (ไม่ต้องกังวล)

- Layered `Page/API → Service → Repository → DB` — ตรง ไม่มี SQL หลุดไปอยู่ใน View
- Transaction + `SELECT ... FOR UPDATE` ในทุก flow สำคัญ — **มีจริงทั้งหมด** ไม่ใช่แค่เคลม
- PDO prepared statement + ปิด emulate prepares — ตรง
- CSRF / XSS escape / rate limit / session hardening / upload validation — มีจริง (ทดสอบสดแล้ว)
- FK RESTRICT + guard กันลบหนังสือ/สมาชิกที่มีประวัติ — ตรงกับ Context §8
- ใช้ `is_visible` แยกจาก `quantity` แทนการเอา `quantity=0` มาแปลว่า "ซ่อน" — **ทำถูกตามที่ Context §8 แนะนำแล้ว**
- 3 role (admin/staff/member) แยกด้าน "สิทธิ์" แต่ยังไม่มี borrow policy ต่อกลุ่มสมาชิก — ตรงกับหมายเหตุใน Context §3
- Barcode พึ่งพฤติกรรม HID/keyboard — ตรงกับ Context §5
- Cron ต้องตั้งเองบน server — ตรงกับ Context §11 (แต่มี lazy-expire สำรองให้ ระบบจึงไม่พังถ้าลืมตั้ง)
