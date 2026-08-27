# คู่มือติดตั้งระบบยืมคืนหนังสือ

> เอกสารนี้เขียนสำหรับผู้ที่ไม่ใช่นักพัฒนา — ทำตามทีละขั้น ไม่ต้องเดา

---

## A. ภาพรวม

ระบบยืมคืนหนังสือเป็นเว็บแอปพลิเคชัน PHP สำหรับจัดการห้องสมุดขนาดเล็ก-กลาง  
รองรับการยืม/คืน/จอง/ค่าปรับ/รายงาน พร้อมระบบสมาชิกและเจ้าหน้าที่

**สิ่งที่ `install.php` ทำให้อัตโนมัติ:**
- สร้าง database + ตารางทั้ง 8 ตาราง (users, categories, books, borrows, reservations, payments, password_resets, settings, rate_limits)
- สร้างบัญชี Admin เริ่มต้น
- เพิ่มหมวดหมู่ตัวอย่าง 5 หมวด + หนังสือตัวอย่าง 5 เล่ม
- สร้างไฟล์ `.installed` ล็อคป้องกันติดตั้งซ้ำ

---

## B. ความต้องการระบบ (Requirements)

| รายการ | เวอร์ชันขั้นต่ำ | หมายเหตุ |
|--------|----------------|----------|
| **PHP** | 8.1+ | ใช้ `match()`, `str_starts_with()`, named arguments |
| **MySQL** | 5.7+ | หรือ MariaDB 10.3+ (CHECK constraints ทำงานจริงบน MariaDB 10.2.1+ / MySQL 8.0.16+) |
| **Web Server** | Apache 2.4+ | ต้องเปิด `mod_rewrite` (ถ้าใช้) และรองรับ `.htaccess` |
| **PHP Extensions** | pdo_mysql, session, mbstring, fileinfo, json | XAMPP มีครบทุกตัวตามค่าเริ่มต้น |

### ตรวจสอบ PHP Extensions

XAMPP มาพร้อมทุก extension ที่จำเป็น — ปกติไม่ต้องเปิดเพิ่ม  
ถ้าใช้ hosting อื่น ตรวจสอบโดยสร้างไฟล์ `phpinfo.php`:

```php
<?php phpinfo();
```

แล้วค้นหา: `pdo_mysql`, `mbstring`, `fileinfo`, `session`  
**ลบไฟล์ `phpinfo.php` ทันทีหลังตรวจเสร็จ**

---

## C. โครงสร้างไฟล์ที่เกี่ยวข้องกับการติดตั้ง

```
book_borrowing/
├── install.php              ← ตัวติดตั้งอัตโนมัติ (เปิดผ่าน browser)
├── .env.example             ← ตัวอย่างไฟล์ตั้งค่า (คัดลอกเป็น .env)
├── .env                     ← ค่าตั้งค่าจริง (สร้างเอง — ห้าม commit)
├── .htaccess                ← ป้องกันเข้าถึงไฟล์สำคัญ (.env, .sql, .md)
├── .installed               ← ล็อคไฟล์ (สร้างอัตโนมัติหลังติดตั้ง)
├── bootstrap.php            ← จุดเริ่มต้นระบบ (โหลด config → db → functions)
│
├── includes/
│   ├── config.php           ← อ่านค่าจาก .env → กำหนด PHP constants
│   ├── db.php               ← เชื่อมต่อ MySQL ผ่าน PDO (Singleton)
│   └── functions.php        ← ฟังก์ชันกลาง (session, CSRF, auth, ฯลฯ)
│
├── database/
│   ├── schema.sql           ← โครงสร้างตาราง (ใช้ import manual ได้)
│   └── sample_data.sql      ← ข้อมูลตัวอย่างเต็ม (ยืม/คืน/จอง/ค่าปรับ)
│
├── uploads/covers/          ← โฟลเดอร์เก็บรูปปกหนังสือ (ต้องเขียนได้)
├── logs/                    ← โฟลเดอร์เก็บ log (ต้องเขียนได้)
│
└── cron/
    ├── expire_reservations.php  ← ตรวจและ expire การจองหมดอายุ
    └── cleanup_tokens.php       ← ลบ password reset token หมดอายุ
```

---

## D. ติดตั้งแบบ Local (XAMPP) — Step-by-step

### ขั้นที่ 1 — ติดตั้ง XAMPP

1. ดาวน์โหลด XAMPP จาก https://www.apachefriends.org/
2. ติดตั้งตามปกติ (เลือก PHP + MySQL + Apache)
3. เปิด XAMPP Control Panel
4. กด **Start** ที่ Apache (ต้องขึ้นสีเขียว)
5. กด **Start** ที่ MySQL (ต้องขึ้นสีเขียว)

### ขั้นที่ 2 — วางไฟล์โปรเจกต์

วางโฟลเดอร์ `book_borrowing` ไว้ใน:
```
C:\xampp\htdocs\book_borrowing\
```

ตรวจสอบว่ามีไฟล์เหล่านี้:
```
C:\xampp\htdocs\book_borrowing\index.php        ← ต้องมี
C:\xampp\htdocs\book_borrowing\install.php       ← ต้องมี
C:\xampp\htdocs\book_borrowing\.env.example      ← ต้องมี
```

### ขั้นที่ 3 — สร้างไฟล์ .env

1. เข้าโฟลเดอร์ `C:\xampp\htdocs\book_borrowing\`
2. คัดลอกไฟล์ `.env.example` → เปลี่ยนชื่อเป็น `.env`
3. เปิด `.env` ด้วย Notepad แล้วตรวจค่า:

```env
# --- ค่าที่ต้องตรวจ (XAMPP ค่าเริ่มต้นใช้ได้เลย) ---
DB_HOST=localhost
DB_NAME=book_borrowing
DB_USER=root
DB_PASS=

# --- ค่าที่ต้องแก้ถ้า URL ไม่ตรง ---
APP_URL=http://localhost/book_borrowing

# --- ค่าที่ปรับได้ตามต้องการ ---
APP_NAME="ระบบยืมคืนหนังสือ"
DEFAULT_BORROW_DAYS=7
MAX_BORROW_BOOKS=3
FINE_PER_DAY=10
SESSION_LIFETIME=3600
TIMEZONE=Asia/Bangkok

# --- ห้ามเปิดบน production ---
APP_DEBUG=false
```

> **XAMPP ค่าเริ่มต้น:** DB_USER=`root`, DB_PASS=ว่าง — ใช้ได้เลยไม่ต้องแก้  
> **ถ้าเคยตั้งรหัส MySQL ไว้:** ให้กรอก `DB_PASS=รหัสที่ตั้ง`

### ขั้นที่ 4 — รันตัวติดตั้ง

1. เปิด browser
2. ไปที่ **http://localhost/book_borrowing/install.php**
3. กรอกอีเมล admin (หรือใช้ค่าเริ่มต้น `admin@library.com`)
4. กรอกรหัสผ่าน admin (ขั้นต่ำ 6 ตัวอักษร — ถ้าเว้นว่าง ระบบสร้างให้)
5. กดปุ่ม **"เริ่มติดตั้ง"**
6. ถ้าสำเร็จ จะเห็น email + password ของ admin → **จดไว้!**
7. กดเข้าหน้าแรก หรือเข้า Admin ได้เลย

### ขั้นที่ 5 — เพิ่มข้อมูลตัวอย่าง (ไม่บังคับ)

ถ้าต้องการข้อมูล demo เต็ม (มีสมาชิก, ยืม/คืน, จอง, ค่าปรับ):

**วิธีที่ 1 — phpMyAdmin**

1. เปิด http://localhost/phpmyadmin
2. **เลือก database ที่ติดตั้งไว้จากเมนูซ้าย** (ค่าเริ่มต้นคือ `book_borrowing` — ถ้าตั้ง `DB_NAME` ใน `.env` เป็นชื่ออื่นให้เลือกชื่อนั้น)
3. กดแท็บ **Import** → เลือกไฟล์ `database/sample_data.sql` → กด **Go**

**วิธีที่ 2 — Command line**

```
mysql -u root -p ชื่อฐานข้อมูล < database/sample_data.sql
```

> ⚠️ ไฟล์ `sample_data.sql` **ไม่ระบุชื่อฐานข้อมูลในตัวเอง** จึงต้องเลือก database ก่อนเสมอ
> (ออกแบบแบบนี้เพื่อให้ใช้ได้กับทุกค่า `DB_NAME` ไม่ใช่แค่ `book_borrowing`)

**รหัสผ่าน**

- ทุก account ในข้อมูลตัวอย่าง: **`123456`**
- ยกเว้น `admin@library.com` ที่ยังใช้รหัสที่ตั้งไว้ตอน `install.php` — ไฟล์ตัวอย่างจะไม่ทับรหัส admin
- ⚠️ ห้ามนำข้อมูลชุดนี้ขึ้น production

| Email | บทบาท |
|-------|--------|
| admin@library.com | ผู้ดูแลระบบ |
| staff@library.com | เจ้าหน้าที่ |
| somchai@example.com | สมาชิก |
| somying@example.com | สมาชิก |
| wichai@example.com | สมาชิก |

---

## E. ติดตั้งบน Shared Hosting — Step-by-step

### ขั้นที่ 1 — สร้าง Database

1. เข้า cPanel → **MySQL Databases**
2. สร้าง database ชื่อ `book_borrowing` (หรือชื่ออื่นตามที่ hosting กำหนด เช่น `username_book_borrowing`)
3. สร้าง user + กำหนดสิทธิ์ **All Privileges** ให้ database นั้น
4. จดชื่อ database, username, password ไว้

### ขั้นที่ 2 — อัปโหลดไฟล์

1. ใช้ FTP/SFTP (เช่น FileZilla) อัปโหลดทั้งโฟลเดอร์ไปที่ `public_html/book_borrowing/`
2. หรือ upload ผ่าน cPanel File Manager (อัปโหลด .zip แล้วแตกไฟล์)

### ขั้นที่ 3 — แก้ไข .env

แก้ค่าใน `.env` ให้ตรงกับ hosting:

```env
DB_HOST=localhost
DB_NAME=username_book_borrowing
DB_USER=username_dbuser
DB_PASS=รหัสที่ตั้งไว้

APP_URL=https://yourdomain.com/book_borrowing
APP_DEBUG=false
TIMEZONE=Asia/Bangkok
```

> **สำคัญ:** `APP_URL` ต้องตรงกับ URL จริงของเว็บไซต์ ไม่ลงท้ายด้วย `/`  
> ถ้า URL ไม่ตรง ระบบจะ redirect ผิดพลาด (ไฟล์ที่เกี่ยว: `includes/config.php` บรรทัด 70)

### ขั้นที่ 4 — ตั้ง Permission

```bash
chmod 755 uploads/
chmod 755 uploads/covers/
chmod 755 logs/
chmod 600 .env
```

### ขั้นที่ 5 — รันตัวติดตั้ง

1. เปิด browser ไปที่ `https://yourdomain.com/book_borrowing/install.php`
2. กรอกข้อมูล admin → กด "เริ่มติดตั้ง"
3. จดอีเมลและรหัสผ่านที่แสดง

### ขั้นที่ 6 — ตั้งค่า Cron Job (ดูรายละเอียดหัวข้อ H)

---

## F. Permission — โฟลเดอร์ที่ต้องเขียนได้

| โฟลเดอร์ | ทำไมต้องเขียนได้ | Permission |
|----------|-----------------|------------|
| `uploads/covers/` | เก็บรูปปกหนังสือที่ admin upload | `755` |
| `logs/` | เก็บ log ของระบบ (error, cron) | `755` |

### บน Windows (XAMPP)
ไม่ต้องตั้ง permission — XAMPP เขียนได้ทุกโฟลเดอร์อยู่แล้ว

### บน Linux/Mac/Hosting
```bash
# ตั้ง permission
chmod -R 755 uploads/
chmod -R 755 logs/

# กำหนดเจ้าของ (ใช้ user ของ web server)
chown -R www-data:www-data uploads/ logs/
# บาง hosting ใช้ nobody หรือ apache แทน www-data
```

### ไฟล์ .env ต้องอ่านได้แต่ห้ามเข้าถึงจาก web
```bash
chmod 600 .env
```

> ไฟล์ `.htaccess` ที่ root ของโปรเจกต์มีการบล็อกการเข้าถึง `.env` จาก browser อยู่แล้ว:  
> `<FilesMatch "\.(env|example|md|json|lock|log|yml|yaml|ini|bak|sql)$">` → `Require all denied`

---

## G. Post-install Checklist — ทำทันทีหลังติดตั้ง

- [ ] **ลบหรือเปลี่ยนชื่อ `install.php`** — ป้องกันคนอื่นรันติดตั้งซ้ำ
- [ ] **เปลี่ยนรหัสผ่าน Admin** — เข้าระบบ → ไอคอนผู้ใช้มุมขวาบน → เปลี่ยนรหัสผ่าน
- [ ] **ตรวจ `APP_DEBUG=false`** ในไฟล์ `.env` — เปิด debug บน production จะแสดง error ละเอียดให้ผู้ใช้เห็น
- [ ] **ตรวจ `APP_URL`** ในไฟล์ `.env` ว่าตรงกับ URL จริง
- [ ] **ทดสอบ upload รูปปก** — เข้า Admin → เพิ่มหนังสือ → ลอง upload รูป (ถ้าไม่ได้ = permission โฟลเดอร์ `uploads/covers/`)
- [ ] **ทดสอบยืม/คืน** — สร้างสมาชิก → ยืมหนังสือ → คืนหนังสือ
- [ ] **ตั้ง Cron Job** — ดูหัวข้อ H (ถ้าไม่ตั้ง การจองที่หมดอายุจะไม่ถูก expire อัตโนมัติ)
- [ ] **Backup database** — สำรองข้อมูลก่อนเปิดใช้งานจริง

---

## H. ตั้งค่า Cron Job

ระบบมี 2 งานอัตโนมัติ ต้องตั้งให้รันตามกำหนด:

### 1. Expire การจองที่หมดอายุ

**ไฟล์:** `cron/expire_reservations.php`  
**หน้าที่:** ตรวจการจองที่เลยวันหมดอายุ → เปลี่ยนสถานะเป็น `expired` + คืน stock  
**ความถี่:** ทุก 15 นาที

**Linux/Hosting (crontab):**
```bash
# เปิด crontab
crontab -e

# เพิ่มบรรทัดนี้ (แก้ path ให้ตรง)
*/15 * * * * /usr/bin/php /var/www/html/book_borrowing/cron/expire_reservations.php >> /var/www/html/book_borrowing/logs/cron.log 2>&1
```

**Windows (Task Scheduler):**
1. เปิด Task Scheduler
2. สร้าง Task ใหม่
3. Trigger: ทุก 15 นาที
4. Action: Start a program
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `C:\xampp\htdocs\book_borrowing\cron\expire_reservations.php`

### 2. ลบ Token หมดอายุ

**ไฟล์:** `cron/cleanup_tokens.php`  
**หน้าที่:** ลบ password reset token ที่หมดอายุแล้ว  
**ความถี่:** วันละ 1 ครั้ง (แนะนำตี 3)

**Linux/Hosting (crontab):**
```bash
0 3 * * * /usr/bin/php /var/www/html/book_borrowing/cron/cleanup_tokens.php >> /var/www/html/book_borrowing/logs/cron.log 2>&1
```

**Windows (Task Scheduler):**
- Trigger: ทุกวัน เวลา 03:00
- Program: `C:\xampp\php\php.exe`
- Arguments: `C:\xampp\htdocs\book_borrowing\cron\cleanup_tokens.php`

> **ถ้าไม่ตั้ง Cron:** ระบบยังใช้งานได้ แต่การจองที่หมดอายุจะไม่ถูก expire อัตโนมัติ (stock จะไม่คืน จนกว่า admin จะจัดการเอง) และ token เก่าจะสะสมใน database

---

## I. Troubleshooting — แก้ปัญหาที่พบบ่อย

### 1. หน้าขาว / ไม่มีอะไรเลย

| | |
|---|---|
| **สาเหตุ** | PHP error แต่ไม่แสดง (APP_DEBUG=false) |
| **วิธีแก้** | เปิดไฟล์ `.env` → แก้ `APP_DEBUG=true` → refresh browser |
| **ไฟล์ที่เกี่ยว** | `.env`, `bootstrap.php` (บรรทัด 100-106) |
| **หลังแก้เสร็จ** | กลับไปตั้ง `APP_DEBUG=false` เสมอ |

### 2. Access denied for user 'root'

| | |
|---|---|
| **สาเหตุ** | รหัส database ไม่ตรง |
| **วิธีแก้** | เปิด `.env` → แก้ `DB_PASS` ให้ตรงกับรหัส MySQL |
| **ไฟล์ที่เกี่ยว** | `.env`, `includes/config.php` (บรรทัด 62-65) |
| **XAMPP ค่าเริ่มต้น** | `DB_USER=root` + `DB_PASS=` (ว่าง) |

### 3. Unknown database 'book_borrowing'

| | |
|---|---|
| **สาเหตุ** | ยังไม่ได้รันตัวติดตั้ง หรือติดตั้งไม่สำเร็จ |
| **วิธีแก้** | ไปที่ `http://localhost/book_borrowing/install.php` แล้วกดติดตั้ง |
| **ถ้าขึ้น "ระบบติดตั้งแล้ว"** | ลบไฟล์ `.installed` ที่ root ของโปรเจกต์แล้วลองใหม่ |
| **ไฟล์ที่เกี่ยว** | `install.php` (บรรทัด 15-16), `.installed` |

### 4. 404 Not Found

| | |
|---|---|
| **สาเหตุ** | วางโฟลเดอร์ผิดที่ หรือ URL ไม่ตรง |
| **วิธีแก้** | ตรวจว่ามีไฟล์ `C:\xampp\htdocs\book_borrowing\index.php` |
| **ไฟล์ที่เกี่ยว** | `.env` → `APP_URL` |

### 5. Redirect วนลูป / ไปผิดหน้า

| | |
|---|---|
| **สาเหตุ** | `APP_URL` ในไฟล์ `.env` ไม่ตรงกับ URL จริง |
| **วิธีแก้** | แก้ `APP_URL` ให้ตรง เช่น `http://localhost/book_borrowing` (ไม่มี `/` ต่อท้าย) |
| **ไฟล์ที่เกี่ยว** | `.env`, `includes/config.php` (บรรทัด 70), `includes/functions.php` → `redirect()` |

### 6. Upload รูปปกไม่ได้

| | |
|---|---|
| **สาเหตุ** | โฟลเดอร์ `uploads/covers/` ไม่มี หรือเขียนไม่ได้ |
| **วิธีแก้ (Linux)** | `chmod -R 755 uploads/` + `chown -R www-data:www-data uploads/` |
| **วิธีแก้ (XAMPP)** | ตรวจว่ามีโฟลเดอร์ `uploads/covers/` — ถ้าไม่มีให้สร้าง |
| **ไฟล์ที่เกี่ยว** | `admin/book_form.php` (บรรทัด 102-133) |
| **ข้อจำกัด** | รองรับ JPEG, PNG, GIF, WEBP — ขนาดไม่เกิน 2MB |

### 7. ติดตั้งแล้วแต่ขึ้น "ระบบติดตั้งแล้ว" ทั้งที่ DB ยังไม่มี

| | |
|---|---|
| **สาเหตุ** | มีไฟล์ `.installed` ค้างอยู่ (อาจจากการติดตั้งที่ล้มเหลว) |
| **วิธีแก้** | ลบไฟล์ `.installed` ที่ root ของโปรเจกต์ → เปิด `install.php` ใหม่ |
| **ไฟล์ที่เกี่ยว** | `install.php` (บรรทัด 15-16) |

### 8. ระบบขัดข้อง กรุณาติดต่อผู้ดูแลระบบ

| | |
|---|---|
| **สาเหตุ** | เชื่อมต่อ database ไม่ได้ (APP_DEBUG=false จึงไม่แสดงรายละเอียด) |
| **วิธีแก้** | เปิด `APP_DEBUG=true` ชั่วคราว → refresh → อ่าน error ที่แสดง → แก้ตามสาเหตุ |
| **สาเหตุที่พบบ่อย** | MySQL ไม่ได้เปิด, DB_PASS ผิด, database ถูกลบ |
| **ไฟล์ที่เกี่ยว** | `includes/db.php` (บรรทัด 67-77) |

### 9. Session หมดอายุบ่อย / ถูก logout เร็ว

| | |
|---|---|
| **สาเหตุ** | `SESSION_LIFETIME` ตั้งไว้สั้น |
| **วิธีแก้** | แก้ `.env` → `SESSION_LIFETIME=7200` (2 ชั่วโมง) หรือค่าที่ต้องการ (หน่วยวินาที) |
| **ไฟล์ที่เกี่ยว** | `.env`, `includes/functions.php` → `startSession()` (บรรทัด 600-627) |

### 10. Apache ไม่ Start (Port 80 ถูกใช้)

| | |
|---|---|
| **สาเหตุ** | โปรแกรมอื่น (เช่น Skype, IIS) ใช้ port 80 อยู่ |
| **วิธีแก้** | XAMPP Control Panel → Config → Apache (httpd.conf) → เปลี่ยน `Listen 80` เป็น `Listen 8080` |
| **หลังเปลี่ยน port** | เปิดเว็บที่ `http://localhost:8080/book_borrowing/` + แก้ `APP_URL` ใน `.env` |

---

## J. Security Notes — ต้องทำก่อนใช้งานจริง

### ต้องทำ (Critical)

1. **ลบ `install.php`** หลังติดตั้งเสร็จ — ป้องกันคนอื่นสร้าง admin ใหม่
2. **ตั้ง `APP_DEBUG=false`** ในไฟล์ `.env` — debug mode แสดง error ละเอียดรวมถึง path ไฟล์
3. **เปลี่ยนรหัส Admin** เป็นรหัสที่แข็งแรง (ขั้นต่ำ 6 ตัวอักษร)
4. **ตั้งรหัส MySQL** ถ้ายังใช้ root/ไม่มีรหัส (สำคัญมากสำหรับ production)

### ควรทำ (Recommended)

5. **Backup database เป็นประจำ** — ผ่าน phpMyAdmin → Export หรือ `mysqldump`
6. **ใช้ HTTPS** บน production — แก้ `APP_URL` เป็น `https://...` ในไฟล์ `.env`
7. **ตรวจไฟล์ `.htaccess`** ว่าทำงาน — ลองเข้า `http://yourdomain.com/book_borrowing/.env` ต้องได้ 403 Forbidden

### ระบบป้องกันที่มีอยู่แล้ว (ไม่ต้องตั้งค่าเพิ่ม)

| การป้องกัน | กลไก | ไฟล์ |
|-----------|------|------|
| SQL Injection | PDO prepared statements (`EMULATE_PREPARES=false`) | `includes/db.php` |
| XSS | `htmlspecialchars()` ผ่านฟังก์ชัน `e()` | `includes/functions.php` |
| CSRF | per-session token + `hash_equals()` | `includes/functions.php` |
| Brute Force | DB-based rate limiting | `includes/functions.php` |
| Session Hijacking | HttpOnly + SameSite=Lax cookie | `includes/functions.php` |
| File Upload | whitelist MIME type + ปิด PHP execution ใน `uploads/` | `admin/book_form.php`, `uploads/.htaccess` |
| Directory Listing | `Options -Indexes` | `.htaccess` |
| Direct Access | `Require all denied` บน `app/`, `includes/`, `tests/` | `.htaccess` ในแต่ละโฟลเดอร์ |

---

## K. Quick Verification — เช็คว่าติดตั้งสำเร็จ

หลังติดตั้งเสร็จ ให้ตรวจทีละข้อ:

- [ ] เปิด `http://localhost/book_borrowing/` → เห็นหน้าแรกแสดงรายการหนังสือ
- [ ] เปิด `http://localhost/book_borrowing/login.php` → เห็นหน้า login
- [ ] Login ด้วยบัญชี admin → เข้า dashboard ได้
- [ ] เข้า Admin → จัดการหนังสือ → เห็นหนังสือตัวอย่าง
- [ ] เข้า `http://localhost/book_borrowing/.env` → ต้องได้ **403 Forbidden** (ไม่ใช่เห็นเนื้อหา)
- [ ] เข้า `http://localhost/book_borrowing/install.php` → ต้องแสดง "ระบบติดตั้งแล้ว" หรือ 404 (ถ้าลบแล้ว)

> ถ้าผ่านครบทุกข้อ → ระบบพร้อมใช้งาน
