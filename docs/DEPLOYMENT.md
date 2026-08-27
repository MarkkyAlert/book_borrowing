# คู่มือ Deploy ระบบยืมคืนหนังสือขึ้น Production

> เอกสารนี้สำหรับ "คนที่ติดตั้งสำเร็จแล้ว" และต้องการนำระบบขึ้นใช้งานจริง  
> ถ้ายังไม่ได้ติดตั้ง → อ่าน `INSTALL.md` ก่อน

---

## 1. ภาพรวม Deployment

### Deployment คืออะไร

Deployment คือการนำระบบที่ทดสอบเสร็จแล้วไปวางบน server จริง ให้ผู้ใช้จริงเข้าถึงได้

### ต่างจาก Install ยังไง

| | Install | Deploy |
|---|---|---|
| **ทำเมื่อไหร่** | ครั้งแรกที่ตั้งระบบ | หลัง install สำเร็จ + ทดสอบแล้ว |
| **เป้าหมาย** | ให้ระบบทำงานได้ | ให้ระบบ **ปลอดภัย + พร้อมใช้จริง** |
| **สิ่งที่ทำ** | สร้าง DB, ตั้งค่า .env, รัน install.php | ปิด debug, ลบไฟล์อันตราย, ตั้ง cron, backup |

### ควรใช้เอกสารนี้ตอนไหน

- ทดสอบระบบบน local (XAMPP) จนมั่นใจแล้ว
- จะย้ายขึ้น hosting / server จริง
- จะเปิดให้คนอื่นใช้งาน (ไม่ใช่แค่ตัวเองทดสอบ)

---

## 2. Pre-Deployment Checklist

ตรวจทุกข้อก่อนเอาขึ้น production:

### ระบบ

- [ ] PHP 8.1+ ติดตั้งบน server แล้ว
- [ ] MySQL 5.7+ หรือ MariaDB 10.3+ พร้อมใช้งาน
- [ ] PHP Extensions ครบ: `pdo_mysql`, `session`, `mbstring`, `fileinfo`, `json`
- [ ] Apache รองรับ `.htaccess` (`AllowOverride All`)

### ฐานข้อมูล

- [ ] รัน `install.php` สำเร็จ (หรือ import `database/schema.sql` แล้ว)
- [ ] ทดสอบ login ได้ทุก role (admin, staff, member)
- [ ] ทดสอบยืม/คืน/จอง ผ่านอย่างน้อย 1 รอบ
- [ ] **สำรอง database ก่อน deploy** (Export ผ่าน phpMyAdmin → format SQL)

### ไฟล์

- [ ] โฟลเดอร์ `uploads/covers/` มีอยู่จริง + เขียนได้
- [ ] โฟลเดอร์ `logs/` มีอยู่จริง + เขียนได้
- [ ] ไฟล์ `.env` มีอยู่ + ค่า DB ถูกต้อง
- [ ] ไม่มีไฟล์ทดสอบค้างอยู่ (`tests/check_db.php`, `phpinfo.php` ฯลฯ)

---

## 3. Production Configuration

### ไฟล์ `.env` — ตัวอย่างที่ปลอดภัยสำหรับ production

```env
# ─── Database (ต้องเปลี่ยนให้ตรงกับ server) ───
DB_HOST=localhost
DB_NAME=your_database_name
DB_USER=your_db_username
DB_PASS=strong_password_here
DB_CHARSET=utf8mb4

# ─── Application (ต้องเปลี่ยน APP_URL ให้ตรง) ───
APP_NAME="ห้องสมุดประชาชน"
APP_URL=https://yourdomain.com/book_borrowing
ADMIN_EMAIL=admin@yourdomain.com

# ─── กฎการยืม (ปรับได้ตามนโยบาย) ───
DEFAULT_BORROW_DAYS=7
MAX_BORROW_BOOKS=3
FINE_PER_DAY=10

# ─── Security (ห้ามเปลี่ยนบน production) ───
MIN_PASSWORD_LENGTH=6
RATE_LIMIT_MAX_ATTEMPTS=5
RATE_LIMIT_WINDOW_MINUTES=15
SESSION_LIFETIME=3600

# ─── Production settings ───
APP_DEBUG=false
TIMEZONE=Asia/Bangkok
```

### ตารางค่าตั้งค่า — ต้องเปลี่ยน / ปรับได้ / ห้ามแตะ

| ค่า | สถานะ | เหตุผล |
|-----|--------|--------|
| `DB_HOST` | **ต้องเปลี่ยน** | ตรงกับ server จริง |
| `DB_NAME` | **ต้องเปลี่ยน** | ชื่อ database บน server |
| `DB_USER` | **ต้องเปลี่ยน** | **ห้ามใช้ root บน production** |
| `DB_PASS` | **ต้องเปลี่ยน** | ตั้งรหัสที่แข็งแรง |
| `APP_URL` | **ต้องเปลี่ยน** | URL จริงของเว็บ — ไม่มี `/` ต่อท้าย |
| `APP_NAME` | ปรับได้ | ชื่อที่แสดงบนหน้าเว็บ |
| `ADMIN_EMAIL` | ปรับได้ | อีเมล admin |
| `DEFAULT_BORROW_DAYS` | ปรับได้ | จำนวนวันยืม default |
| `MAX_BORROW_BOOKS` | ปรับได้ | ยืมได้สูงสุดกี่เล่มต่อคน |
| `FINE_PER_DAY` | ปรับได้ | ค่าปรับต่อวัน (บาท) |
| `SESSION_LIFETIME` | ปรับได้ | อายุ session (วินาที) — ค่าเริ่มต้น 3600 = 1 ชม. |
| `APP_DEBUG` | **ห้ามเปิด** | `false` เสมอบน production |
| `DB_CHARSET` | ห้ามเปลี่ยน | `utf8mb4` รองรับภาษาไทย + emoji |
| `RATE_LIMIT_*` | ห้ามลดต่ำ | ป้องกัน brute force — ลดเกินไปจะเสี่ยง |
| `MIN_PASSWORD_LENGTH` | ห้ามลดต่ำ | ขั้นต่ำ 6 ตัวอักษร |

### `APP_URL` สำคัญมาก

ระบบใช้ `APP_URL` สร้าง redirect ทุกจุด (`includes/functions.php` → `redirect()`)  
ถ้าค่าไม่ตรง → redirect พัง, ลิงก์ผิด, login ไม่ได้

**ตรวจสอบ:**
- ตรงกับ URL จริง เช่น `https://library.example.com`
- **ไม่มี** `/` ต่อท้าย
- ถ้าใช้ HTTPS ต้องขึ้นต้นด้วย `https://`
- ถ้าอยู่ใน subdirectory ต้องใส่ path เช่น `https://example.com/book_borrowing`

---

## 4. Security Checklist

### ระดับ Critical — ต้องทำก่อนเปิดใช้งาน

- [ ] **`APP_DEBUG=false`** ในไฟล์ `.env`  
  เหตุผล: `APP_DEBUG=true` แสดง error ละเอียด + path ไฟล์ + แสดง password reset link บนหน้าจอ  
  ไฟล์: `bootstrap.php` บรรทัด 100-106, `forgot_password.php`

- [ ] **ลบ `install.php`** หรือเปลี่ยนชื่อเป็นอย่างอื่น  
  เหตุผล: ใครก็ได้ที่เข้าถึง URL นี้สามารถสร้าง admin ใหม่ได้ (ถ้าลบ `.installed`)  
  หมายเหตุ: ระบบมี `.installed` lock file ป้องกันอยู่ชั้นหนึ่ง แต่ลบ `install.php` ปลอดภัยกว่า

- [ ] **เปลี่ยนรหัส Admin** เป็นรหัสที่แข็งแรง  
  ถ้าใช้ข้อมูลตัวอย่าง (`sample_data.sql`) รหัสทุก account คือ `123456` — ต้องเปลี่ยนทั้งหมด

- [ ] **ตั้งรหัส MySQL** — ห้ามใช้ root / ไม่มีรหัส บน production  
  สร้าง DB user แยก + ให้สิทธิ์เฉพาะ database นี้

### ระดับ Important — ควรทำ

- [ ] **ตรวจ `.htaccess` ทำงาน** — ลองเข้า `https://yourdomain.com/book_borrowing/.env` ผ่าน browser  
  ต้องได้ 403 Forbidden (ไม่ใช่เห็นเนื้อหาไฟล์)  
  ถ้าเข้าถึงได้ → Apache ไม่ได้เปิด `AllowOverride All`

- [ ] **ตรวจ `uploads/.htaccess`** — ป้องกันรัน PHP ในโฟลเดอร์ upload  
  ไฟล์ `uploads/.htaccess` มี `php_flag engine off` อยู่แล้ว — ตรวจว่าไม่ถูกลบ

- [ ] **ตั้ง permission ไฟล์ `.env`**
  ```bash
  chmod 600 .env
  ```

- [ ] **ใช้ HTTPS** — แก้ `APP_URL` เป็น `https://...`  
  ระบบตั้ง session cookie เป็น `secure=true` อัตโนมัติเมื่อตรวจพบ HTTPS  
  (`includes/functions.php` → `startSession()` บรรทัด 608)

### .htaccess ที่ระบบมีอยู่แล้ว

| ไฟล์ | หน้าที่ |
|------|---------|
| `.htaccess` (root) | บล็อก `.env`, `.sql`, `.md`, `.log` + ปิด directory listing |
| `app/.htaccess` | บล็อกเข้าถึง Service/Repository ทั้งหมด |
| `includes/.htaccess` | บล็อก `.php` แต่อนุญาต `.js`, `.css` |
| `tests/.htaccess` | บล็อกทั้งโฟลเดอร์ |
| `uploads/.htaccess` | ปิด PHP engine + อนุญาตเฉพาะไฟล์รูปภาพ |

### สิ่งที่ควรลบออกจาก production

| ไฟล์/โฟลเดอร์ | เหตุผล |
|---------------|--------|
| `install.php` | ป้องกันสร้าง admin ซ้ำ |
| `tests/` | ไฟล์ทดสอบ — ไม่จำเป็นบน production (มี `.htaccess` บล็อกอยู่ แต่ลบดีกว่า) |
| `tests/check_db.php` | ไฟล์ตรวจ DB connection ที่สร้างระหว่างทดสอบ |
| `database/` | ไฟล์ SQL — ไม่จำเป็นหลังติดตั้ง (มี `.htaccess` บล็อก `.sql` อยู่) |
| `docs/` | เอกสารพัฒนา — ไม่จำเป็นบน server (มี `.htaccess` บล็อก `.md` อยู่) |
| `node_modules/` | ถ้ามี — ไม่ต้อง upload ขึ้น production |
| `*.md` | เอกสาร Markdown — ถูก `.htaccess` บล็อกอยู่แล้ว |

> **หมายเหตุ:** `.htaccess` ป้องกันการเข้าถึงไฟล์เหล่านี้ผ่าน browser อยู่แล้ว  
> การลบออกเป็น defense-in-depth — ป้องกันกรณี `.htaccess` ไม่ทำงาน

---

## 5. Deploy ขึ้น Hosting / Server จริง

### ขั้นตอนย้ายจาก Local → Server

**ขั้นที่ 1 — สำรองข้อมูล**
1. เปิด phpMyAdmin (local) → เลือก database `book_borrowing`
2. กด **Export** → Format: SQL → กด **Go**
3. บันทึกไฟล์ `.sql` ไว้

**ขั้นที่ 2 — สร้าง Database บน Server**
1. เข้า cPanel → **MySQL Databases**
2. สร้าง database (เช่น `user_book_borrowing`)
3. สร้าง DB user + กำหนด **All Privileges**
4. จดชื่อ database, user, password ไว้

**ขั้นที่ 3 — Import Database**
1. เข้า phpMyAdmin บน server
2. เลือก database ที่สร้าง → กด **Import**
3. เลือกไฟล์ `.sql` ที่ export มา → กด **Go**

**ขั้นที่ 4 — อัปโหลดไฟล์**
1. ใช้ FTP/SFTP อัปโหลดทั้งโฟลเดอร์ไปที่ `public_html/book_borrowing/`
2. **ไม่ต้อง** upload: `node_modules/`, `tests/`, `database/`, `install.php`

**ขั้นที่ 5 — แก้ไฟล์ `.env` บน Server**

```env
DB_HOST=localhost
DB_NAME=user_book_borrowing
DB_USER=user_dbuser
DB_PASS=strong_password_here
APP_URL=https://yourdomain.com/book_borrowing
APP_DEBUG=false
```

**ขั้นที่ 6 — ตั้ง Permission**

```bash
chmod -R 755 uploads/
chmod -R 755 logs/
chmod 600 .env
```

**ขั้นที่ 7 — ทดสอบ** (ดูหัวข้อ 6)

### เปลี่ยน APP_URL — จุดที่ต้องระวัง

เมื่อย้าย server URL จะเปลี่ยน — ต้องแก้ **1 ที่เท่านั้น** คือ `APP_URL` ในไฟล์ `.env`

ไม่ต้องแก้ไฟล์ PHP ใดๆ เพราะทุกจุดอ้างอิงผ่าน constant `APP_URL` จาก `includes/config.php`

**ตัวอย่าง:**
```env
# Local
APP_URL=http://localhost/book_borrowing

# Production
APP_URL=https://library.myschool.ac.th
```

---

## 5.5 อัปเกรดระบบที่ติดตั้งไปแล้ว

`install.php` ใช้ได้แค่ครั้งแรก — ระบบที่ลูกค้าใช้อยู่แล้วรันซ้ำไม่ได้ (มีล็อคกันไว้)
เวลาส่งเวอร์ชันใหม่ที่**เปลี่ยนโครงสร้างฐานข้อมูล** ให้ใช้ระบบ migration แทน

### ขั้นตอนอัปเกรด (ฝั่งลูกค้า)

```bash
# 1. สำรองฐานข้อมูลก่อนเสมอ (ดูหัวข้อ 9)
mysqldump -u USER -p DBNAME > backup_ก่อนอัปเกรด.sql

# 2. อัปโหลดไฟล์เวอร์ชันใหม่ทับของเดิม (ยกเว้น .env และ uploads/)

# 3. ดูว่ามีอะไรต้องอัปเดตบ้าง
php database/migrate.php --status

# 4. รันอัปเดต
php database/migrate.php

# 5. ตรวจว่า index ค้นหาครบ (ควรขึ้น ✅)
php database/rebuild_search_index.php --check
```

ระบบจำเองว่ารันอะไรไปแล้ว — รันซ้ำได้ไม่มีผลข้างเคียง ถ้าล้มกลางทางจะหยุดที่ไฟล์นั้น
แก้แล้วรันใหม่ ระบบจะทำต่อจากจุดที่ค้าง

> ⚠️ **ผู้ใช้ที่ค้าง login อยู่จะถูกเด้งออก 1 ครั้ง** หลังอัปเกรดเป็นเวอร์ชันนี้
> เพราะชื่อ session เปลี่ยนไป (แก้ปัญหาระบบ 2 ชุดบนโดเมนเดียวกันใช้ session ร่วมกัน)
> ให้ล็อกอินใหม่ได้เลย ข้อมูลไม่หาย

> 🔎 **ถ้าขั้นที่ 5 แจ้งว่ามีเล่มตกหล่น** ให้รัน `php database/rebuild_search_index.php`
> เกิดได้เมื่อมีคนเพิ่มหนังสือด้วย SQL ตรง ๆ หรือ restore backup จากเวอร์ชันเก่า
> หนังสือที่ตกหล่นจะยังแสดงในรายการปกติ แต่ **ค้นหาไม่เจอ**

> ถ้าเข้า command line ไม่ได้ (shared hosting บางเจ้า) ให้ export คำสั่ง SQL จากไฟล์ migration
> ไปรันใน phpMyAdmin ด้วยตนเอง แล้วเพิ่มแถวใน `schema_migrations` เองเพื่อบันทึกว่ารันแล้ว

### ขั้นตอนออกเวอร์ชันใหม่ (ฝั่งผู้พัฒนา)

เมื่อต้องเปลี่ยนโครงสร้างฐานข้อมูล ต้องแก้ **3 ที่ให้ตรงกัน**:

| ไฟล์ | เพื่ออะไร |
|------|-----------|
| `database/migrations/YYYY_MM_DD_NNNNNN_ชื่อ.php` | ให้ระบบที่ติดตั้งไปแล้วอัปเดตตาม |
| `install.php` | ให้การติดตั้งใหม่ได้โครงสร้างล่าสุดตั้งแต่แรก |
| `database/schema.sql` | ให้คนที่สร้าง DB เองด้วยมือได้โครงสร้างตรงกัน |

รูปแบบไฟล์ migration:

```php
<?php
return function (PDO $pdo): string {
    // ต้องเช็คก่อนเสมอ — migration ต้องรันซ้ำได้โดยไม่พัง
    if ($pdo->query("SHOW COLUMNS FROM `books` LIKE 'new_column'")->rowCount() > 0) {
        return 'มีคอลัมน์อยู่แล้ว — ข้าม';
    }
    $pdo->exec("ALTER TABLE `books` ADD COLUMN `new_column` INT NOT NULL DEFAULT 0");
    return 'เพิ่มคอลัมน์ new_column แล้ว';
};
```

⚠️ MySQL ไม่รองรับ DDL ใน transaction (`ALTER TABLE` จะ commit ทันที) — ถ้า migration
ต้องทำหลายขั้น ให้เขียนแยกเป็นขั้นที่เช็คสถานะได้ทีละขั้น ไม่ใช่หวังว่าจะ rollback ได้

### ล็อคติดตั้งซ้ำ

ระบบล็อค 2 ชั้น: ไฟล์ `.installed` และแถว `installed_at` ในตาราง `settings`

ชั้น DB มีไว้เพราะบาง server รัน PHP คนละ user กับเจ้าของไฟล์ ทำให้เขียน `.installed` ไม่ได้
ถ้าเจอกรณีนั้น ตัวติดตั้งจะเตือนไว้ท้ายผลการติดตั้ง — **ยังควรลบ `install.php` ทิ้งอยู่ดี**

---

## 6. Post-Deployment Verification

### Checklist ทดสอบหลัง Deploy

ทำทุกข้อ — ถ้าข้อไหนไม่ผ่าน อย่าเปิดให้ใช้งาน:

**หน้า Public:**
- [ ] เปิดหน้าแรก → เห็นรายการหนังสือ
- [ ] คลิกดูรายละเอียดหนังสือ → เห็นข้อมูล + รูปปก (ถ้ามี)
- [ ] สมัครสมาชิกใหม่ → ได้รับ flash message สำเร็จ
- [ ] Login ด้วย member → เข้าสู่ระบบได้

**หน้า Admin:**
- [ ] Login ด้วย admin → เข้า dashboard ได้
- [ ] เพิ่มหนังสือ + upload รูปปก → รูปแสดงถูกต้อง
- [ ] เพิ่มสมาชิก → บันทึกได้
- [ ] ยืมหนังสือ → stock ลด
- [ ] คืนหนังสือ → stock เพิ่ม + คำนวณค่าปรับถูก
- [ ] ดูรายงาน → แสดงข้อมูลได้

**การจองหนังสือ:**
- [ ] Login ด้วย member → จองหนังสือ → สถานะเป็น pending
- [ ] ยกเลิกการจอง → stock คืน

**ความปลอดภัย:**
- [ ] เข้า `APP_URL/.env` → ได้ 403 (ไม่เห็นเนื้อหา)
- [ ] เข้า `APP_URL/app/Services/` → ได้ 403
- [ ] เข้า `APP_URL/install.php` → ได้ 404 หรือ "ติดตั้งแล้ว"
- [ ] Logout → กดปุ่ม Back ของ browser → ไม่เห็นหน้า admin

### สัญญาณเตือนว่าระบบผิดปกติ

| อาการ | สาเหตุที่เป็นไปได้ | ตรวจอะไร |
|-------|-------------------|----------|
| หน้าขาว | `APP_DEBUG=false` + PHP error | เปิด debug ชั่วคราว (ดูหัวข้อ 8) |
| Redirect วนลูป | `APP_URL` ไม่ตรง | ตรวจ `.env` → `APP_URL` |
| รูปปกไม่แสดง | path `uploads/` ผิด หรือ permission | ตรวจ `uploads/covers/` มีไฟล์ + เขียนได้ |
| Login แล้ว session หาย | `SESSION_LIFETIME` สั้นเกินไป | ตรวจ `.env` |
| ค่าปรับคำนวณผิด | `FINE_PER_DAY` ไม่ตรง หรือ timezone ผิด | ตรวจ `.env` → `FINE_PER_DAY`, `TIMEZONE` |
| Stock ติดลบ | Cron ไม่ทำงาน + การจองไม่ถูก expire | ตั้ง cron (ดูหัวข้อ 7) |
| "ระบบขัดข้อง กรุณาติดต่อผู้ดูแล" | DB connection fail | ตรวจ DB_HOST/USER/PASS ใน `.env` |

---

## 7. Cron Jobs

ระบบมี 2 งานอัตโนมัติที่ต้องตั้ง Cron:

### งานที่ 1: Expire การจองหมดอายุ

| | |
|---|---|
| **ไฟล์** | `cron/expire_reservations.php` |
| **หน้าที่** | ตรวจการจองที่เลยวันหมดอายุ → เปลี่ยนสถานะเป็น `expired` + คืน stock |
| **ความถี่** | ทุก 15 นาที |
| **ถ้าไม่ตั้ง** | การจองที่หมดอายุจะค้างเป็น `pending` ตลอด → stock ไม่คืน → หนังสือหายจากระบบ |

**Linux (crontab):**
```bash
*/15 * * * * /usr/bin/php /path/to/book_borrowing/cron/expire_reservations.php >> /path/to/book_borrowing/logs/cron.log 2>&1
```

**Shared Hosting (cPanel):**
1. เข้า cPanel → **Cron Jobs**
2. ตั้ง: Every 15 minutes
3. Command: `/usr/local/bin/php /home/username/public_html/book_borrowing/cron/expire_reservations.php`

### งานที่ 2: ลบ Token หมดอายุ

| | |
|---|---|
| **ไฟล์** | `cron/cleanup_tokens.php` |
| **หน้าที่** | ลบ password reset token ที่หมดอายุ (ทำความสะอาด table `password_resets`) |
| **ความถี่** | วันละ 1 ครั้ง (แนะนำตี 3) |
| **ถ้าไม่ตั้ง** | Token เก่าจะสะสมใน database — ไม่กระทบการใช้งาน แต่ table จะใหญ่ขึ้นเรื่อยๆ |

**Linux (crontab):**
```bash
0 3 * * * /usr/bin/php /path/to/book_borrowing/cron/cleanup_tokens.php >> /path/to/book_borrowing/logs/cron.log 2>&1
```

### ตรวจว่า Cron ทำงาน

ดู log ที่ `logs/cron.log`:
```
[2026-02-14 00:15:00] expire_reservations: 2 expired
[2026-02-14 03:00:00] cleanup_tokens: 5 deleted
```

ถ้าไม่มี log → cron ยังไม่ทำงาน

### หมายเหตุ: rate_limits cleanup

ตาราง `rate_limits` มีระบบ cleanup อัตโนมัติอยู่ใน `bootstrap.php` — ลบ record เก่ากว่า 1 วันแบบ probabilistic (~1% ของ request)  
ไม่ต้องตั้ง cron เพิ่ม

---

## 8. Logs & Debugging

### ดู Error จากตรงไหน

| แหล่งข้อมูล | ดูอย่างไร | เมื่อไหร่ |
|-------------|----------|----------|
| **PHP Error Log** | ไฟล์ `php_error.log` ของ Apache หรือ `error_log` ใน cPanel | ระบบพัง ไม่แสดงอะไร |
| **Cron Log** | `logs/cron.log` | ตรวจว่า cron ทำงาน |
| **Database** | phpMyAdmin → ดูข้อมูลในตาราง | ตรวจ stock, สถานะ borrow/reservation |
| **Browser Console** | กด F12 → Console tab | ปัญหา JavaScript / modal / AJAX |

### เปิด Debug ชั่วคราวอย่างปลอดภัย

เมื่อต้องดู error ละเอียดบน production:

1. แก้ `.env`:
   ```env
   APP_DEBUG=true
   ```
2. Refresh หน้าที่มีปัญหา → อ่าน error message
3. **แก้เสร็จ → กลับไปตั้ง `APP_DEBUG=false` ทันที**

> เมื่อ `APP_DEBUG=true`:
> - แสดง error ละเอียดรวม path ไฟล์ (`bootstrap.php` บรรทัด 100-106)
> - แสดง DB connection error ละเอียด (`includes/db.php` บรรทัด 70-71)
> - แสดง password reset link บนหน้าจอ (`forgot_password.php`)
> 
> **ทั้งหมดนี้เป็นความเสี่ยงด้านความปลอดภัย — ห้ามเปิดทิ้งไว้**

### สิ่งที่ไม่ควรเปิดบน Production

| สิ่งที่ | เหตุผล |
|---------|--------|
| `APP_DEBUG=true` | แสดง path, credentials, reset link |
| `display_errors = On` ใน php.ini | แสดง PHP error ทั้งหมดให้ผู้ใช้ |
| phpMyAdmin เปิดสาธารณะ | ใครก็เข้าจัดการ DB ได้ |
| phpinfo() | แสดง config ทั้งหมดของ server |

---

## 9. Backup & Recovery

### ควร Backup อะไร

| สิ่งที่ต้อง backup | ความสำคัญ | ตำแหน่ง |
|-------------------|----------|---------|
| **Database** | สูงมาก | Export ผ่าน phpMyAdmin / `mysqldump` |
| **ไฟล์ `.env`** | สูง | root ของโปรเจกต์ |
| **โฟลเดอร์ `uploads/`** | สูง | รูปปกหนังสือที่ upload |
| **โค้ดทั้งหมด** | ปานกลาง | เก็บ zip ของโปรเจกต์ทั้งหมดไว้ |

### ความถี่แนะนำ

| ขนาดห้องสมุด | Database | Uploads |
|-------------|----------|---------|
| เล็ก (< 100 เล่ม) | สัปดาห์ละครั้ง | เมื่อเพิ่มหนังสือใหม่ |
| กลาง (100-1,000 เล่ม) | วันละครั้ง | สัปดาห์ละครั้ง |
| มีการใช้งานหนัก | วันละครั้ง + ก่อนทำการเปลี่ยนแปลง | วันละครั้ง |

### วิธี Backup Database

**ผ่าน phpMyAdmin:**
1. เข้า phpMyAdmin → เลือก database
2. กด **Export** → Quick → Format: SQL → กด **Go**
3. เก็บไฟล์ `.sql` ไว้ที่ปลอดภัย

**ผ่าน command line:**
```bash
mysqldump -u root -p book_borrowing > backup_$(date +%Y%m%d).sql
```

### ถ้าระบบพัง — ทำอะไรก่อน

**ลำดับที่ 1: ตรวจว่าพังแบบไหน**
- หน้าขาว → เปิด `APP_DEBUG=true` ชั่วคราว → อ่าน error
- "ระบบขัดข้อง" → ตรวจ DB connection (DB_HOST/USER/PASS ใน `.env`)
- 403/404 → ตรวจ `.htaccess` + ตำแหน่งไฟล์

**ลำดับที่ 2: แก้ตามสาเหตุ**
- DB ถูกลบ → import backup `.sql` ผ่าน phpMyAdmin
- ไฟล์หาย → upload จาก backup
- `.env` หาย → สร้างใหม่จาก `.env.example` + ใส่ค่าที่ถูกต้อง

**ลำดับที่ 3: ทดสอบ**
- Login ได้ทุก role
- ยืม/คืนทำงาน
- Stock ถูกต้อง

---

## 10. ข้อจำกัดของระบบ

### ขอบเขตการใช้งาน

ระบบนี้ออกแบบสำหรับ:
- ห้องสมุดขนาดเล็ก-กลาง (หนังสือหลักร้อย ถึงหลักพัน)
- ผู้ใช้งานพร้อมกันไม่เกิน 20-50 คน
- Server เดียว (single machine)
- เป็น template / demo / ระบบสำหรับองค์กรเดียว

### ไม่เหมาะกับ

| กรณี | เหตุผล |
|------|--------|
| ห้องสมุดขนาดใหญ่ (หมื่นเล่ม+) | ค้นหาใช้ `LIKE '%คำ%'` ซึ่งใช้ index ไม่ได้ ยังไม่มี FULLTEXT index (แบ่งหน้าแล้ว — วัดจริงถึง 2,000 เล่มยังสบาย) |
| ผู้ใช้พร้อมกันมาก (100+) | session เก็บบน file system, ไม่มี caching layer |
| หลายสาขา | ออกแบบสำหรับห้องสมุดเดียว ไม่มีระบบ multi-branch |
| Mobile app / Third-party integration | มี API เบื้องต้น แต่ไม่ครบ (ไม่มี token-based auth) |
| ระบบ email แจ้งเตือน | ไม่มีระบบส่ง email ในตัว (password reset แสดงลิงก์บนหน้าจอเมื่อ debug=true) |

### คำเตือนสำหรับการใช้งานจริง

- **Password Reset:** ระบบไม่ส่ง email จริง — สร้าง token แล้วแสดงบนหน้าจอ (เฉพาะเมื่อ `APP_DEBUG=true`) สำหรับ production ต้องมีคนช่วย reset ผ่าน admin หรือ DB โดยตรง
- **Session:** เก็บบน file system ของ server — ถ้า restart Apache อาจทำให้ session หมดอายุ
- **Concurrency:** ระบบมี row-level locking (`SELECT FOR UPDATE`) สำหรับยืม/จอง แต่ถ้ามี traffic สูงมากอาจเจอ deadlock
- **Backup:** ระบบไม่มี auto-backup — ต้องตั้ง cron หรือทำ manual ตามหัวข้อ 9
- **File Upload:** รองรับเฉพาะรูปปกหนังสือ (JPEG, PNG, GIF, WEBP ไม่เกิน 2MB)
- **PDF Export:** ใช้ `window.print()` ของ browser — ไม่ได้สร้าง PDF จริง ผลลัพธ์ขึ้นกับ browser + เครื่องพิมพ์

### สิ่งที่ระบบทำได้ดี

เพื่อความเป็นธรรม — ระบบมีจุดแข็งเหล่านี้:
- **ป้องกัน SQL Injection** ทุกจุด (PDO prepared statements, `EMULATE_PREPARES=false`)
- **ป้องกัน XSS** ผ่าน `e()` helper ทุกที่ที่แสดงข้อมูล
- **ป้องกัน CSRF** ด้วย per-session token + `hash_equals()`
- **ป้องกัน Brute Force** ด้วย DB-based rate limiting
- **ป้องกัน Session Fixation** ด้วย `session_regenerate_id()` หลัง login
- **ป้องกัน Stock Leak** ด้วย DB constraints (CHECK, FK RESTRICT) + row-level locking
- **ป้องกัน Double Payment** ด้วย UNIQUE constraint บน `payments.borrow_id`
