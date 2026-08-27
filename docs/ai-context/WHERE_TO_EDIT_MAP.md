# WHERE TO EDIT MAP

> "อยากแก้ X → แตะไฟล์ไหน" — เสริมจาก `docs/WHERE_TO_EDIT.md` ด้วยเลขบรรทัดที่ตรวจแล้ว
> **หลักการ:** กติกา → Service · SQL → Repository · หน้าตา → Page · ค่าคงที่ → `.env`

## 1. เปลี่ยน "กติกา" (ไม่ต้องแตะโค้ด)

แก้ที่ `.env` แล้ว refresh — ไม่ต้อง restart อะไร

| อยากได้ | แก้ค่า |
|---------|--------|
| ยืมได้กี่วัน | `DEFAULT_BORROW_DAYS` |
| ยืมได้สูงสุดกี่เล่ม (นับรวมการจอง) | `MAX_BORROW_BOOKS` |
| ค่าปรับต่อวัน | `FINE_PER_DAY` |
| ความยาวรหัสผ่านขั้นต่ำ | `MIN_PASSWORD_LENGTH` |
| ความเข้มของ rate limit | `RATE_LIMIT_MAX_ATTEMPTS`, `RATE_LIMIT_WINDOW_MINUTES` |
| อายุ session | `SESSION_LIFETIME` |
| จำนวนรายการต่อหน้า | `ITEMS_PER_PAGE` (ตารางแอดมิน), `BOOKS_PER_PAGE` (grid หน้าแรก) |
| ชื่อระบบ / URL | `APP_NAME`, `APP_URL` |

## 2. เปลี่ยนกติกาที่ต้องแก้โค้ด

| อยากได้ | แก้ที่ | หมายเหตุ |
|---------|--------|----------|
| สูตรค่าปรับ (ขั้นบันได, มีเพดาน, เว้นวันหยุด) | `app/Services/BorrowService.php::calculateFine()` :272 | จุดเดียว ทั้งระบบเรียกที่นี่ |
| ช่วงวันยืมที่อนุญาต (ตอนนี้ 1–30) | `BorrowService.php:122` | |
| อายุการจอง (ตอนนี้ 2 วัน) | `ReservationService::createReservation()` param `$expireDays` :98 | ควรย้ายไป `.env` ถ้าลูกค้าอยากปรับเอง |
| ให้ admin ยืมหนังสือได้ | `UserRepository::findMemberById()` :204 (`role IN`) | ตรวจผลกระทบกับ `admin/borrow_form.php` |
| นับ/ไม่นับการจองในโควตา | `BorrowService.php:147-157` + `ReservationService.php:152` | ต้องแก้ **ทั้งคู่** ให้สอดคล้อง |
| เงื่อนไขห้ามลบหนังสือ | `BookService::deleteBook()` :249-261 | |
| เงื่อนไขห้ามลบสมาชิก | `MemberService::deleteMember()` :231-241 | |
| role ที่ตั้งได้จากหน้า admin | `MemberService.php:198` (whitelist) | |

## 3. เปลี่ยน "วิธีอ่าน/เขียนข้อมูล"

| อยากได้ | แก้ที่ |
|---------|--------|
| เพิ่ม filter/sort ของรายการหนังสือ | `BookRepository::findAll()` :159 (เพิ่มใน `$where` + whitelist `match()`) |
| เพิ่ม filter ของรายการยืม | `BorrowRepository::findAll()` :158 |
| เปลี่ยน query รายงาน | `app/Repositories/ReportRepository.php` (14 method) |
| เปลี่ยนตัวเลขบน Dashboard | `DashboardService::getCardStats()` :83 → repo ที่เกี่ยวข้อง |
| เพิ่มคอลัมน์ในตาราง | ดู DATABASE_MAP §6 (ต้องแก้ 6 จุด รวม `install.php`) |

## 4. เปลี่ยนหน้าตา

| อยากได้ | แก้ที่ |
|---------|--------|
| เมนู/หัวเว็บฝั่ง public | `includes/header.php`, `includes/footer.php` |
| เมนู/หัวเว็บฝั่ง admin | `admin/header.php` (มี `requireStaff()` อยู่ในนี้ — ห้ามลบ), `admin/footer.php` |
| การ์ดหนังสือบนหน้าแรก + ผลค้นหา | `includes/book_grid.php` (ใช้ร่วมกัน 2 ที่ — แก้ที่เดียวได้ทั้งคู่) |
| แถบเลือกหน้า | `includes/pagination.php` (ใช้ร่วมกัน 4 หน้า) · ตรรกะคำนวณอยู่ที่ `paginate()` ใน `includes/functions.php` |
| สูตรการค้นหา (trigram) | `buildSearchTokens()` / `buildSearchBooleanQuery()` ใน `includes/functions.php` · 🔴 แก้แล้วต้องรัน `php database/rebuild_search_index.php --all` |
| สี/ฟอนต์ | `css/style.css` + tailwind config inline ใน `includes/header.php` |
| ชื่อหน่วยงาน + สีบัตรสมาชิก | หน้า `admin/settings.php` (ไม่ต้องแก้โค้ด) |
| เลย์เอาต์บัตรสมาชิก / ฉลากหนังสือ | `admin/member_card.php`, `admin/book_labels.php` |

## 5. เพิ่มรายงานใหม่

1. เขียน query ใหม่ใน `app/Repositories/ReportRepository.php`
2. เพิ่ม `case` ใน `includes/report_helper.php::getReportConfig()` ← **จุดเดียว** ได้ทั้งหน้าเว็บ + CSV + PDF
3. เพิ่มตัวเลือกใน dropdown ที่ `admin/reports.php`

## 6. เพิ่มหน้าใหม่

```php
require_once __DIR__ . '/bootstrap.php';   // (หรือ '/../bootstrap.php' ถ้าอยู่ใน admin/)
requireStaff();                             // หรือ requireLogin() / requireAdmin()
$service = new \App\Services\XxxService(getDB());
// POST → ตรวจ validateCSRFToken() ก่อนเสมอ
$pageTitle = '...';
require_once __DIR__ . '/header.php';       // admin/header.php หรือ includes/header.php
```

## 7. เพิ่ม Service/Repository ใหม่

- วางไฟล์ที่ `app/Services/` หรือ `app/Repositories/` ตั้ง namespace `App\Services\` / `App\Repositories\` → autoloader หาเจอเอง
- Constructor รับ `PDO` เสมอ และส่ง PDO **ตัวเดียวกัน** ให้ทุก repo ที่ใช้ร่วมใน transaction (ถ้าคนละ instance → transaction จะไม่ครอบ)
- ถ้าเพิ่ม namespace ใหม่ (เช่น `App\Notifications\`) ต้องเพิ่มใน `$map` ที่ `bootstrap.php:73-78`

## 8. จุดที่ "ห้ามแตะ" ถ้าไม่เข้าใจผลกระทบ

| ไฟล์/บรรทัด | เหตุผล |
|-------------|--------|
| `includes/db.php:62` `EMULATE_PREPARES => false` | เปลี่ยนเป็น true = เปิดช่อง SQL injection + `LIMIT ?` พัง |
| ลำดับ `require` ใน `bootstrap.php:50-52` | config → db → functions สลับแล้วพังทั้งระบบ |
| `startSession()` ท้าย `functions.php` | ทุกหน้าพึ่ง session ที่ start อัตโนมัติ |
| `FOR UPDATE` ทุกจุด (ดู PROJECT_MAP §4) | ถอดแล้วเกิด race condition ยืมเล่มสุดท้ายซ้อน |
| `beginTransaction/commit/rollBack` ใน Service | ถอดแล้ว stock กับ borrow ไม่ตรงกัน |
| `UNIQUE(payments.borrow_id)` | ด่านสุดท้ายกันชำระซ้ำ |
| FK `ON DELETE RESTRICT` | ถอดแล้ว = ลบหนังสือ/สมาชิกทิ้งประวัติ orphan |
| `is_visible` vs `quantity` | คนละความหมาย — อย่าใช้แทนกัน |
| `.htaccess` ทุกไฟล์ | ถอดแล้ว `.env`/source/test หลุดสู่เว็บ |
