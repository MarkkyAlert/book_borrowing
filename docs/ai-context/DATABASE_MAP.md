# DATABASE MAP

ที่มา: `database/schema.sql` + `install.php` (สร้างตารางเหมือนกัน) + ตรวจกับ DB จริงด้วย `SHOW CREATE TABLE`
Engine: InnoDB / `utf8mb4_unicode_ci` ทุกตาราง · ตรวจบน MariaDB 10.4.28

**ไฟล์ SQL 2 ตัวทำงานต่างกัน:**

| ไฟล์ | ระบุชื่อ DB เองไหม | วิธีรัน |
|------|--------------------|---------|
| `schema.sql` | ✅ สร้าง + `USE` ชื่อ `book_borrowing` ให้เลย | `mysql -u root -p < database/schema.sql` (ถ้า `DB_NAME` ต่าง ต้องแก้ชื่อใน 2 บรรทัดแรกก่อน) |
| `sample_data.sql` | ❌ ไม่ระบุ — ตั้งใจให้ใช้ได้ทุก `DB_NAME` | `mysql -u root -p ชื่อฐานข้อมูล < database/sample_data.sql` หรือเลือก DB ใน phpMyAdmin ก่อน Import |

`sample_data.sql` จะ **ล้างข้อมูลเดิมทั้งหมดก่อนเสมอ** (`DELETE FROM` ทุกตารางหลัก) ยกเว้น `users.id = 1` เพื่อรักษารหัส admin ที่ตั้งไว้ตอน install
และคำนวณ `available` ใหม่ท้ายไฟล์จาก borrows + reservations จริง — ไม่ hard-code ตัวเลข

## 1. ตารางทั้งหมด (10)

| ตาราง | หน้าที่ | จำนวนแถวตอนติดตั้งใหม่ |
|-------|--------|------------------------|
| `users` | สมาชิก + เจ้าหน้าที่ + admin (ตารางเดียว แยกด้วย `role`) | 1 (admin) |
| `categories` | หมวดหมู่หนังสือ | 5 |
| `books` | หนังสือ + stock + การมองเห็น | 5 |
| `borrows` | รายการยืม-คืน + ค่าปรับ | 0 |
| `reservations` | การจอง | 0 |
| `payments` | การรับชำระค่าปรับ | 0 |
| `password_resets` | token รีเซ็ตรหัสผ่าน | 0 |
| `settings` | key-value ตั้งค่า | 0 (สร้าง lazily) |
| `rate_limits` | นับ attempt กัน brute force | 0 |
| `closed_days` | วันที่ห้องสมุดปิด — หักออกจากการคิดค่าปรับ | 0 |

## 2. ความสัมพันธ์

```
categories 1 ──< books           (FK category_id, ON DELETE SET NULL)
users      1 ──< borrows         (FK user_id,  ON DELETE RESTRICT)
books      1 ──< borrows         (FK book_id,  ON DELETE RESTRICT)
users      1 ──< reservations    (FK user_id,  ON DELETE RESTRICT)
books      1 ──< reservations    (FK book_id,  ON DELETE RESTRICT)
borrows    1 ──0..1 reservations (FK borrow_id, ON DELETE SET NULL) ← link ตอน fulfill
borrows    1 ──0..1 payments     (FK borrow_id, ON DELETE CASCADE) + UNIQUE(borrow_id)
users      1 ──< payments        (FK recorded_by, ON DELETE SET NULL)
password_resets / rate_limits / settings  → ไม่มี FK (standalone)
```

> `RESTRICT` คือเหตุผลที่ "ลบหนังสือ/สมาชิกที่มีประวัติไม่ได้" ในระดับ DB — **ห้ามถอด FK เพื่อให้ DELETE ผ่าน** (ตรงกับ Context §8)

## 3. โมเดล Stock — จุดที่พลาดบ่อยที่สุด

`books` มี 3 คอลัมน์ที่ความหมายต่างกัน **ห้ามใช้แทนกัน**:

| คอลัมน์ | ความหมาย | ใครแก้ |
|---------|----------|--------|
| `quantity` | มีทั้งหมดกี่เล่มบนชั้น | admin แก้ในฟอร์ม (`BookService::updateBook`) |
| `available` | เหลือให้ยืม/จองได้กี่เล่ม | ระบบแก้อัตโนมัติเท่านั้น (ยืม/จอง/คืน/ยกเลิก/หมดอายุ) |
| `is_visible` | ให้ผู้ใช้ทั่วไปเห็นหรือไม่ | admin toggle (คนละเรื่องกับ stock) |

**สมการที่ระบบรักษาไว้:** `available = quantity − (จำนวนที่ยืมค้าง + จำนวน pending reservation)`

บังคับด้วย CHECK constraint จริงในฐานข้อมูล (`schema.sql:67-68`):
```sql
CONSTRAINT chk_books_available_non_negative CHECK (available >= 0)
CONSTRAINT chk_books_quantity_gte_available CHECK (quantity >= available)
```
> MariaDB 10.2.1+ / MySQL 8.0.16+ บังคับใช้จริง — เวอร์ชันเก่ากว่านั้นจะ parse แล้วเมิน

**การจองหัก stock ทันที** (ไม่ใช่ตอนอนุมัติ) → ยกเลิก/หมดอายุ **ต้องคืน stock เสมอ** (`ReservationService.php:161`, `:214`, `ReservationRepository.php:130`)

## 4. รายละเอียดคอลัมน์ที่มีผลต่อ logic

### `users`
`role ENUM('member','admin','staff') DEFAULT 'member'` · `email UNIQUE` · `password` = bcrypt hash (`password_hash()`)
> ไม่มีคอลัมน์ `is_active` / `member_group` / `expiry_date` — ระบบยังไม่มีแนวคิด "ระงับสมาชิก" หรือ "กลุ่มสมาชิกที่มีโควตาต่างกัน"

### `books`
`isbn VARCHAR(20) UNIQUE` (NULL ซ้ำได้หลายแถว ตามพฤติกรรม MySQL) · `cover_image` เก็บแค่ชื่อไฟล์ ไฟล์จริงอยู่ `uploads/covers/`

### `borrows`
`status ENUM('borrowing','returned')` · `fine_amount DECIMAL(10,2) DEFAULT 0`
> **`fine_amount` ถูกเขียนตอน "คืน" เท่านั้น** (`BorrowRepository::markAsReturned()`) — รายการที่เกินกำหนดแต่ยังไม่คืนจะมี `fine_amount = 0` และ **ไม่ถูกนับ** ในยอดค้างชำระ

### `reservations`
`status ENUM('pending','fulfilled','expired','cancelled')` · `expires_at DATETIME NOT NULL` · `borrow_id` ผูกกลับตอน fulfilled
> **ไม่มี UNIQUE constraint** กัน pending ซ้ำ (user+book) — กันด้วย application logic + row lock เท่านั้น (`ReservationService.php:130`)

### `payments`
`UNIQUE INDEX unique_borrow_payment (borrow_id)` = ด่านสุดท้ายกันชำระซ้ำระดับ DB · 1 borrow ชำระได้ครั้งเดียว จ่ายบางส่วนไม่ได้

### `settings`
key-value ธรรมดา · ใช้จริง 2 กลุ่ม:
- **กฎการยืม-คืน** — key ขึ้นต้นด้วย `rule_` (9 ข้อ: `rule_borrow_days`, `rule_max_books`,
  `rule_max_books_staff`, `rule_fine_per_day`, `rule_max_renew`, `rule_lost_book_fee`,
  `rule_waive_staff_limit`, `rule_due_soon_days`, `rule_reservation_days`)
- **บัตรสมาชิก** — `org_name`, `card_color_primary`, `card_color_secondary`

> 🔴 **กฎการยืมย้ายมาอยู่ที่นี่แล้ว** (ROADMAP ข้อ 0) — ค่าที่ใช้จริงอ่านเรียง
> **ตาราง settings → `.env` → ค่าเริ่มต้นในทะเบียน** ดู `resolveRuleValue()` ใน `includes/rules.php`
> พอผู้ดูแลกดบันทึกในหน้าตั้งค่าครั้งแรก ค่าใน `.env` จะไม่ถูกใช้อีกเลย
> ⚠️ แถวถูกสร้างแบบ lazy — ไม่มีแถว = ยังไม่เคยตั้งในหน้าเว็บ ไม่ได้แปลว่ากฎไม่มีผล

### `rate_limits`
`key_name` = `action_IP` หรือ `action_userId` · 1 แถว = 1 attempt · ล้างอัตโนมัติแบบ probabilistic ~1% ของ request (`bootstrap.php:59`)

### `closed_days`
ช่วงวันที่ห้องสมุดไม่เปิดทำการ (`start_date` … `end_date` แบบรวมปลายทั้งสองข้าง) + `note`
> 🔴 ใช้ตอนคิดค่าปรับ — `ClosedDayRepository::countClosedDaysBetween()` หักวันปิดออกจากวันที่เกินกำหนด
> **ช่วงที่นับคือ (วันครบกำหนด, วันคืนจริง]** ต้องตรงกับสูตรค่าปรับเป๊ะ ไม่งั้นหักเกิน/ขาดไป 1 วัน
> ⚠️ ช่วงวันปิดซ้อนทับกันได้ จึงกางเป็น "เซ็ตของวัน" ก่อนนับ ห้ามบวกความยาวช่วงตรง ๆ
> 📌 แก้วันปิดแล้วมีผลกับรายการที่ยืมไปก่อนหน้าด้วย (คิดค่าปรับตอนคืน ไม่ได้เก็บค่าไว้ล่วงหน้า)

## 5. Index ที่มีอยู่

`books`: idx_available, idx_category, uq_isbn, idx_call_number · `borrows`: idx_status, idx_user, idx_book, idx_due_date
`reservations`: idx_status, idx_user, idx_book · `users`: idx_email, idx_role · `password_resets`: idx_email, idx_token, idx_expires · `rate_limits`: idx_key_name, idx_created_at

> 🔎 **FULLTEXT:** `books.search_tokens` + index `ft_books_search` (F-24)
> เก็บ trigram ของ `title + author + isbn` เพราะ FULLTEXT ปกติตัดคำด้วยช่องว่าง
> ซึ่งใช้กับภาษาไทยไม่ได้ · การค้นหายังคง `LIKE` ไว้กรองซ้ำเพื่อความแม่นยำ
> 🔴 คอลัมน์นี้ **PHP เป็นคนสร้าง** — `INSERT` ด้วย SQL ตรง ๆ ต้องรัน
> `php database/rebuild_search_index.php` ตามเสมอ ไม่งั้นค้นหาเล่มนั้นไม่เจอ
> เดิมคอขวดคือขนาดหน้าเว็บเพราะไม่มี pagination — **แก้แล้ว (F-21)** ดู KNOWN_LIMITATIONS §1.1–1.2

## 6. การเปลี่ยน Schema — เช็คลิสต์

ถ้าเพิ่ม/แก้คอลัมน์ ต้องแก้ให้ครบทุกจุดนี้ ไม่งั้นติดตั้งใหม่แล้วไม่ตรงกัน:

1. `database/schema.sql` (สำหรับ import มือ)
2. `install.php` (สำหรับติดตั้งอัตโนมัติ) ← **มักลืม**
3. ไฟล์ migration ใน `database/migrations/` สำหรับคนที่ติดตั้งไปแล้ว (รันด้วย `php database/migrate.php` — ดู DEPLOYMENT.md §5.5)
4. Repository ที่ INSERT/UPDATE คอลัมน์นั้น
5. Report / Import / Export ที่อ้างคอลัมน์
6. `docs/ARCHITECTURE.md` + `docs/ai-context/DATABASE_MAP.md` (เอกสารชุดนี้)
7. **ถ้าคอลัมน์นั้นค้นหาได้** → `BookRepository::makeSearchTokens()` + เงื่อนไข `LIKE`
   ใน `buildListQuery()` แล้วรัน `php database/rebuild_search_index.php --all` ← **มักลืม**
8. seeder ทั้ง 2 ตัวใน `tests/fixtures/` ถ้าคอลัมน์เป็น NOT NULL
